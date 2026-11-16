<?php

/**
 * @file
 * A buyer for the Drush scripts: pays an x402 call (the library's
 * PayingHttpClient, inside a budget) and signs in with its wallet, signing
 * with the library's Signer and Eip712 (no Node, no npm: see the
 * README, "No Node.js, on purpose").
 *
 * The key comes from BUYER_PRIVATE_KEY in the environment and stays in PHP.
 */

declare(strict_types=1);

namespace Drupal\ombu\Scripts;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\Eip712;
use Quebracho\X402\EvmExact;
use Quebracho\X402\InvalidPaymentException;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;

final class Buyer {

  public readonly Signer $wallet;

  private ClientInterface $http;

  /**
   * What this run may spend, per network (the library's Budget).
   */
  private Budget $budget;

  /**
   * Builds the buyer.
   *
   * @param \GuzzleHttp\ClientInterface|null $http
   *   The HTTP client; tests pass their own.
   * @param string|null $maxUsd
   *   The most this buyer spends on each network while it lives, in USDC;
   *   BUYER_MAX_USDC from the environment, else 1.
   */
  public function __construct(?ClientInterface $http = NULL, ?string $maxUsd = NULL) {
    $key = getenv('BUYER_PRIVATE_KEY') ?: throw new \RuntimeException('Set BUYER_PRIVATE_KEY.');
    // Trimmed: a key read from a file brings its line break, and the library
    // takes the whole value or nothing.
    $this->wallet = new Signer(trim($key));
    // One cookie jar: a sign-in is kept for the reviews that follow. It waits
    // as long as a payment can settle: a buyer that hangs up first may be
    // charged for an answer it never reads.
    $this->http = $http ?? new Client([
      'cookies' => new CookieJar(),
      'http_errors' => FALSE,
      'timeout' => 120,
      // A payment goes to the host that was asked, never where it points.
      'allow_redirects' => FALSE,
    ]);
    $max = $maxUsd ?? (getenv('BUYER_MAX_USDC') ?: '1');
    // It signs nothing valid for longer than it waits for the answer (120 s):
    // a seller could settle after the script gave up, and a rerun pay again.
    $this->budget = Budget::fromUsd(array_fill_keys(array_keys(Networks::all()), $max), [], NULL, 110);
  }

  /**
   * Calls $url with $input as JSON and pays its 402 once.
   *
   * The library's PayingHttpClient does it: it signs only an offer in a
   * token it knows, to a wallet that is not on OFAC's list, inside this
   * buyer's budget (a cap per network, not one for all). $approve() gets the
   * offer that was signed, before it is sent, and throws to refuse (the
   * caller's own budget). Returns status, body,
   * contentType, tx (NULL if not settled), amount, network, requirements.
   *
   * @throws \RuntimeException
   *   The first answer is not a 402 with requirements, or no offer was
   *   signed ("Not paid: <reason>").
   */
  public function pay(string $url, array $input, ?callable $approve = NULL): array {
    $psr17 = new HttpFactory();
    // What went by: the first answer, and the offer that was signed.
    $seen = new \stdClass();
    $seen->first = $seen->req = $seen->refused = NULL;
    $watching = new class($this->http, $approve, $seen) implements PsrClient {

      public function __construct(private readonly PsrClient $http, private readonly mixed $approve, private readonly \stdClass $seen) {}

      /**
       * {@inheritdoc}
       */
      public function sendRequest(RequestInterface $request): ResponseInterface {
        $signed = $request->getHeaderLine(PaymentHeaders::SIGNATURE);
        if ($signed !== '') {
          $accepted = PaymentHeaders::decode($signed)['accepted'] ?? NULL;
          // The offer that was signed, not the first one listed: the caller
          // refuses it here, before it leaves.
          if ($this->approve && is_array($accepted)) {
            ($this->approve)($accepted);
          }
          $this->seen->req = $accepted;
          return $this->http->sendRequest($request);
        }
        $response = $this->http->sendRequest($request);
        $this->seen->first = $response;
        return $response;
      }

    };
    $client = new PayingHttpClient(
      $watching,
      [new EvmExact($this->wallet)],
      $this->budget,
      onRefused: function (array $req, string $reason) use ($seen): void {
        $seen->refused = $reason;
      },
      // Run from Drush on a site whose payments are paused, it signs nothing.
      stop: \Drupal::hasContainer() && \Drupal::hasService('x402_pay.stop') ? \Drupal::service('x402_pay.stop')->library() : NULL,
    );
    $request = $psr17->createRequest('POST', $url)
      ->withHeader('Accept', '*/*')
      ->withHeader('Content-Type', 'application/json')
      ->withBody($psr17->createStream(json_encode($input ?: new \stdClass(), JSON_UNESCAPED_SLASHES)));
    $answer = $client->sendRequest($request);
    $first = $seen->first;
    if ($first->getStatusCode() !== 402 || $first->getHeaderLine(PaymentHeaders::REQUIRED) === '') {
      throw new \RuntimeException(sprintf('Expected 402, got %d: %s', $first->getStatusCode(), substr((string) $first->getBody(), 0, 300)));
    }
    if ($seen->req === NULL) {
      throw new \RuntimeException('Not paid: ' . ($seen->refused ?? 'no offer this wallet can sign'));
    }
    $receipt = $answer->getHeaderLine(PaymentHeaders::RESPONSE);
    try {
      $receipt = $receipt === '' ? [] : PaymentHeaders::decode($receipt);
    }
    catch (InvalidPaymentException) {
      $receipt = [];
    }
    return [
      'status' => $answer->getStatusCode(),
      'body' => (string) $answer->getBody(),
      'contentType' => $answer->getHeaderLine('Content-Type'),
      'tx' => ($receipt['success'] ?? NULL) === TRUE ? $receipt['transaction'] : NULL,
      'amount' => (string) $seen->req['amount'],
      'network' => $seen->req['network'],
      'requirements' => $seen->req,
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
    return (string) (Networks::find($network)['explorer'] ?? '') . $tx;
  }

}
