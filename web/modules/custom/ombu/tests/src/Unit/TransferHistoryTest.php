<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\TransferHistory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the transfer detail against a response shaped as Alchemy's.
 *
 * @coversDefaultClass \Drupal\ombu\TransferHistory
 * @group ombu
 */
class TransferHistoryTest extends UnitTestCase {

  private const BASE_USDC = '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913';
  private const PAY_TO = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';

  /**
   * One alchemy_getAssetTransfers transfer, as the API answers it.
   *
   * The amount lives in rawContract.value (hex atomic units); `value` is a
   * decimal float in token units.
   */
  private function transfer(string $hex, float $value, ?string $timestamp): array {
    return [
      'blockNum' => '0x1',
      'uniqueId' => bin2hex(random_bytes(8)) . ':log:1',
      'hash' => '0x' . str_repeat('ab', 32),
      'from' => '0x0000000000000000000000000000000000000001',
      'to' => strtolower(self::PAY_TO),
      'value' => $value,
      'erc721TokenId' => NULL,
      'erc1155Metadata' => NULL,
      'tokenId' => NULL,
      'asset' => 'USDC',
      'category' => 'erc20',
      'rawContract' => ['value' => $hex, 'address' => self::BASE_USDC, 'decimal' => '0x6'],
    ] + ($timestamp === NULL ? [] : ['metadata' => ['blockTimestamp' => $timestamp]]);
  }

  /**
   * Runs the closure over one answer and returns it with the request sent.
   */
  private function ask(array $transfers): array {
    $sent = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['transfers' => $transfers],
      ])),
    ]));
    $stack->push(Middleware::history($sent));
    $answer = TransferHistory::closure(new Client(['handler' => $stack]), 'k')('eip155:8453', self::BASE_USDC, self::PAY_TO);
    return [$answer, json_decode((string) $sent[0]['request']->getBody(), TRUE)['params'][0]];
  }

  /**
   * The largest amount and the newest day, read from rawContract and metadata.
   *
   * @covers ::closure
   */
  public function testLargestAmountAndNewestDay(): void {
    [$answer, $params] = $this->ask([
      // 0.005 USDC, the newest.
      $this->transfer('0x1388', 0.005, '2026-11-15T10:00:00.000Z'),
      // 0.5 USDC, the largest; one smaller transfer does not hide it.
      $this->transfer('0x7a120', 0.5, '2026-10-01T10:00:00.000Z'),
      // 0.0001 USDC, no metadata: its amount counts, it is not fresh.
      $this->transfer('0x64', 0.0001, NULL),
    ]);
    $this->assertSame(['count' => 3, 'maxAmount' => 500000, 'newest' => strtotime('2026-11-15T10:00:00Z')], $answer);
    $this->assertSame('desc', $params['order'], 'Newest first: a wallet with over 100 sales still shows its latest one.');
    $this->assertTrue($params['withMetadata']);
  }

  /**
   * No transfers: a count of 0 and nothing to filter on.
   *
   * @covers ::closure
   */
  public function testNoTransfers(): void {
    [$answer] = $this->ask([]);
    $this->assertSame(['count' => 0, 'maxAmount' => NULL, 'newest' => NULL], $answer);
  }

  /**
   * A network without an index is not asked.
   *
   * @covers ::closure
   */
  public function testUnknownNetworkThrows(): void {
    $this->expectException(\RuntimeException::class);
    TransferHistory::closure(new Client(), 'k')('eip155:999999', self::BASE_USDC, self::PAY_TO);
  }

}
