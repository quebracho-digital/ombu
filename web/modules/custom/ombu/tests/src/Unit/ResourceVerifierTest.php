<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\ResourceVerifier;
use Drupal\ombu\SellerProbe;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;
use Quebracho\X402\TokenBlacklist;

/**
 * @coversDefaultClass \Drupal\ombu\ResourceVerifier
 * @group ombu
 */
class ResourceVerifierTest extends UnitTestCase {

  private const BASE_USDC = '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913';
  private const PAY_TO = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';
  private const TRUE = '0x0000000000000000000000000000000000000000000000000000000000000001';
  private const FALSE = '0x0000000000000000000000000000000000000000000000000000000000000000';

  protected function setUp(): void {
    parent::setUp();
    Networks::add(8453, self::BASE_USDC, 'USD Coin', '2', 6, 'Base', 'https://base.invalid');
  }

  protected function tearDown(): void {
    Networks::reset();
    parent::tearDown();
  }

  private function req(array $override = []): array {
    return $override + ['scheme' => 'exact', 'network' => 'eip155:8453', 'asset' => self::BASE_USDC, 'payTo' => self::PAY_TO, 'amount' => '5000'];
  }

  /**
   * A verifier whose probe answers $accepts (or throws $error), whose Base
   * node answers $freeze and whose Ethereum node answers $ofac (NULL: down).
   */
  private function verifier(array $accepts, ?string $freeze, ?string $ofac, ?\RuntimeException $error = NULL, ?SellerProbe $probe = NULL): ResourceVerifier {
    if (!$probe) {
      $probe = $this->createMock(SellerProbe::class);
      $error
        ? $probe->method('requirements')->willThrowException($error)
        : $probe->method('requirements')->willReturn(['https://seller.example/x', $accepts]);
    }
    $factory = new HttpFactory();
    $node = fn(?string $answer) => new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 4,
      $answer === NULL ? new Response(500, [], 'down') : new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $answer]))
    )))]);
    $blacklist = new TokenBlacklist($node($freeze), $factory, $factory, FALSE, ['eip155:8453|' . self::BASE_USDC => 'isBlacklisted']);
    $ethereum = new Rpc($node($ofac), $factory, $factory, 'https://eth.invalid');
    return new ResourceVerifier($probe, $blacklist, $ethereum, fn() => 3, ['eip155:8453|' . self::BASE_USDC => 'isBlacklisted']);
  }

  /**
   * @covers ::verify
   */
  public function testAnnouncedPostIsProbedWithGetFirst(): void {
    $methods = [];
    $probe = $this->createMock(SellerProbe::class);
    $probe->method('requirements')->willReturnCallback(function (string $url, string $method) use (&$methods): array {
      $methods[] = $method;
      if ($method === 'GET') {
        throw new \RuntimeException('The endpoint answered 405, not 402 Payment Required.');
      }
      return [$url, []];
    });
    $verifier = $this->verifier([], self::FALSE, self::FALSE, NULL, $probe);
    $verifier->verify('https://seller.example/x', 'POST');
    $this->assertSame(['GET', 'POST'], $methods, 'POST only after the GET answered 404/405');
  }

  /**
   * @covers ::verify
   */
  public function testPostIsNeverSentWhenGetFailsForAnotherReason(): void {
    $methods = [];
    $probe = $this->createMock(SellerProbe::class);
    $probe->method('requirements')->willReturnCallback(function (string $url, string $method) use (&$methods): array {
      $methods[] = $method;
      throw new \RuntimeException('The endpoint answered 200, not 402 Payment Required.');
    });
    $verifier = $this->verifier([], self::FALSE, self::FALSE, NULL, $probe);
    $report = $verifier->verify('https://seller.example/x', 'POST');
    $this->assertSame(['GET'], $methods);
    $this->assertSame(ResourceVerifier::FAIL, $report['verdict']);
  }

  /**
   * @covers ::verify
   */
  public function testCleanResourcePasses(): void {
    $report = $this->verifier([$this->req()], self::FALSE, self::FALSE)->verify('https://seller.example/x', 'GET', [$this->req()]);
    $this->assertSame(ResourceVerifier::PASS, $report['verdict'], json_encode($report['checks']));
    $this->assertSame('3 incoming transfer(s)', $report['checks']['accepts[0].transfers'][1]);
  }

  /**
   * @covers ::verify
   */
  public function testFrozenPayToFails(): void {
    $report = $this->verifier([$this->req()], self::TRUE, self::FALSE)->verify('https://seller.example/x');
    $this->assertSame(ResourceVerifier::FAIL, $report['verdict']);
    $this->assertSame([ResourceVerifier::FAIL, 'address_blacklisted'], $report['checks']['accepts[0].freeze']);
  }

  /**
   * @covers ::verify
   */
  public function testSanctionedPayToFails(): void {
    $report = $this->verifier([$this->req()], self::FALSE, self::TRUE)->verify('https://seller.example/x');
    $this->assertSame(ResourceVerifier::FAIL, $report['verdict']);
    $this->assertSame(ResourceVerifier::FAIL, $report['checks']['accepts[0].ofac'][0]);
  }

  /**
   * A node that does not answer never makes a pass.
   *
   * @covers ::verify
   */
  public function testDownNodeIsUncheckedNotPass(): void {
    $report = $this->verifier([$this->req()], self::FALSE, NULL)->verify('https://seller.example/x');
    $this->assertSame(ResourceVerifier::UNCHECKED, $report['verdict']);
  }

  /**
   * @covers ::verify
   */
  public function testLiveAcceptsDifferingFromCatalogueFails(): void {
    $report = $this->verifier([$this->req(['amount' => '9000'])], self::FALSE, self::FALSE)->verify('https://seller.example/x', 'GET', [$this->req()]);
    $this->assertSame(ResourceVerifier::FAIL, $report['verdict']);
  }

  /**
   * @covers ::verify
   */
  public function testZeroAmountAndOtherSchemeFail(): void {
    $this->assertSame(ResourceVerifier::FAIL, $this->verifier([$this->req(['amount' => '0'])], self::FALSE, self::FALSE)->verify('https://s.example')['verdict']);
    $this->assertSame(ResourceVerifier::FAIL, $this->verifier([$this->req(['scheme' => 'upto'])], self::FALSE, self::FALSE)->verify('https://s.example')['verdict']);
  }

  /**
   * @covers ::verify
   */
  public function testNo402Fails(): void {
    $report = $this->verifier([], NULL, NULL, new \RuntimeException('The endpoint answered 200, not 402 Payment Required.'))->verify('https://s.example');
    $this->assertSame(ResourceVerifier::FAIL, $report['verdict']);
  }

  /**
   * A token with no known freeze list is unchecked, never clean.
   *
   * @covers ::verify
   */
  public function testUnknownTokenIsUnchecked(): void {
    $report = $this->verifier([$this->req(['asset' => '0x' . str_repeat('1', 40)])], self::FALSE, self::FALSE)->verify('https://s.example');
    $this->assertSame(ResourceVerifier::UNCHECKED, $report['verdict']);
  }

}
