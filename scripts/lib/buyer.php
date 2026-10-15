<?php

/**
 * @file
 * A buyer for the Drush scripts: pays an x402 call (x402_pay's Buyer) and
 * signs in with its wallet, signing with x402_pay's Signer and Eip712 (no Node, no npm: see the
 * README, "No Node.js, on purpose").
 *
 * The key comes from BUYER_PRIVATE_KEY in the environment and stays in PHP.
 */

declare(strict_types=1);

namespace Drupal\ombu\Scripts;

use Drupal\x402_pay\Buyer as X402Buyer;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\Networks;
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
   * Calls $url with $input as JSON and pays its 402 (x402_pay's Buyer).
   *
   * $approve($requirements) runs before signing and throws to refuse (the
   * budget). Returns status, body, tx (NULL if not settled), amount,
   * network.
   */
  public function pay(string $url, array $input, ?callable $approve = NULL): array {
    return (new X402Buyer($this->http, $this->wallet))->pay($url, $input, $approve);
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
