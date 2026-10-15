<?php

/**
 * @file
 * A buyer for the Drush scripts: pays an x402 call and signs in with its
 * wallet, signing with x402_pay's Signer and Eip712 (no Node, no npm: see the
 * README, "No Node.js, on purpose").
 *
 * The key comes from BUYER_PRIVATE_KEY in the environment and stays in PHP.
 */

declare(strict_types=1);

namespace Drupal\ombu\Scripts;

use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Signer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

final class Buyer {

  public readonly Signer $wallet;

  private Client $http;

  public function __construct() {
    $key = getenv('BUYER_PRIVATE_KEY') ?: throw new \RuntimeException('Set BUYER_PRIVATE_KEY.');
    $this->wallet = new Signer(trim($key));
    // One cookie jar: a sign-in is kept for the reviews that follow.
    $this->http = new Client(['cookies' => new CookieJar(), 'http_errors' => FALSE, 'timeout' => 30]);
  }

  /**
   * Calls $url with $input as JSON and pays its 402.
   *
   * $approve($requirements) runs before signing and throws to refuse (the
   * budget). Returns status, body, tx (NULL if not settled), amount,
   * network.
   */
  public function pay(string $url, array $input, ?callable $approve = NULL): array {
    $body = json_encode($input ?: new \stdClass(), JSON_UNESCAPED_SLASHES);
    $call = fn(array $headers = [], int $timeout = 30) => $this->http->post($url, [
      'body' => $body,
      'headers' => ['Content-Type' => 'application/json', 'Accept' => '*/*'] + $headers,
      'timeout' => $timeout,
    ]);

    // 1. The 402 says what to pay, to whom, on which chain.
    $first = $call();
    if ($first->getStatusCode() !== 402) {
      throw new \RuntimeException(sprintf('Expected 402, got %d: %s', $first->getStatusCode(), substr((string) $first->getBody(), 0, 300)));
    }
    $required = PaymentHeaders::decode($first->getHeaderLine('PAYMENT-REQUIRED'));
    $req = $required['accepts'][0];
    if ($approve) {
      $approve($req);
    }

    // 2. One EIP-3009 transfer of exactly that: no gas, no allowance.
    $authorization = [
      'from' => $this->wallet->address(),
      'to' => $req['payTo'],
      'value' => (string) $req['amount'],
      'validAfter' => '0',
      'validBefore' => (string) (time() + (int) $req['maxTimeoutSeconds']),
      'nonce' => '0x' . bin2hex(random_bytes(32)),
    ];
    $digest = Eip712::hash(
      ['name' => $req['extra']['name'], 'version' => $req['extra']['version'], 'chainId' => (int) explode(':', $req['network'])[1], 'verifyingContract' => $req['asset']],
      ['TransferWithAuthorization' => [
        ['name' => 'from', 'type' => 'address'], ['name' => 'to', 'type' => 'address'], ['name' => 'value', 'type' => 'uint256'],
        ['name' => 'validAfter', 'type' => 'uint256'], ['name' => 'validBefore', 'type' => 'uint256'], ['name' => 'nonce', 'type' => 'bytes32'],
      ]],
      'TransferWithAuthorization',
      $authorization,
    );
    $payment = [
      'x402Version' => 2,
      'resource' => $required['resource'],
      'accepted' => ['extra' => ['assetTransferMethod' => 'eip3009'] + $req['extra']] + $req,
      'payload' => ['signature' => $this->wallet->sign($digest), 'authorization' => $authorization],
    ];

    // 3. The same call, paid. It waits as long as the payment can settle: a
    // buyer that hangs up first may be charged for an answer it never reads.
    $paid = $call(['PAYMENT-SIGNATURE' => PaymentHeaders::encode($payment)], (int) $req['maxTimeoutSeconds'] + 30);
    $receipt = $paid->getHeaderLine('PAYMENT-RESPONSE');
    $receipt = $receipt === '' ? [] : PaymentHeaders::decode($receipt);
    return [
      'status' => $paid->getStatusCode(),
      'body' => (string) $paid->getBody(),
      'tx' => !empty($receipt['success']) ? $receipt['transaction'] : NULL,
      'amount' => (string) $req['amount'],
      'network' => $req['network'],
    ];
  }

  /**
   * Signs in to $site with the wallet (Sign-In With Ethereum).
   */
  public function signIn(string $site): void {
    $nonce = json_decode((string) $this->http->get("$site/wallet/nonce")->getBody(), TRUE);
    $message = implode("\n", [
      "{$nonce['domain']} wants you to sign in with your Ethereum account:",
      $this->wallet->address(),
      '',
      'Sign in as an agent.',
      '',
      "URI: {$nonce['uri']}",
      'Version: 1',
      'Chain ID: 5042',
      "Nonce: {$nonce['nonce']}",
      'Issued At: ' . gmdate('Y-m-d\TH:i:s.000\Z'),
    ]);
    // EIP-191, as a wallet's personal_sign.
    $digest = Eip712::keccak("\x19Ethereum Signed Message:\n" . strlen($message) . $message);
    $login = $this->http->post("$site/wallet/login", ['json' => ['message' => $message, 'signature' => $this->wallet->sign($digest)]]);
    if ($login->getStatusCode() !== 200) {
      throw new \RuntimeException("Sign-in: {$login->getStatusCode()} " . substr((string) $login->getBody(), 0, 200));
    }
  }

  /**
   * Reviews a purchase: $tx must have bought listing $id from this wallet.
   */
  public function review(string $site, string $id, string $tx, int $rating, string $body): array {
    $this->signIn($site);
    $token = (string) $this->http->get("$site/session/token")->getBody();
    $response = $this->http->post("$site/market/listing/$id/reviews", [
      'headers' => ['X-CSRF-Token' => $token],
      'json' => ['rating' => $rating, 'body' => $body, 'tx' => $tx],
    ]);
    return ['status' => $response->getStatusCode()] + (json_decode((string) $response->getBody(), TRUE) ?: []);
  }

  /**
   * Where people see a transaction.
   */
  public static function explorer(string $network, string $tx): string {
    return (Networks::ALL[$network]['explorer'] ?? '') . $tx;
  }

}
