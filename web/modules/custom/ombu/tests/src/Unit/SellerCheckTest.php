<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\SellerCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The market's hire / reject / ask a human answer.
 */
#[Group('ombu')]
final class SellerCheckTest extends UnitTestCase {

  /**
   * Facts for a listing, with what a test changes.
   *
   * @param array<string, mixed> $over
   *   Facts to replace.
   *
   * @return array<string, mixed>
   *   The facts.
   */
  private static function facts(array $over = []): array {
    return $over + [
      'id' => 7,
      'title' => 'Fact-check a claim',
      'source' => 'ours',
      'retired' => FALSE,
      'verified' => TRUE,
      'price' => 0.02,
      'rating' => ['average' => 4.6, 'count' => 12],
    ];
  }

  /**
   * Tests the verdict for each situation.
   *
   * @param string $expected
   *   The verdict.
   * @param array<string, mixed> $facts
   *   What changes in the facts.
   * @param float|null $budget
   *   The budget.
   */
  #[DataProvider('verdicts')]
  public function testVerdicts(string $expected, array $facts, ?float $budget): void {
    $this->assertSame($expected, SellerCheck::decide(self::facts($facts), $budget)['verdict']);
  }

  /**
   * Cases: facts that change, budget.
   *
   * @return array<string, array<mixed>>
   *   Named cases.
   */
  public static function verdicts(): array {
    return [
      'good rating, enough reviews, in budget' => ['hire', [], 0.05],
      'good rating and no budget said' => ['hire', [], NULL],
      'exactly the hire threshold' => ['hire', ['rating' => ['average' => 4.0, 'count' => 3]], NULL],
      'over budget' => ['reject', [], 0.01],
      'exactly the budget' => ['hire', [], 0.02],
      'retired' => ['reject', ['retired' => TRUE], 0.05],
      'bad rating, enough reviews' => ['reject', ['rating' => ['average' => 2.4, 'count' => 8]], NULL],
      'middling rating' => ['ask_human', ['rating' => ['average' => 3.5, 'count' => 8]], NULL],
      'no reviews' => ['ask_human', ['rating' => NULL], 0.05],
      'good rating but too few reviews' => ['ask_human', ['rating' => ['average' => 5.0, 'count' => 2]], 0.05],
      'bad rating, too few reviews: not a rejection' => [
        'ask_human',
        ['rating' => ['average' => 1.0, 'count' => 2]],
        NULL,
      ],
      'price unknown' => ['hire', ['price' => NULL], 0.05],
    ];
  }

  /**
   * Tests that every reason is a fact the buyer can check.
   */
  public function testReasons(): void {
    $result = SellerCheck::decide(self::facts(['source' => 'third_party']), 0.05);
    $kinds = array_column($result['reasons'], 'kind');
    $this->assertSame(['price', 'rating', 'verified', 'source'], $kinds);
    $text = implode(' ', array_column($result['reasons'], 'text'));
    $this->assertStringContainsString('0.02 USDC', $text);
    $this->assertStringContainsString('within your budget', $text);
    $this->assertStringContainsString('Rated 4.6 out of 5 by 12 wallets that paid', $text);
    $this->assertStringContainsString('third party', $text);
    $this->assertSame('Hire', $result['verdictLabel']);
  }

  /**
   * Tests the wording when it is over budget, retired or barely reviewed.
   */
  public function testReasonWording(): void {
    $over = SellerCheck::decide(self::facts(), 0.01);
    $this->assertSame('It costs 0.02 USDC a call, over your 0.01 USDC budget.', $over['reasons'][0]['text']);
    $retired = SellerCheck::decide(self::facts(['retired' => TRUE]), NULL);
    $this->assertSame('retired', $retired['reasons'][0]['kind']);
    $few = SellerCheck::decide(self::facts(['rating' => ['average' => 5.0, 'count' => 1]]), NULL);
    $this->assertStringContainsString('Only 1 verified review: at least 3 are needed', implode(' ', array_column($few['reasons'], 'text')));
    $none = SellerCheck::decide(self::facts(['rating' => NULL]), NULL);
    $this->assertStringContainsString('No verified reviews yet', implode(' ', array_column($none['reasons'], 'text')));
    $this->assertSame('Ask a human', $none['verdictLabel']);
  }

}
