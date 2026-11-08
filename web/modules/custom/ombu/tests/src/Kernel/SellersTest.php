<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\ombu\Form\SellForm;
use Drupal\ombu\SellerProbe;
use GuzzleHttp\Client;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Session\AccountInterface;
use Drupal\key\Entity\Key;
use Drupal\ombu\Mcp\McpPayments;
use Drupal\ombu\Sales;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\x402_pay\Facilitators;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Quebracho\X402\Eip712;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Facilitator;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\Signer;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
  // Hardhat's #4, the buyer.
  private const BUYER_KEY = '0x47e179ec197488593b187f80a00eb0da91f1b9d0b13f8733639f19c30a34926a';

  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'text', 'filter', 'link', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views', 'user', 'wallet_auth',
    // PageFetcher (the markdown seller's guarded fetch) lives here; its
    // config is not needed, and the model sellers stay unconfigured.
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck', 'mcp_server',
  ];

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('x402_pay', ['x402_pay_log', 'x402_pay_claim']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['commerce_product', 'comment', 'x402_pay', 'ombu']);
    Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])
      ->grantPermission('view commerce_product')
      ->save();
    foreach (['seller_ping' => self::PING_KEY, 'seller_markdown' => self::MARKDOWN_KEY] as $id => $value) {
      Key::create([
        'id' => $id,
        'label' => $id,
        'key_type' => 'authentication',
        'key_provider' => 'config',
        'key_provider_settings' => ['key_value' => $value],
      ])->save();
    }
  }

  /**
   * Tests that a listing is paid to its own seller.
   */
  public function testListingIsPaidToItsOwnSeller(): void {
    $ping = $this->listing('ping', 'seller_ping', '0.0001');
    $markdown = $this->listing('markdown', 'seller_markdown', '0.001');

    $required = $this->required($ping);
    $this->assertSame((new Signer(self::PING_KEY))->address(), $required['accepts'][0]['payTo']);
    $this->assertSame('100', $required['accepts'][0]['amount']);
    $this->assertSame('application/json', $required['resource']['mimeType']);
    $this->assertSame((new Signer(self::MARKDOWN_KEY))->address(), $this->required($markdown)['accepts'][0]['payTo']);
  }

  /**
   * Tests that a listing without a usable seller key is not for sale.
   */
  public function testListingWithoutUsableSellerKeyIsNotForSale(): void {
    // Even with a key on the field: that wallet is not this seller's.
    $field = \Drupal::entityTypeManager()->getStorage('field_config')->load('commerce_product.listing.field_x402_price');
    $field->setSetting('key_id', 'seller_markdown')->save();
    $listing = $this->listing('ping', 'missing', '0.0001');
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
  }

  /**
   * Tests that a listing can be paid to an address alone.
   */
  public function testListingCanBePaidToAnAddressAlone(): void {
    $cold = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $listing = $this->listing('terms', '', '0.01');
    $listing->set('field_seller_address', $cold)->save();
    $this->assertSame($cold, $this->required($listing)['accepts'][0]['payTo']);
  }

  /**
   * Tests that a solana address sells on solana.
   */
  public function testSolanaAddressSellsOnSolana(): void {
    // cspell:disable-next-line
    [
      $devnet,
      $seller,
      $fee_payer,
    ] = ['solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', '6PJNCqXycHy7qsPPsz1wj9REkZxnJQFutEgfKqyZpuGU', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5'];
    $this->setSetting('ombu_solana_network', $devnet);
    // Devnet's facilitator, its /supported already cached: no request out.
    $url = 'https://x402.org/facilitator';
    $this->config('x402_pay.settings')->set('facilitator.remote', [$devnet => ['url' => $url, 'key_id' => '']])->save();
    \Drupal::cache()->set('x402_pay.supported:' . hash('sha256', $url), [
      ['x402Version' => 2, 'scheme' => 'exact', 'network' => $devnet, 'extra' => ['feePayer' => $fee_payer]],
    ]);
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $listing->set('field_seller_address', $seller)->save();
    $accepts = $this->required($listing)['accepts'][0];
    // cspell:disable-next-line
    $this->assertSame([
      $devnet,
      '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
      $seller,
      $fee_payer,
    ], [$accepts['network'], $accepts['asset'], $accepts['payTo'], $accepts['extra']['feePayer']]);
  }

  /**
   * Tests that the cold address wins over the key.
   */
  public function testTheColdAddressWinsOverTheKey(): void {
    $cold = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $listing->set('field_seller_address', $cold)->save();
    $this->assertSame($cold, $this->required($listing)['accepts'][0]['payTo']);
  }

  /**
   * Tests that a retired listing is not for sale.
   */
  public function testRetiredListingIsNotForSale(): void {
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $listing->set('field_retired', TRUE)->save();
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(403, $response->getStatusCode(), $response->getContent());
  }

  /**
   * Tests that model sellers give the buyer ten minutes.
   */
  public function testModelSellersGiveTheBuyerTenMinutes(): void {
    $this->assertSame(60, $this->required($this->listing('ping', 'seller_ping', '0.0001'))['accepts'][0]['maxTimeoutSeconds']);
    $this->assertSame(600, $this->required($this->listing('terms', 'seller_ping', '0.01'))['accepts'][0]['maxTimeoutSeconds']);
    $this->assertSame(600, $this->required($this->listing('factcheck', 'seller_ping', '0.02'))['accepts'][0]['maxTimeoutSeconds']);
  }

  /**
   * Tests that ping delivers a timestamp signed by seller.
   */
  public function testPingDeliversTimestampSignedBySeller(): void {
    $response = $this->buy($this->listing('ping', 'seller_ping', '0.0001'), []);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $pong = json_decode($response->getContent(), TRUE);
    $seller = new Signer(self::PING_KEY);
    $this->assertSame($seller->address(), $pong['signer']);
    // Signatures are deterministic (RFC 6979): signing again must match.
    $this->assertSame($seller->sign(Eip712::keccak($pong['time'])), $pong['signature']);
    $this->assertSame('settled', $this->logged());
  }

  /**
   * Tests that a settled sale shows with its transaction.
   */
  public function testSettledSaleShowsWithItsTransaction(): void {
    $ping = $this->listing('ping', 'seller_ping', '0.0001');
    $this->buy($ping, []);
    $this->buy($this->listing('markdown', 'seller_markdown', '0.001'), []);
    $html = (string) \Drupal::service('renderer')->renderInIsolation(Sales::build($ping));
    $this->assertStringContainsString('/tx/0x' . str_repeat('ab', 32), $html);
    $this->assertSame(1, substr_count($html, ' UTC'), 'Only its own sale, not the unsettled one.');
    $this->assertStringContainsString('0.0001 USDC', $html);
    $this->assertSame('1.5', Sales::usdc('1500000'));
    $this->assertSame('0', Sales::usdc('0'));
    $this->assertStringNotContainsString('settled by', $html, 'Circle settles: no gas wallet of ours to show.');

    // Settled by our own facilitator: its gas wallet, linked.
    $this->config('x402_pay.settings')->set('facilitator.own_networks', ['eip155:5042'])->set('facilitator.own_key_id', 'seller_ping')->save();
    $html = (string) \Drupal::service('renderer')->renderInIsolation(Sales::build($ping));
    $this->assertStringContainsString('/address/' . (new Signer(self::PING_KEY))->address(), $html);
  }

  /**
   * Tests that my listings finds a listing paid to the sellers key.
   */
  public function testMyListingsFindsListingPaidToTheSellersKey(): void {
    $this->installSchema('wallet_auth', ['wallet_auth_wallet']);
    $ping = $this->listing('ping', 'seller_ping', '0.0001');
    $this->listing('markdown', 'seller_markdown', '0.001');
    $seller = $this->createUser();
    \Drupal::database()->insert('wallet_auth_wallet')->fields([
      'namespace' => 'eip155',
      'address' => strtolower((new Signer(self::PING_KEY))->address()),
      'uid' => $seller->id(),
      'created' => time(),
    ])->execute();
    $this->setCurrentUser($seller);
    $argument = \Drupal::service('plugin.manager.views.argument_default')->createInstance('ombu_seller_listings');
    $this->assertSame((string) $ping->id(), $argument->getArgument());
  }

  /**
   * Tests that a seller lists their own endpoint for approval.
   */
  public function testSellerListsTheirOwnEndpointForApproval(): void {
    $this->installSchema('wallet_auth', ['wallet_auth_wallet']);
    $this->installEntitySchema('taxonomy_term');
    $wallet = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';
    $seller = $this->createUser();
    \Drupal::database()->insert('wallet_auth_wallet')->fields([
      'namespace' => 'eip155',
      'address' => $wallet,
      'uid' => $seller->id(),
      'created' => time(),
    ])->execute();
    $this->setCurrentUser($seller);
    $submit = function (string $payTo, string $endpoint) {
      $probe = new class(new Client(), $payTo) extends SellerProbe {

        public function __construct($client, private string $payTo) {
          parent::__construct($client);
        }

        /**
         * Requirements.
         */
        public function requirements(string $url, string $method): array {
          // cspell:disable-next-line
          [
            $network,
            $asset,
          ] = str_starts_with($this->payTo, '0x') ? ['eip155:8453', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'] : ['solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp', 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'];
          return [
            $url,
            [
              [
                'scheme' => 'exact',
                'network' => $network,
                'asset' => $asset,
                'payTo' => $this->payTo,
                'amount' => '20000',
              ],
            ],
          ];
        }

      };
      $form = new SellForm($probe, $this->container->get('ombu.circle_importer'), \Drupal::database());
      $state = (new FormState())->setValues([
        'endpoint' => $endpoint,
        'method' => 'POST',
        'name' => 'Weather now',
        'provider' => 'Acme',
        'description' => '',
        'website' => '',
      ]);
      \Drupal::formBuilder()->submitForm($form, $state);
      return $state->getErrors();
    };

    // Its 402 pays someone else: refused.
    $this->assertNotEmpty($submit('0x941d7505c14102e0b40c49e3eb0be68230366e8a', 'https://acme.example/weather'));
    $this->assertSame([], \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('field_source', 'third_party')->execute());

    // It pays the seller's wallet: listed, unpublished until approved.
    $this->assertSame([], $submit(strtoupper($wallet), 'https://acme.example/weather'));
    $ids = \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('field_source', 'third_party')->execute();
    $this->assertCount(1, $ids);
    $listing = Product::load(reset($ids));
    $this->assertFalse($listing->isPublished());
    $this->assertSame('0.02', $listing->getDefaultVariation()->getPrice()->getNumber() + 0 . '');

    // The same endpoint again does not make a second one.
    $submit($wallet, 'https://acme.example/weather');
    $this->assertCount(1, \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('field_source', 'third_party')->execute());

    // Paid on Solana, to their Solana wallet; base58 matches only exactly.
    // cspell:disable-next-line
    $solana = '6PJNCqXycHy7qsPPsz1wj9REkZxnJQFutEgfKqyZpuGU';
    \Drupal::database()->insert('wallet_auth_wallet')->fields([
      'namespace' => 'solana',
      'address' => $solana,
      'uid' => $seller->id(),
      'created' => time(),
    ])->execute();
    $this->assertNotEmpty($submit(strtolower($solana), 'https://acme.example/solana'));
    $this->assertSame([], $submit($solana, 'https://acme.example/solana'));
    $this->assertCount(2, \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('field_source', 'third_party')->execute());
  }

  /**
   * Tests that markdown without a url is not charged.
   */
  public function testMarkdownWithoutUrlIsNotCharged(): void {
    $response = $this->buy($this->listing('markdown', 'seller_markdown', '0.001'), ['nothing' => 'here']);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('not_settled', $this->logged());
  }

  /**
   * Tests that markdown refuses private addresses and is not charged.
   */
  public function testMarkdownRefusesPrivateAddressesAndIsNotCharged(): void {
    $response = $this->buy($this->listing('markdown', 'seller_markdown', '0.001'), ['url' => 'http://127.0.0.1/admin']);
    $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    $this->assertSame('not_settled', $this->logged());
  }

  /**
   * Tests that a seller without its model is not charged.
   */
  public function testSellerWithoutItsModelIsNotCharged(): void {
    $response = $this->buy($this->listing('terms', 'seller_ping', '0.01'), ['text' => 'You agree to binding arbitration.']);
    $this->assertSame(503, $response->getStatusCode(), $response->getContent());
    $this->assertSame('not_settled', $this->logged());
  }

  /**
   * Tests that the people page is free.
   */
  public function testThePeoplePageIsFree(): void {
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $request = Request::create('/product/' . $listing->id());
    $request->headers->set('Accept', '*/*');
    $this->assertSame(200, $this->container->get('http_kernel')->handle($request)->getStatusCode());
  }

  /**
   * Tests that the page is free as markdown too and the service still sold.
   */
  public function testThePageIsFreeAsMarkdownTooAndTheServiceStillSold(): void {
    $listing = $this->listing('ping', 'seller_ping', '0.0001');
    $request = Request::create('/product/' . $listing->id());
    $request->headers->set('Accept', 'text/markdown');
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
    // Under the line that says how to keep reading as markdown.
    $this->assertMatchesRegularExpression('/^> .+\n\n# Ping/', (string) $response->getContent());
    // What it sells is the call, at its x402 URL.
    $this->assertNotEmpty($this->required($listing)['accepts']);
  }

  /**
   * Tests that an mcp call is paid in band and never free.
   */
  public function testAnMcpCallIsPaidInBandAndNeverFree(): void {
    $ping = $this->listing('ping', 'seller_ping', '0.0001');
    // Before the payments service is built: it keeps the facilitators it got.
    $this->facilitatorSaysYes();
    $ran = 0;
    $handler = $this->mcpHandler($ran);

    // Unpaid: the PaymentRequired, and the seller never ran.
    $required = $this->mcpCall($ping, $handler);
    $this->assertTrue($required['isError']);
    $this->assertSame(0, $ran);
    $accepted = $required['structuredContent']['accepts'][0];
    $this->assertSame((new Signer(self::PING_KEY))->address(), $accepted['payTo']);
    $this->assertSame('100', $accepted['amount']);

    // Paid: delivered, with the receipt, logged as a sale of the listing.
    $payment = (new EvmExact(new Signer(self::BUYER_KEY)))->pay($required['structuredContent'], $accepted);
    $paid = $this->mcpCall($ping, $handler, [X402McpMiddleware::PAYMENT => $payment]);
    $this->assertArrayNotHasKey('isError', $paid, json_encode($paid));
    $this->assertSame((new Signer(self::PING_KEY))->address(), json_decode($paid['content'][0]['text'], TRUE)['signer']);
    $this->assertTrue($paid['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    $this->assertSame('settled', $this->logged());
    $this->assertSame((string) $ping->id(), \Drupal::database()->select('x402_pay_log', 'l')->fields('l', ['entity_id'])->execute()->fetchField());

    // The same payment buys one delivery.
    $this->assertTrue($this->mcpCall($ping, $handler, [X402McpMiddleware::PAYMENT => $payment])['isError']);
    $this->assertSame(1, $ran, 'Unpaid and replayed calls did not run the seller; only the paid one did.');

    // Without the middleware (another transport) a priced listing sells nothing.
    $tool = $this->container->get('plugin.manager.mcp_server.tool')->createInstance(McpPayments::TOOL);
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Payment required.');
    $tool->execute(['id' => (int) $ping->id()], $this->createStub(ClientGateway::class));
  }

  /**
   * Past the site's deadline, or on a settle answer that is not a real TRUE,
   * the buyer is not charged and gets nothing.
   */
  public function testAnMcpCallIsNotChargedPastTheDeadlineNorOnSettleThatDoesNotAddUp(): void {
    foreach ([
      'deadline' => [['success' => TRUE, 'transaction' => '0x' . str_repeat('ab', 32)], 'deadline'],
      'string false' => [['success' => 'false', 'errorReason' => 'insufficient_funds'], 'invalid_settle_response'],
      'refused' => [
        ['success' => FALSE, 'errorReason' => 'insufficient_funds', 'transaction' => ''],
        'insufficient_funds',
      ],
    ] as $case => [$settle, $reason]) {
      $this->setSetting('x402_pay_deadline', $case === 'deadline' ? 0.000001 : NULL);
      $this->container->get('kernel')->rebuildContainer();
      $ping = $this->listing('ping', 'seller_ping', '0.0001');
      $this->facilitatorSaysYes($settle);
      $ran = 0;
      $handler = $this->mcpHandler($ran);
      $required = $this->mcpCall($ping, $handler)['structuredContent'];
      $payment = (new EvmExact(new Signer(self::BUYER_KEY)))->pay($required, $required['accepts'][0]);
      $result = $this->mcpCall($ping, $handler, [X402McpMiddleware::PAYMENT => $payment]);
      $this->assertTrue($result['isError'], $case);
      $this->assertSame($reason, $result['structuredContent']['error'], $case);
      $this->assertStringNotContainsString('signer', json_encode($result), "$case: the content is withheld");
    }
  }

  /**
   * Tests that malformed mcp params are not an error.
   */
  public function testMalformedMcpParamsAreNotAnError(): void {
    $this->facilitatorSaysYes();
    $ran = 0;
    foreach (['x', 7, NULL] as $params) {
      $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]);
      $response = $this->container->get('ombu.mcp_payments')->process(new ServerRequest('POST', '/mcp', [], $body), $this->mcpHandler($ran));
      $this->assertSame(200, $response->getStatusCode());
    }
  }

  /**
   * What the SDK does with a tools/call: runs the tool, wraps the answer.
   */
  private function mcpHandler(int &$ran): RequestHandlerInterface {
    $tool = $this->container->get('plugin.manager.mcp_server.tool')->createInstance(McpPayments::TOOL);
    return new class($tool, $this->createStub(ClientGateway::class), $ran) implements RequestHandlerInterface {

      public function __construct(private $tool, private $gateway, private int &$ran) {}

      /**
       * Handle.
       */
      public function handle(ServerRequestInterface $request): ResponseInterface {
        $message = json_decode((string) $request->getBody(), TRUE);
        $this->ran++;
        try {
          $result = [
            'content' => [
              [
                'type' => 'text',
                'text' => json_encode($this->tool->execute($message['params']['arguments'] ?? [], $this->gateway)),
              ],
            ],
          ];
        }
        catch (\Throwable $e) {
          $result = ['isError' => TRUE, 'content' => [['type' => 'text', 'text' => $e->getMessage()]]];
        }
        return new PsrResponse(200, [
          'Content-Type' => 'application/json',
        ], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'] ?? 1, 'result' => $result]));
      }

    };
  }

  /**
   * Mcp call.
   */
  private function mcpCall(Product $listing, RequestHandlerInterface $handler, array $meta = []): array {
    $body = json_encode([
      'jsonrpc' => '2.0',
      'id' => 7,
      'method' => 'tools/call',
      'params' => ['name' => McpPayments::TOOL, 'arguments' => ['id' => (int) $listing->id(), 'input' => []]] + ($meta ? ['_meta' => $meta] : []),
    ]);
    $response = $this->container->get('ombu.mcp_payments')->process(new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $body), $handler);
    return json_decode((string) $response->getBody(), TRUE)['result'];
  }

  /**
   * Listing.
   */
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

  /**
   * Required.
   */
  private function required(Product $listing): array {
    $response = $this->container->get('http_kernel')->handle(Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: '{}'));
    $this->assertSame(402, $response->getStatusCode(), $response->getContent());
    return PaymentHeaders::decode($response->headers->get(PaymentHeaders::REQUIRED));
  }

  /**
   * A paid POST with the service's input; the facilitator says yes.
   */
  private function buy(Product $listing, array $input): Response {
    $this->facilitatorSaysYes();

    // Signed for real: the library checks it offline before the facilitator.
    $required = $this->required($listing);
    $accepted = $required['accepts'][0];
    $request = Request::create("/x402/commerce_product/{$listing->id()}/full", 'POST', content: json_encode($input));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set(PaymentHeaders::SIGNATURE, PaymentHeaders::encode((new EvmExact(new Signer(self::BUYER_KEY)))->pay($required, $accepted)));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * A facilitator that verifies and settles whatever it is given.
   */
  private function facilitatorSaysYes(array $settle = []): void {
    $facilitator = $this->createMock(Facilitator::class);
    $facilitator->method('verify')->willReturn([
      'isValid' => TRUE,
      'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
    ]);
    $facilitator->method('settle')->willReturn($settle + [
      'success' => TRUE,
      'transaction' => '0x' . str_repeat('ab', 32),
      'network' => 'eip155:5042002',
      'payer' => '0x857b06519E91e3A54538791bDbb0E22373e36b66',
    ]);
    $facilitators = $this->createMock(Facilitators::class);
    $facilitators->method('for')->willReturn($facilitator);
    $this->container->set('x402_pay.facilitators', $facilitators);
  }

  /**
   * Logged.
   */
  private function logged(): string {
    return (string) \Drupal::database()->select('x402_pay_log', 'l')->fields('l', ['status'])->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
  }

}
