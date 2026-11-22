<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Should a buyer hire a listing? The market's own answer, from what it knows.
 *
 * Same three verdicts as the buyer agent (scripts/agent.php): hire, reject,
 * ask a human. It reads only what Ombú already holds, so it costs no model:
 * the price against the budget, whether the listing is retired, and the
 * rating of its verified reviews (Rating). Few reviews are never enough to
 * hire; the answer then is to ask a human, with the reason.
 */
final class SellerCheck {

  /**
   * Verified reviews needed before the average counts.
   */
  public const MIN_REVIEWS = 3;

  /**
   * Average at or above which, with enough reviews, a buyer hires.
   */
  public const HIRE_AT = 4.0;

  /**
   * Average below which, with enough reviews, a buyer rejects.
   */
  public const REJECT_BELOW = 3.0;

  /**
   * What the market knows about a listing.
   *
   * @return array{id: int, title: string, source: string, retired: bool, verified: bool, price: float|null, rating: array{average: float, count: int}|null}
   *   The facts.
   */
  public static function facts(ProductInterface $listing): array {
    $amount = $listing->hasField('field_x402_price') ? $listing->get('field_x402_price')->amount : NULL;
    return [
      'id' => (int) $listing->id(),
      'title' => (string) $listing->label(),
      'source' => $listing->hasField('field_source') ? (string) $listing->get('field_source')->value : '',
      'retired' => $listing->hasField('field_retired') && (bool) $listing->get('field_retired')->value,
      'verified' => $listing->hasField('field_verified') && (bool) $listing->get('field_verified')->value,
      'price' => is_numeric($amount) ? (float) $amount : NULL,
      'rating' => Rating::of($listing),
    ];
  }

  /**
   * Decides on a listing's facts and an optional budget in USDC.
   *
   * @param array<string, mixed> $facts
   *   What facts() returns.
   * @param float|null $budget
   *   The most the buyer will pay for one call, if they said.
   *
   * @return array{verdict: string, verdictLabel: string, reasons: array<int, array{kind: string, text: string}>}
   *   The verdict and its reasons, each one a fact the buyer can check.
   */
  public static function decide(array $facts, ?float $budget): array {
    $reasons = [];
    $reject = FALSE;
    $rating = $facts['rating'] ?? NULL;
    $count = (int) ($rating['count'] ?? 0);
    $average = (float) ($rating['average'] ?? 0);

    if ($facts['retired'] ?? FALSE) {
      $reject = TRUE;
      $reasons[] = self::reason('retired', 'The seller retired this listing: it is not for sale.');
    }
    $price = $facts['price'] ?? NULL;
    if ($price !== NULL && $budget !== NULL && $price > $budget) {
      $reject = TRUE;
      $reasons[] = self::reason('price', sprintf(
        'It costs %s USDC a call, over your %s USDC budget.',
        self::number($price),
        self::number($budget),
      ));
    }
    elseif ($price !== NULL) {
      $within = $budget === NULL ? '' : ', within your budget';
      $reasons[] = self::reason('price', sprintf('It costs %s USDC a call%s.', self::number($price), $within));
    }

    if ($count === 0) {
      $reasons[] = self::reason('rating', 'No verified reviews yet: nobody who paid has rated it.');
    }
    elseif ($count < self::MIN_REVIEWS) {
      $noun = $count === 1 ? 'review' : 'reviews';
      $reasons[] = self::reason('rating', sprintf(
        'Only %d verified %s: at least %d are needed to trust the average.',
        $count,
        $noun,
        self::MIN_REVIEWS,
      ));
    }
    else {
      $reasons[] = self::reason('rating', sprintf('Rated %s out of 5 by %d wallets that paid.', self::number($average), $count));
      $reject = $reject || $average < self::REJECT_BELOW;
    }

    if ($facts['verified'] ?? FALSE) {
      $reasons[] = self::reason('verified', 'Vetted by the market.');
    }
    if (($facts['source'] ?? '') !== 'ours') {
      $reasons[] = self::reason('source', 'Offered by a third party: Ombú is not its seller and does not answer for it.');
    }

    $verdict = match (TRUE) {
      $reject => 'reject',
      $count >= self::MIN_REVIEWS && $average >= self::HIRE_AT => 'hire',
      default => 'ask_human',
    };
    return [
      'verdict' => $verdict,
      'verdictLabel' => ['hire' => 'Hire', 'reject' => 'Do not hire', 'ask_human' => 'Ask a human'][$verdict],
      'reasons' => $reasons,
    ];
  }

  /**
   * One reason: what kind of fact, and the sentence for the buyer.
   *
   * @return array{kind: string, text: string}
   *   The reason.
   */
  private static function reason(string $kind, string $text): array {
    return ['kind' => $kind, 'text' => $text];
  }

  /**
   * A number without trailing zeros: 0.02, 4.8, 1.
   */
  private static function number(float $value): string {
    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
  }

}
