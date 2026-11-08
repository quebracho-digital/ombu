<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Quebracho\X402\AddressCheck;
use Quebracho\X402\Rpc;
use Quebracho\X402\TokenBlacklist;

/**
 * The checks a paid resource passes before Ombú lists it or vouches for it.
 *
 * Shared by the catalogue importers and the paid verifier. Each check answers
 * "pass", "fail" or "unchecked" with a detail; the verdict is "fail" if any
 * check failed, "unchecked" if a required one could not be asked, else "pass".
 * Fails closed: a node that does not answer is "unchecked", never "pass".
 *
 * Required: the 402, its accepts matching what was announced, the token's
 * freeze list and OFAC's SDN list. Informational: past USDC transfers into
 * payTo (a seller nobody ever paid is not a failure, but it is worth saying).
 */
class ResourceVerifier {

  public const PASS = 'pass';
  public const FAIL = 'fail';
  public const UNCHECKED = 'unchecked';

  /**
   * Chainalysis' sanctions oracle, `isSanctioned(address)`, on Ethereum.
   *
   * The SDN list is the same for an address on any EVM chain, so Ethereum's
   * copy answers for all of them (Base has no deployment).
   */
  public const OFAC_ORACLE = '0x40c57923924b5c5c5455c48d93317139addac8fb';
  public const OFAC_SELECTOR = '0xdf592f7d';

  /**
   * Builds the verifier from its probe, its nodes and its token list.
   *
   * @param \Drupal\ombu\SellerProbe $probe
   *   Asks the seller for its 402.
   * @param \Quebracho\X402\TokenBlacklist $blacklist
   *   Asks the token whether payTo is frozen.
   * @param \Quebracho\X402\Rpc|null $ethereum
   *   Ethereum's node, for OFAC's oracle.
   * @param \Closure|null $transfers
   *   fn(string $network, string $asset, string $payTo): int, the USDC
   *   transfers payTo has received (capped); throws when it cannot ask.
   * @param array<string, string> $tokens
   *   The `network|asset` keys the blacklist was given beyond its own TOKENS:
   *   those, and only those, are asked (TokenBlacklist passes the rest).
   */
  public function __construct(
    private readonly SellerProbe $probe,
    private readonly TokenBlacklist $blacklist,
    private readonly ?Rpc $ethereum,
    private readonly ?\Closure $transfers = NULL,
    array $tokens = [],
  ) {
    $this->known = array_change_key_case($tokens + TokenBlacklist::TOKENS);
  }

  /**
   * Lower-case `network|asset` => function, the tokens with a freeze list.
   */
  private readonly array $known;

  /**
   * Verifies one resource.
   *
   * @param string $url
   *   The resource.
   * @param string $method
   *   GET or POST: what the seller answers 402 to (GET is asked first).
   * @param array $announced
   *   The accepts its catalogue lists; empty to skip the comparison (a URL
   *   someone typed has no catalogue).
   * @param string[] $networks
   *   When not empty, only the live `exact` requirements on these networks are
   *   checked (and returned): a resource that also takes Solana or a
   *   batch-settlement is judged by the part Ombú can verify. None left is a
   *   failure.
   *
   * @return array{verdict: string, checks: array<string, array{0: string, 1: string}>, accepts: array, activity: ?int}
   *   `accepts` are the requirements that were checked; `activity` is the most
   *   USDC transfers any of their payTo addresses has received (NULL: not
   *   asked, or the index did not answer).
   */
  public function verify(string $url, string $method = 'GET', array $announced = [], array $networks = []): array {
    $checks = [];
    try {
      // Ask with GET first: a POST to a seller that skips x402 could run the
      // call unpaid. Only a POST-only endpoint (404/405 to GET) gets the POST.
      try {
        [, $accepts] = $this->probe->requirements($url, 'GET');
      }
      catch (\RuntimeException $e) {
        if ($method !== 'POST' || !preg_match('/answered 40[45],/', $e->getMessage())) {
          throw $e;
        }
        [, $accepts] = $this->probe->requirements($url, 'POST');
      }
      $checks['probe'] = [self::PASS, count($accepts) . ' requirement(s)'];
    }
    catch (\RuntimeException $e) {
      return $this->report(['probe' => [self::FAIL, $e->getMessage()]], []);
    }

    if ($networks) {
      $networks = array_map('strtolower', $networks);
      $accepts = array_values(array_filter($accepts, fn($r) => is_array($r)
        && ($r['scheme'] ?? '') === 'exact'
        && in_array(strtolower((string) ($r['network'] ?? '')), $networks, TRUE)));
      if (!$accepts) {
        return $this->report($checks + ['accepts' => [self::FAIL, 'no exact requirement on a network Ombú verifies']], []);
      }
    }
    $activity = NULL;
    $fingerprints = array_map([self::class, 'fingerprint'], array_filter($announced, 'is_array'));
    foreach (array_values($accepts) as $i => $req) {
      $at = "accepts[$i]";
      if (!is_array($req) || ($req['scheme'] ?? '') !== 'exact' || empty($req['network']) || empty($req['payTo']) || empty($req['asset'])) {
        $checks[$at] = [self::FAIL, 'malformed or not exact'];
        continue;
      }
      $amount = (string) ($req['amount'] ?? $req['maxAmountRequired'] ?? '');
      if (!ctype_digit($amount) || ltrim($amount, '0') === '') {
        $checks[$at] = [self::FAIL, 'amount not a positive integer'];
        continue;
      }
      if ($fingerprints && !in_array(self::fingerprint($req), $fingerprints, TRUE)) {
        $checks[$at] = [self::FAIL, 'differs from the catalogue (network, asset, payTo or amount)'];
        continue;
      }
      $checks[$at] = [self::PASS, $req['network'] . ' ' . $amount];
      $checks["$at.freeze"] = $this->freeze($req);
      $checks["$at.ofac"] = $this->ofac((string) $req['network'], (string) $req['payTo']);
      $count = NULL;
      $checks["$at.transfers"] = $this->history($req, $count);
      if ($count !== NULL) {
        $activity = max($activity ?? 0, $count);
      }
    }
    return $this->report($checks, $accepts, $activity);
  }

  /**
   * Frozen by the token's own contract? Only the tokens the blacklist knows.
   */
  private function freeze(array $req): array {
    $check = new AddressCheck(AddressCheck::PAY_TO, (string) $req['network'], (string) $req['payTo'], $req);
    if (!isset($this->known[strtolower($req['network'] . '|' . $req['asset'])])) {
      return [self::UNCHECKED, 'no freeze list known for this token'];
    }
    try {
      ($this->blacklist)($check);
    }
    catch (\Throwable $e) {
      return [self::UNCHECKED, 'node did not answer'];
    }
    return $check->reason() === NULL ? [self::PASS, 'not frozen'] : [self::FAIL, $check->reason()];
  }

  /**
   * On OFAC's SDN list? EVM addresses only.
   */
  private function ofac(string $network, string $payTo): array {
    if (!str_starts_with($network, 'eip155:')) {
      return [self::UNCHECKED, 'OFAC oracle is EVM only'];
    }
    if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $payTo)) {
      return [self::FAIL, 'payTo is not an EVM address'];
    }
    if ($this->ethereum === NULL) {
      return [self::UNCHECKED, 'no Ethereum node configured'];
    }
    try {
      $call = ['to' => self::OFAC_ORACLE, 'data' => self::OFAC_SELECTOR . Rpc::word($payTo)];
      $answer = ($this->ethereum)('eth_call', [$call, 'latest']);
    }
    catch (\Throwable) {
      return [self::UNCHECKED, 'node did not answer'];
    }
    if (!is_string($answer) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $answer)) {
      return [self::UNCHECKED, 'oracle gave no bool'];
    }
    return gmp_cmp(Rpc::number($answer), 0) === 0
      ? [self::PASS, 'not sanctioned']
      : [self::FAIL, 'sanctioned (OFAC SDN)'];
  }

  /**
   * How many USDC transfers payTo has received. Informational.
   */
  private function history(array $req, ?int &$count = NULL): array {
    if ($this->transfers === NULL) {
      return [self::UNCHECKED, 'no transfer index configured'];
    }
    try {
      $count = ($this->transfers)((string) $req['network'], (string) $req['asset'], (string) $req['payTo']);
    }
    catch (\Throwable) {
      return [self::UNCHECKED, 'transfer index did not answer'];
    }
    $count = (int) $count;
    return [self::PASS, $count . ' incoming transfer(s)'];
  }

  /**
   * The verdict over the required checks; transfers never decide it.
   */
  private function report(array $checks, array $accepts, ?int $activity = NULL): array {
    $verdict = self::PASS;
    foreach ($checks as $name => [$result]) {
      if (str_ends_with($name, '.transfers')) {
        continue;
      }
      if ($result === self::FAIL) {
        $verdict = self::FAIL;
        break;
      }
      if ($result === self::UNCHECKED) {
        $verdict = self::UNCHECKED;
      }
    }
    return ['verdict' => $verdict, 'checks' => $checks, 'accepts' => $accepts, 'activity' => $activity];
  }

  /**
   * What must match between a catalogue's accepts and the live 402.
   */
  public static function fingerprint(array $a): string {
    return strtolower(implode('|', [
      $a['network'] ?? '',
      $a['asset'] ?? '',
      $a['payTo'] ?? '',
      $a['amount'] ?? $a['maxAmountRequired'] ?? '',
    ]));
  }

}
