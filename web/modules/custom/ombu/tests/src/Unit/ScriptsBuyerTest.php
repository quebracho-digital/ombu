<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\Scripts\Buyer;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;

/**
 * The buyer of the Drush scripts pays with the library's PayingHttpClient.
 *
 * @group ombu
 */
class ScriptsBuyerTest extends UnitTestCase {

  private const NETWORK = 'eip155:84532';

  private const KEY = '0x' . '11' . '22334455667788990011223344556677889900112233445566778899001122';

  /**
   * The requests the seller got.
   *
   * @var array
   */
  private array $sent = [];

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../../../../../scripts/lib/buyer.php';
    putenv('BUYER_PRIVATE_KEY=' . self::KEY . "\n");
    putenv('BUYER_MAX_USDC');
  }

  /**
   * A buyer whose seller answers $responses; $usd is what it asks for.
   */
  private function buyer(array $responses, ?string $max = NULL): Buyer {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->sent));
    return new Buyer(new Client(['handler' => $stack, 'http_errors' => FALSE]), $max);
  }

  /**
   * A 402 asking $usd on Base Sepolia.
   */
  private function required(string $usd = '0.01'): Response {
    $req = Networks::requirements(self::NETWORK, $usd, (new Signer('0x' . str_repeat('ab', 32)))->address());
    return new Response(402, [PaymentHeaders::REQUIRED => PaymentHeaders::encode([
      'x402Version' => 2,
      'resource' => ['url' => 'https://seller.example/x'],
      'accepts' => [$req],
    ]),
    ], '{}');
  }

  /**
   * The paid answer, with its receipt.
   */
  private function paid(bool $success = TRUE): Response {
    return new Response(200, [PaymentHeaders::RESPONSE => PaymentHeaders::encode([
      'success' => $success,
      'transaction' => '0x' . str_repeat('cd', 32),
      'network' => self::NETWORK,
    ] + ($success ? [] : ['errorReason' => 'insufficient_funds'])),
    ], '{"ok":true}');
  }

  /**
   * Pays the 402 once and says what it paid.
   */
  public function testItPaysThe402AndSaysWhatItPaid(): void {
    $paid = $this->buyer([$this->required(), $this->paid()])->pay('https://seller.example/x', ['a' => 1]);
    $this->assertSame(200, $paid['status']);
    $this->assertSame('{"ok":true}', $paid['body']);
    $this->assertSame('0x' . str_repeat('cd', 32), $paid['tx']);
    $this->assertSame('10000', $paid['amount']);
    $this->assertSame(self::NETWORK, $paid['network']);
    $this->assertCount(2, $this->sent);
    $this->assertFalse($this->sent[0]['request']->hasHeader(PaymentHeaders::SIGNATURE));
    $this->assertTrue($this->sent[1]['request']->hasHeader(PaymentHeaders::SIGNATURE));
    $this->assertSame('{"a":1}', (string) $this->sent[1]['request']->getBody(), 'The same call, paid.');
  }

  /**
   * What the caller refuses is never signed.
   */
  public function testWhatTheCallerRefusesIsNeverSigned(): void {
    $buyer = $this->buyer([$this->required(), $this->paid()]);
    try {
      $buyer->pay('https://seller.example/x', [], function (array $req): void {
        throw new \RuntimeException('Over budget: ' . $req['amount']);
      });
      $this->fail('It paid.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Over budget: 10000', $e->getMessage());
    }
    $this->assertCount(1, $this->sent, 'Only the unpaid call left.');
  }

  /**
   * Over the cap of the run, nothing is signed.
   */
  public function testOverTheCapNothingIsSigned(): void {
    $buyer = $this->buyer([$this->required('0.02'), $this->paid(), $this->required('0.02'), $this->paid()], '0.03');
    $this->assertNotNull($buyer->pay('https://seller.example/x', [])['tx']);
    try {
      $buyer->pay('https://seller.example/x', []);
      $this->fail('It paid over its cap.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Not paid', $e->getMessage());
    }
    $this->assertCount(3, $this->sent, 'The second 402 was not paid.');
  }

  /**
   * A seller that does not charge, or does not settle, is said as it is.
   */
  public function testAnAnswerThatIsNotA402AndASettleThatFails(): void {
    try {
      $this->buyer([new Response(200, [], 'free')])->pay('https://seller.example/x', []);
      $this->fail('No 402, no purchase.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Expected 402, got 200', $e->getMessage());
    }
    $unsettled = $this->buyer([$this->required(), $this->paid(FALSE)])->pay('https://seller.example/x', []);
    $this->assertNull($unsettled['tx'], 'Not settled: not charged.');
    $this->assertSame('10000', $unsettled['amount']);
  }

}
