<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Session\AccountInterface;
use Drupal\key\Entity\Key;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\x402_pay\CircleFacilitator;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\PaymentHeaders;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Signer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Our listings: each paid to its own seller, each delivering its service.
 *
 * Agents call POST /x402/commerce_product/{id}/full with the service's input
 * as JSON. A seller that fails, or input it cannot use, costs nothing.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class SellersTest extends CommerceKernelTestBase {

  // Hardhat's public test accounts #2 and #3, never real wallets.
  private const PING_KEY = '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a';
  private const MARKDOWN_KEY = '0x7c852118294e51e653712a81e05800f419141751be58f605c371e15141b007a6';

  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'text', 'filter', 'key', 'x402_pay', 'ombu', 'file', 'image',
    // PageFetcher (the markdown seller's guarded fetch) lives here; its
    // config is not needed, and the model sellers stay unconfigured.
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('x402_pay', ['x402_pay_log']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['commerce_product', 'comment', 'x402_pay', 'ombu']);
    Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])
      ->grantPermission('view commerce_product')
      ->save();
    foreach (['seller_ping' => self::PING_KEY, 'seller_markdown' => self::MARKDOWN_KEY] as $id => $value) {
      Key::create(['id' => $id, 'label' => $id, 'key_type' => 'authentication', 'key_provider' => 'config', 'key_provider_settings' => ['key_value' => $value]])->save();
    }
  }

  public function testAListingIsPaidToItsOwnSeller(): void {
    $ping = $this->listing('ping', 'seller_ping', '0.0001');
    $markdown = $this->listing('markdown', 'seller_markdown', '0.001');

    $required = $this->required($ping);
    $this->assertSame((new Signer(self::PING_KEY))->address(), $required['accepts'][0]['payTo']);
    $this->assertSame('100', $required['accepts'][0]['amount']);
    $this->assertSame('application/json', $required['resource']['mimeType']);
    $this->assertSame((new Signer(self::MARKDOWN_KEY))->address(), $this->required($markdown)['accepts'][0]['payTo']);
  }

  public function testAListingWithoutAUsableSellerKeyIsNotForSale(): void {
    // Even with a key on the field: that wallet is not this seller's.
    $field = \Drupal::entityTypeManager()->getStorage('field_config')->load('commerce_product.listing.field_x402_price');
    $field->setSetting('key_id', 'seller_markdown')->save();
    $listing = $this->listing('ping', 'missing', '0.0001');
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
  }

  public function testAListingCanBePaidToAnAddressAlone(): void {
    $cold = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $listing = $this->listing('terms', '', '0.01');
    $listing->set('field_seller_address', $cold)->save();
    $this->assertSame($cold, $this->required($listing)['accepts'][0]['payTo']);
  }

  public function testTheColdAddressWinsOverTheKey(): void {
    $cold = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $listing->set('field_seller_address', $cold)->save();
    $this->assertSame($cold, $this->required($listing)['accepts'][0]['payTo']);
  }

  public function testARetiredListingIsNotForSale(): void {
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $listing->set('field_retired', TRUE)->save();
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
  }

  public function testModelSellersGiveTheBuyerTenMinutes(): void {
    $this->assertSame(60, $this->required($this->listing('ping', 'seller_ping', '0.0001'))['accepts'][0]['maxTimeoutSeconds']);
    $this->assertSame(600, $this->required($this->listing('terms', 'seller_ping', '0.01'))['accepts'][0]['maxTimeoutSeconds']);
    $this->assertSame(600, $this->required($this->listing('factcheck', 'seller_ping', '0.02'))['accepts'][0]['maxTimeoutSeconds']);
  }

  public function testPingDeliversATimestampSignedBySeller(): void {
    $response = $this->buy($this->listing('ping', 'seller_ping', '0.0001'), []);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $pong = json_decode($response->getContent(), TRUE);
    $seller = new Signer(self::PING_KEY);
    $this->assertSame($seller->address(), $pong['signer']);
    // Signatures are deterministic (RFC 6979): signing again must match.
    $this->assertSame($seller->sign(Eip712::keccak($pong['time'])), $pong['signature']);
    $this->assertSame('settled', $this->logged());
  }

  public function testMarkdownWithoutAUrlIsNotCharged(): void {
    $response = $this->buy($this->listing('markdown', 'seller_markdown', '0.001'), ['nothing' => 'here']);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('not_settled', $this->logged());
  }

  public function testMarkdownRefusesPrivateAddressesAndIsNotCharged(): void {
    $response = $this->buy($this->listing('markdown', 'seller_markdown', '0.001'), ['url' => 'http://127.0.0.1/admin']);
    $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    $this->assertSame('not_settled', $this->logged());
  }

  public function testASellerWithoutItsModelIsNotCharged(): void {
    $response = $this->buy($this->listing('terms', 'seller_ping', '0.01'), ['text' => 'You agree to binding arbitration.']);
    $this->assertSame(503, $response->getStatusCode(), $response->getContent());
    $this->assertSame('not_settled', $this->logged());
  }

  public function testThePeoplePageIsFree(): void {
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $request = Request::create('/product/' . $listing->id());
    $request->headers->set('Accept', '*/*');
    $this->assertSame(200, $this->container->get('http_kernel')->handle($request)->getStatusCode());
  }

  private function listing(string $service, string $key, string $price): Product {
    $listing = Product::create([
      'type' => 'listing',
      'title' => ucfirst($service),
      'stores' => [$this->store],
      'field_service' => $service,
      'field_seller_key' => $key,
      'field_x402_price' => ['enabled' => 1, 'amount' => $price],
    ]);
    $listing->save();
    return $listing;
  }

  private function required(Product $listing): array {
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(402, $response->getStatusCode(), $response->getContent());
    return PaymentHeaders::decode($response->headers->get(PaymentHeaders::REQUIRED));
  }

  /**
   * A paid POST with the service's input; the facilitator says yes.
   */
  private function buy(Product $listing, array $input): Response {
    $facilitator = $this->createMock(CircleFacilitator::class);
    $facilitator->method('verify')->willReturn(['isValid' => TRUE, 'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66']);
    $facilitator->method('settle')->willReturn(['success' => TRUE, 'transaction' => '0x' . str_repeat('ab', 32), 'network' => 'eip155:5042002', 'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66']);
    $this->container->set('x402_pay.facilitator', $facilitator);

    $accepted = $this->required($listing)['accepts'][0];
    $request = Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: json_encode($input));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set(PaymentHeaders::SIGNATURE, PaymentHeaders::encode(['x402Version' => 2, 'accepted' => $accepted, 'payload' => []]));
    return $this->container->get('http_kernel')->handle($request);
  }

  private function logged(): string {
    return (string) \Drupal::database()->select('x402_pay_log', 'l')->fields('l', ['status'])->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
  }

}
