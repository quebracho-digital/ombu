<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Database\Connection;
use Drupal\x402_pay\Networks;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A review counts only with a purchase on chain: the reviewer paid the seller.
 *
 * The purchase is a transaction with a USDC Transfer from one of the
 * reviewer's wallets (those wallet_auth proved are theirs) to whoever the
 * listing pays: our seller's wallet, or the payTo of a Circle listing. One
 * transaction backs one review. Gateway payments (batched, no transfer of
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
   * Where people see a transaction, by chain: same keys as RPC.
   */
  public const EXPLORER = [
    'eip155:5042' => 'https://explorer.arc.io/tx/',
    'eip155:5042002' => 'https://testnet.arcscan.app/tx/',
    'eip155:8453' => 'https://basescan.org/tx/',
    'eip155:84532' => 'https://sepolia.basescan.org/tx/',
  ];

  /**
   * Keccak-256 of Transfer(address,address,uint256).
   */
  private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

  /**
   * Receipts by network and tx: the validator and presave ask the same.
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
    $tx = strtolower(trim($tx));
    if (!preg_match('/^0x[0-9a-f]{64}$/', $tx)) {
      throw new ReviewRefusedException('A purchase is a transaction hash: 0x and 64 hex digits.');
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
    $checkable = array_intersect_key($payees, self::RPC);
    if (!$checkable) {
      throw new ReviewRefusedException(sprintf('Payments on %s cannot be checked yet.', implode(', ', array_map([Chains::class, 'name'], array_keys($payees))) ?: 'no chain'));
    }
    foreach ($checkable as $network => $accepted) {
      $receipt = $this->receipt($network, $tx);
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
        if (in_array([strtolower($log['address'] ?? ''), $to], $accepted, TRUE) && in_array($from, $wallets, TRUE)) {
          return $from;
        }
      }
      throw new ReviewRefusedException('That transaction pays no USDC from your wallets to this listing\'s seller.');
    }
    throw new ReviewRefusedException('No such transaction on ' . implode(', ', array_map([Chains::class, 'name'], array_keys($checkable))) . '.');
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
   * An account's wallets, lowercase.
   */
  private function wallets(int $uid): array {
    return $this->database->select('wallet_auth_wallet', 'w')->fields('w', ['address'])
      ->condition('uid', $uid)->condition('namespace', 'eip155')->execute()->fetchCol();
  }

  /**
   * Who a listing pays, by network: [asset, payTo] pairs, lowercase.
   */
  public function payees(ProductInterface $listing): array {
    $payees = [];
    if ($listing->get('field_source')->value === 'ours') {
      $seller = $this->sellers->seller($listing);
      $network = (string) $listing->get('field_x402_price')->getFieldDefinition()->getSetting('network');
      if ($seller && isset(Networks::ALL[$network])) {
        $payees[$network][] = [strtolower(Networks::ALL[$network]['asset']), strtolower($seller->address())];
      }
      return $payees;
    }
    foreach (json_decode((string) $listing->get('field_accepts')->value, TRUE) ?: [] as $accept) {
      if (isset($accept['network'], $accept['asset'], $accept['payTo'])) {
        $payees[$accept['network']][] = [strtolower($accept['asset']), strtolower($accept['payTo'])];
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
    return $network === NULL ? NULL : self::EXPLORER[$network] . $tx;
  }

  /**
   * A transaction's receipt, NULL if the chain does not know it.
   */
  private function receipt(string $network, string $tx): ?array {
    if (array_key_exists("$network:$tx", $this->receipts)) {
      return $this->receipts["$network:$tx"];
    }
    try {
      $response = $this->httpClient->request('POST', self::RPC[$network], [
        'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getTransactionReceipt', 'params' => [$tx]],
        'timeout' => 15,
      ]);
    }
    catch (GuzzleException $e) {
      throw new ReviewRefusedException('The chain could not be reached; try again.', previous: $e);
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    return $this->receipts["$network:$tx"] = is_array($body['result'] ?? NULL) ? $body['result'] : NULL;
  }

}
