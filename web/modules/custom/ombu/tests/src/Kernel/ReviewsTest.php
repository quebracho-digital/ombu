<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\comment\Entity\Comment;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * A review counts only with a purchase on chain from the reviewer's wallet.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class ReviewsTest extends CommerceKernelTestBase {

  private const ASSET = '0x3600000000000000000000000000000000000000';
  private const SELLER = '0x263ce7c77aae2bbb7c3d6c45bb0137edf9321cdf';
  private const OTHER = '0x941d7505c14102e0b40c49e3eb0be68230366e8a';
  private const BUYER = '0xcb2d7f50d917fed02b82ef08cefbf9827463b794';
  private const STRANGER = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'wallet_auth',
  ];

  /**
   * Receipts the chain knows, by tx hash.
   */
  private static array $chain = [];

  private ProductInterface $listing;

  private User $buyer;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The chain is this test's, in every container the kernel builds.
    $container->register('http_client', Client::class)->setFactory([self::class, 'chain']);
  }

  /**
   * An HTTP client that answers eth_getTransactionReceipt from self::$chain.
   */
  public static function chain(): Client {
    return new Client(['handler' => function (RequestInterface $request) {
      $tx = json_decode((string) $request->getBody(), TRUE)['params'][0];
      return Create::promiseFor(new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => self::$chain[$tx] ?? NULL])));
    }]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('wallet_auth', ['wallet_auth_wallet']);
    $this->installConfig(['commerce_product', 'comment', 'ombu']);

    $this->listing = Product::create([
      'type' => 'listing',
      'title' => 'Markdown',
      'stores' => [$this->store],
      'field_source' => 'circle',
      'field_accepts' => json_encode([['scheme' => 'exact', 'network' => 'eip155:5042002', 'asset' => self::ASSET, 'payTo' => self::SELLER, 'amount' => '1000']]),
    ]);
    $this->listing->save();
    $this->buyer = $this->createUser(['post comments', 'skip comment approval', 'view commerce_product']);
    // Reviews are written signed in: the listing is checked as theirs to see.
    $this->setCurrentUser($this->buyer);
    \Drupal::database()->insert('wallet_auth_wallet')->fields(['namespace' => 'eip155', 'address' => self::BUYER, 'uid' => $this->buyer->id(), 'created' => time()])->execute();
  }

  public function testAPurchaseFromTheReviewersWalletIsAcceptedAndCounted(): void {
    $tx = $this->paid(self::BUYER, self::SELLER);
    $review = $this->review($tx, 4);
    $this->assertCount(0, $review->validate());
    $review->save();
    $this->assertSame(self::BUYER, $review->get('field_wallet')->value);
    $this->assertSame(['average' => 4.0, 'count' => 1], ombu_rating($this->listing));
    $this->assertSame("https://testnet.arcscan.app/tx/$tx", $this->container->get('ombu.review_verifier')->explorerUrl($this->listing, $tx));
  }

  public function testAPaymentToAnotherWalletIsRefused(): void {
    $this->assertRefused($this->paid(self::BUYER, self::OTHER), 'pays no USDC from your wallets to this listing');
  }

  public function testSomeoneElsesPurchaseIsRefused(): void {
    $this->assertRefused($this->paid(self::STRANGER, self::SELLER), 'pays no USDC from your wallets to this listing');
  }

  public function testAFailedOrUnknownTransactionIsRefused(): void {
    $failed = $this->paid(self::BUYER, self::SELLER, status: '0x0');
    $this->assertRefused($failed, 'failed on chain');
    $this->assertRefused('0x' . str_repeat('ab', 32), 'No such transaction');
    $this->assertRefused('', 'needs a purchase');
  }

  public function testOnePurchaseBacksOneReview(): void {
    $tx = $this->paid(self::BUYER, self::SELLER);
    $this->review($tx, 5)->save();
    $this->assertRefused($tx, 'already backs a review');
  }

  public function testAReviewSavedWithoutTheCheckIsNotCounted(): void {
    $this->review($this->paid(self::BUYER, self::SELLER), 5)->save();
    // Programmatic saves skip validation: the wallet stays empty and the
    // review is kept, but the average ignores it.
    $this->review($this->paid(self::BUYER, self::OTHER), 1)->save();
    $this->assertSame(['average' => 5.0, 'count' => 1], ombu_rating($this->listing));
  }

  /**
   * A transaction with one USDC Transfer, known to the chain.
   */
  private function paid(string $from, string $to, string $status = '0x1'): string {
    $tx = '0x' . bin2hex(random_bytes(32));
    $topic = fn(string $address) => '0x' . str_repeat('0', 24) . substr($address, 2);
    self::$chain[$tx] = ['status' => $status, 'logs' => [[
      'address' => self::ASSET,
      'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', $topic($from), $topic($to)],
      'data' => '0x' . str_pad(dechex(1000), 64, '0', STR_PAD_LEFT),
    ]]];
    return $tx;
  }

  /**
   * An unsaved review of the listing by the buyer.
   */
  private function review(string $tx, int $rating): Comment {
    return Comment::create([
      'comment_type' => 'review',
      'entity_type' => 'commerce_product',
      'entity_id' => $this->listing->id(),
      'field_name' => 'field_reviews',
      'uid' => $this->buyer->id(),
      'comment_body' => 'Clean markdown.',
      'field_rating' => $rating,
      'field_tx_hash' => $tx,
    ]);
  }

  private function assertRefused(string $tx, string $why): void {
    $messages = array_map(fn($v) => $v->getPropertyPath() . ': ' . $v->getMessage(), iterator_to_array($this->review($tx, 1)->validate()));
    $this->assertNotEmpty(array_filter($messages, fn($m) => str_contains($m, $why)), implode(' | ', $messages));
  }

}
