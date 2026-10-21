<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Database\Connection;
use Drupal\x402_pay\Networks;
use Drupal\x402_pay\Solana;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A review counts only with a purchase on chain: the reviewer paid the seller.
 *
 * The purchase is a transaction with a USDC Transfer from one of the
 * reviewer's wallets (those wallet_auth proved are theirs) to whoever the
 * listing pays: our seller's wallet, or the payTo of a Circle listing. On
 * Solana, a TransferChecked of the USDC mint into the payee's token
 * account, by the reviewer's wallet. One transaction backs one review. Gateway payments (batched, no transfer of
 * their own) cannot be checked this way yet.
 */
final class ReviewVerifier {

  /**
   * Public RPCs of the chains whose payments can be checked.
   */
  public const RPC = [
    'eip155:5042' => 'https://rpc.mainnet.arc.io',
    'eip155:5042002' => 'https://rpc.testnet.arc.network',
    'eip155:8453' => 'https://mainnet.base.org',
    'eip155:84532' => 'https://sepolia.base.org',
  ];

  /**
   * Where people see a transaction, by chain: RPC's keys and Solana's.
   */
  public const EXPLORER = [
    'eip155:5042' => 'https://explorer.arc.io/tx/%s',
    'eip155:5042002' => 'https://testnet.arcscan.app/tx/%s',
    'eip155:8453' => 'https://basescan.org/tx/%s',
    'eip155:84532' => 'https://sepolia.basescan.org/tx/%s',
    // cspell:disable-next-line
    'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => 'https://solscan.io/tx/%s',
    // cspell:disable-next-line
    'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => 'https://explorer.solana.com/tx/%s?cluster=devnet',
  ];

  /**
   * Keccak-256 of Transfer(address,address,uint256).
   */
  private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

  /**
   * RPC answers (receipts, transactions): the validator and presave ask
   * the same.
   */
  private array $receipts = [];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Connection $database,
    private readonly Sellers $sellers,
  ) {}

  /**
   * The wallet that paid for this listing in $tx; throws why it did not.
   *
   * @param int $uid
   *   The reviewer: the payment must come from one of their wallets.
   * @param int|null $cid
   *   The review itself, if saved: its own tx is not a reuse.
   *
   * @throws \Drupal\ombu\ReviewRefusedException
   */
  public function payer(ProductInterface $listing, string $tx, int $uid, ?int $cid = NULL): string {
    $tx = self::normalize($tx);
    $solana = (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,88}$/', $tx);
    if (!$solana && !preg_match('/^0x[0-9a-f]{64}$/', $tx)) {
      throw new ReviewRefusedException('A purchase is a transaction hash (0x and 64 hex digits) or a Solana signature.');
    }
    $used = $this->database->select('comment__field_tx_hash', 't')->fields('t', ['entity_id'])
      ->condition('field_tx_hash_value', $tx);
    if ($cid) {
      $used->condition('entity_id', $cid, '<>');
    }
    if ($used->range(0, 1)->execute()->fetchField()) {
      throw new ReviewRefusedException('That purchase already backs a review.');
    }
    $wallets = $this->wallets($uid);
    if (!$wallets) {
      throw new ReviewRefusedException('Sign in with the wallet that paid: reviews need a purchase from your wallet.');
    }

    $payees = $this->payees($listing);
    // The chains this kind of transaction can be on.
    $checkable = array_filter($payees, fn($network) => $solana
      ? str_starts_with($network, 'solana:') && isset(Networks::ALL[$network])
      : isset(self::RPC[$network]), ARRAY_FILTER_USE_KEY);
    if (!$checkable) {
      throw new ReviewRefusedException(sprintf('Payments on %s cannot be checked yet.', implode(', ', array_map([Chains::class, 'name'], array_keys($payees))) ?: 'no chain'));
    }
    foreach ($checkable as $network => $accepted) {
      if ($solana) {
        $from = $this->solanaPayer($listing, $network, $tx, $accepted, $wallets);
        if ($from === NULL) {
          continue;
        }
        return $from;
      }
      $receipt = $this->rpc(self::RPC[$network], 'eth_getTransactionReceipt', [$tx]);
      if (!$receipt) {
        continue;
      }
      if (($receipt['status'] ?? NULL) !== '0x1') {
        throw new ReviewRefusedException('That transaction failed on chain.');
      }
      foreach ($receipt['logs'] ?? [] as $log) {
        $topics = array_map('strtolower', $log['topics'] ?? []);
        if (count($topics) !== 3 || $topics[0] !== self::TRANSFER) {
          continue;
        }
        $from = '0x' . substr($topics[1], -40);
        $to = '0x' . substr($topics[2], -40);
        if (in_array([strtolower($log['address'] ?? ''), $to], $accepted, TRUE) && in_array($from, $wallets, TRUE) && $this->paysFor($listing, $network, $tx, gmp_strval(gmp_init(substr((string) ($log['data'] ?? ''), 2) ?: '0', 16)))) {
          return $from;
        }
      }
      throw new ReviewRefusedException('That transaction pays no USDC from your wallets to this listing\'s seller, or less than its price (ours: bought through Ombú).');
    }
    throw new ReviewRefusedException('No such transaction on ' . implode(', ', array_map([Chains::class, 'name'], array_keys($checkable))) . '.');
  }

  /**
   * The Solana wallet that paid the listing in this transaction; NULL if
   * the chain does not know it.
   *
   * @throws \Drupal\ombu\ReviewRefusedException
   */
  private function solanaPayer(ProductInterface $listing, string $network, string $tx, array $accepted, array $wallets): ?string {
    $result = $this->rpc(Networks::ALL[$network]['rpc'], 'getTransaction', [$tx, ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0, 'commitment' => 'confirmed']]);
    if (!$result) {
      return NULL;
    }
    if ($result['meta']['err'] ?? NULL) {
      throw new ReviewRefusedException('That transaction failed on chain.');
    }
    foreach ($result['transaction']['message']['instructions'] ?? [] as $instruction) {
      $info = $instruction['parsed']['info'] ?? [];
      if (($instruction['program'] ?? '') !== 'spl-token' || ($instruction['parsed']['type'] ?? '') !== 'transferChecked') {
        continue;
      }
      foreach ($accepted as [$mint, $payTo]) {
        if (($info['mint'] ?? '') === $mint && ($info['destination'] ?? '') === Solana::ata($payTo, $mint)
          && in_array($info['authority'] ?? '', $wallets, TRUE) && $this->paysFor($listing, $network, $tx, (string) ($info['tokenAmount']['amount'] ?? '0'))) {
          return $info['authority'];
        }
      }
    }
    throw new ReviewRefusedException('That transaction pays no USDC from your wallets to this listing\'s seller, or less than its price (ours: bought through Ombú).');
  }

  /**
   * Whether a transfer bought this listing, not merely paid its seller.
   *
   * Our sellers share one payTo, so our own sales log must show this tx
   * settled for this listing. A Circle listing's transfer must be at least
   * its price on that network.
   */
  private function paysFor(ProductInterface $listing, string $network, string $tx, string $amount): bool {
    if ($listing->get('field_source')->value === 'ours') {
      return (bool) $this->database->select('x402_pay_log', 'l')->fields('l', ['id'])
        ->condition('transaction', $tx)->condition('status', 'settled')
        // Paid at the door or at the listing's canonical URL.
        ->condition($this->database->condition('OR')
          ->condition('resource', '%/commerce_product/' . $listing->id() . '/%', 'LIKE')
          ->condition('resource', '%/product/' . $listing->id(), 'LIKE'))
        ->range(0, 1)->execute()->fetchField();
    }
    $prices = [];
    foreach (json_decode((string) $listing->get('field_accepts')->value, TRUE) ?: [] as $accept) {
      if (($accept['network'] ?? NULL) === $network && isset($accept['amount'])) {
        $prices[] = (float) $accept['amount'];
      }
    }
    // ponytail: compared as floats, exact below 2^53 atomic units.
    return (float) $amount >= ($prices ? min($prices) : 1);
  }

  /**
   * Whether this account is the listing's seller: it signs in with a wallet
   * the listing pays. No one assigns sellers; the wallet proves it.
   */
  public function sells(ProductInterface $listing, int $uid): bool {
    if (!$uid || $listing->bundle() !== 'listing') {
      return FALSE;
    }
    $paid = array_column(array_merge(...array_values($this->payees($listing)) ?: [[]]), 1);
    return (bool) array_intersect($paid, $this->wallets($uid));
  }

  /**
   * The IDs of the listings an account sells: those paying one of its
   * wallets, as sells() sees it.
   */
  public function listingsOf(int $uid): array {
    if (!$wallets = $this->wallets($uid)) {
      return [];
    }
    // The columns compare case-insensitively: checksummed values match.
    $ids = [];
    foreach (['commerce_product__field_pay_to' => 'field_pay_to_value', 'commerce_product__field_seller_address' => 'field_seller_address_value'] as $table => $column) {
      $ids = [...$ids, ...$this->database->select($table, 't')->fields('t', ['entity_id'])
        ->condition($column, $wallets, 'IN')->execute()->fetchCol()];
    }
    // Ours paid to a seller key (no address on the listing): the key's
    // address, as Sellers::payTo() sees it.
    $keyed = $this->database->select('commerce_product__field_seller_key', 'k')->fields('k', ['entity_id'])->execute()->fetchCol();
    $lower = array_map('strtolower', $wallets);
    foreach (Product::loadMultiple(array_diff($keyed, $ids)) as $listing) {
      if (in_array(strtolower((string) $this->sellers->payTo($listing)), $lower, TRUE)) {
        $ids[] = $listing->id();
      }
    }
    return array_values(array_unique(array_map('intval', $ids)));
  }

  /**
   * A purchase as stored: an EVM hash lowercase, a Solana signature as is.
   */
  public static function normalize(string $tx): string {
    $tx = trim($tx);
    return stripos($tx, '0x') === 0 ? strtolower($tx) : $tx;
  }

  /**
   * An account's wallets, as wallet_auth stores them: EVM lowercase,
   * Solana as is (base58 is case-sensitive).
   */
  public function wallets(int $uid): array {
    return $this->database->select('wallet_auth_wallet', 'w')->fields('w', ['address'])
      ->condition('uid', $uid)->execute()->fetchCol();
  }

  /**
   * Who a listing pays, by network: [asset, payTo] pairs; EVM's lowercase.
   */
  public function payees(ProductInterface $listing): array {
    $key = fn(string $network, string $value) => str_starts_with($network, 'eip155:') ? strtolower($value) : $value;
    $payees = [];
    if ($listing->get('field_source')->value === 'ours') {
      $payTo = $this->sellers->payTo($listing);
      $network = Sellers::network($listing);
      if ($payTo && isset(Networks::ALL[$network])) {
        $payees[$network][] = [$key($network, Networks::ALL[$network]['asset']), $key($network, $payTo)];
      }
      return $payees;
    }
    foreach (json_decode((string) $listing->get('field_accepts')->value, TRUE) ?: [] as $accept) {
      if (isset($accept['network'], $accept['asset'], $accept['payTo'])) {
        $payees[$accept['network']][] = [$key($accept['network'], $accept['asset']), $key($accept['network'], $accept['payTo'])];
      }
    }
    return $payees;
  }

  /**
   * The explorer page of a listing's purchase; NULL if no chain it pays on
   * has one.
   */
  public function explorerUrl(ProductInterface $listing, string $tx): ?string {
    $network = array_key_first(array_intersect_key($this->payees($listing), self::EXPLORER));
    return $network === NULL ? NULL : sprintf(self::EXPLORER[$network], $tx);
  }

  /**
   * One JSON-RPC answer: a receipt, a transaction; NULL if the chain does
   * not know it. Cached: the validator and presave ask the same.
   */
  private function rpc(string $url, string $method, array $params): ?array {
    $key = "$url:$method:" . json_encode($params);
    if (array_key_exists($key, $this->receipts)) {
      return $this->receipts[$key];
    }
    try {
      $response = $this->httpClient->request('POST', $url, [
        'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
        'timeout' => 15,
      ]);
    }
    catch (GuzzleException $e) {
      throw new ReviewRefusedException('The chain could not be reached; try again.', previous: $e);
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    return $this->receipts[$key] = is_array($body['result'] ?? NULL) ? $body['result'] : NULL;
  }

}
