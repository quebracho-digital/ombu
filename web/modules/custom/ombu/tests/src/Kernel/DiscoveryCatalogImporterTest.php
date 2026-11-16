<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\ombu\CatalogImporterInterface;
use Drupal\ombu\DiscoveryCatalogImporter;
use Drupal\ombu\ResourceVerifier;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Bazaar and PayAI importers: they list only what Ombú verified itself.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class DiscoveryCatalogImporterTest extends CommerceKernelTestBase {

  private const USDC = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
  private const PAY_TO = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck',
  ];

  /**
   * The resources the verifier was asked about.
   *
   * @var string[]
   */
  private array $asked = [];

  /**
   * The wallets each resource was verified against, by URL.
   *
   * @var array<string, string[]>
   */
  private array $payees = [];

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['commerce_product', 'comment', 'ombu']);
  }

  /**
   * Accept.
   */
  private function accept(array $override = []): array {
    return $override + [
      'scheme' => 'exact',
      'network' => 'eip155:8453',
      'asset' => self::USDC,
      'payTo' => self::PAY_TO,
      'amount' => '5000',
    ];
  }

  /**
   * Item.
   */
  private function item(string $path, array $override = []): array {
    return $override + [
      'resource' => "https://seller.example$path",
      'type' => 'http',
      'description' => "Service $path",
      'serviceName' => 'Seller',
      'tags' => ['quote', 'pricing'],
      'lastUpdated' => '2026-11-08T20:00:00Z',
      'quality' => ['l30DaysTotalCalls' => 10],
      'accepts' => [$this->accept()],
      'extensions' => [
        'bazaar' => ['info' => ['input' => ['type' => 'http', 'method' => 'GET'], 'output' => ['type' => 'json']]],
      ],
    ];
  }

  /**
   * A verifier answering by path: fn(path) => [verdict, activity, accepts?].
   */
  private function verifier(\Closure $answer): ResourceVerifier {
    $verifier = $this->createMock(ResourceVerifier::class);
    $verifier->method('verify')->willReturnCallback(function (string $url, string $method, array $announced, array $assets, array $payees = []) use ($answer): array {
      $this->asked[] = $url;
      $this->payees[$url] = $payees;
      $this->assertContains('eip155:8453|' . strtolower(self::USDC), $assets, 'it asks only for tokens it can verify');
      [$verdict, $activity, $checks, $live] = $answer(parse_url($url, PHP_URL_PATH)) + [2 => [], 3 => NULL];
      return ['verdict' => $verdict, 'checks' => $checks, 'accepts' => $live ?? $announced, 'activity' => $activity];
    });
    return $verifier;
  }

  /**
   * Importer.
   */
  private function importer(array $items, \Closure $answer, string $source = 'bazaar', int $budget = 200, int $min = 1, ?array $pages = NULL): DiscoveryCatalogImporter {
    $pages ??= [$items];
    $total = array_sum(array_map('count', $pages));
    $responses = array_map(fn($p) => new Response(200, [], json_encode([
      'items' => $p,
      'pagination' => ['total' => $total],
    ])), $pages);
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    return new DiscoveryCatalogImporter($source, 'https://catalogue.example/resources', $client, $this->container->get('entity_type.manager'), $this->container->get('logger.factory'), $this->container->get('lock'), $this->verifier($answer), $this->container->get('state'), $this->container->get('datetime.time'), $budget, $min);
  }

  /**
   * Listing.
   */
  private function listing(string $path): ?ProductInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_endpoint', "https://seller.example$path")->execute();
    return $ids ? Product::load(reset($ids)) : NULL;
  }

  /**
   * Tests that it is an importer of its own source.
   */
  public function testItIsAnImporterOfItsOwnSource(): void {
    $importer = $this->importer([], fn() => ['pass', 5]);
    $this->assertInstanceOf(CatalogImporterInterface::class, $importer);
    $this->assertSame('bazaar', $importer->source());
  }

  /**
   * Tests that a verified active resource becomes a published listing.
   */
  public function testVerifiedActiveResourceBecomesPublishedListing(): void {
    $counts = $this->importer([$this->item('/quote')], fn() => ['pass', 12])->import();
    $this->assertSame(1, $counts['created']);
    $listing = $this->listing('/quote');
    $this->assertTrue((bool) $listing->isPublished());
    $this->assertSame('bazaar', $listing->get('field_source')->value);
    $this->assertSame(['bazaar'], array_column($listing->get('field_markets')->getValue(), 'value'));
    $this->assertSame(self::PAY_TO, $listing->get('field_pay_to')->value);
    $this->assertSame('GET', $listing->get('field_http_method')->value);
    $this->assertSame('Seller', $listing->get('field_provider_term')->entity->label());
    $this->assertSame(0.005, (float) $listing->get('variations')->entity->getPrice()->getNumber());
    $this->assertSame('listing_tag', $listing->get('field_tags')->entity->bundle());
  }

  /**
   * Tests that a resource without activity on chain is not listed.
   */
  public function testResourceWithoutActivityOnChainIsNotListed(): void {
    $counts = $this->importer([$this->item('/quiet')], fn() => ['pass', 0])->import();
    $this->assertSame(1, $counts['inactive']);
    $this->assertNull($this->listing('/quiet'));
  }

  /**
   * Tests that the minimum activity is a setting.
   */
  public function testTheMinimumActivityIsSetting(): void {
    $counts = $this->importer([$this->item('/a')], fn() => ['pass', 4], 'bazaar', 200, 5)->import();
    $this->assertSame(1, $counts['inactive']);
  }

  /**
   * Tests that a failed check is not listed and is counted.
   */
  public function testFailedCheckIsNotListedAndIsCounted(): void {
    $fail = fn() => [
      'fail',
      9,
      ['accepts[0]' => ['fail', 'differs from the catalogue (network, asset, payTo or amount)']],
    ];
    $counts = $this->importer([$this->item('/lie')], $fail)->import();
    $this->assertSame(1, $counts['rejected']);
    $this->assertNull($this->listing('/lie'));
    $this->assertSame(['accepts: differs from the catalogue' => 1], $counts['reasons']);
  }

  /**
   * Tests that what cannot be asked is not listed.
   */
  public function testWhatCannotBeAskedIsNotListed(): void {
    $counts = $this->importer([$this->item('/dark')], fn() => ['unchecked', NULL])->import();
    $this->assertSame(1, $counts['unverified']);
    $this->assertNull($this->listing('/dark'));
  }

  /**
   * Tests that a resource only paid where ombu cannot verify is not asked.
   */
  public function testResourceOnlyPaidWhereOmbuCannotVerifyIsNotAsked(): void {
    $solana = $this->item('/sol', ['accepts' => [$this->accept(['network' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'])]]);
    $counts = $this->importer([$solana], fn() => ['pass', 9])->import();
    $this->assertSame(1, $counts['unsupported']);
    $this->assertSame([], $this->asked);
  }

  /**
   * Tests that an unchanged entry is not asked again but a changed one is.
   */
  public function testAnUnchangedEntryIsNotAskedAgainButChangedOneIs(): void {
    $this->importer([$this->item('/quote')], fn() => ['pass', 3])->import();
    $this->asked = [];
    $again = $this->item('/quote', ['quality' => ['l30DaysTotalCalls' => 99], 'lastUpdated' => '2027-01-01T00:00:00Z']);
    $counts = $this->importer([$again], fn() => ['pass', 3])->import();
    $this->assertSame(1, $counts['unchanged'], 'quality counters and the clock are not a change');
    $this->assertSame([], $this->asked);

    $changed = $this->item('/quote', ['accepts' => [$this->accept(['amount' => '9000'])]]);
    $counts = $this->importer([$changed], fn() => ['pass', 3])->import();
    $this->assertSame(1, $counts['updated']);
    $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->resetCache();
    $this->assertSame(0.009, (float) $this->listing('/quote')->get('variations')->entity->getPrice()->getNumber());
  }

  /**
   * Tests that the budget limits how many are verified and nothing is retired for it.
   */
  public function testTheBudgetLimitsHowManyAreVerifiedAndNothingIsRetiredForIt(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $items = [$this->item('/a', ['description' => 'changed']), $this->item('/b'), $this->item('/c')];
    $counts = $this->importer($items, fn() => ['pass', 3], 'bazaar', 1)->import();
    $this->assertSame(1, $counts['updated'] + $counts['created']);
    $this->assertSame(2, $counts['deferred']);
    $this->assertSame(0, $counts['retired']);
    $this->assertCount(1, $this->asked ? array_slice($this->asked, 1) : [], 'one more resource asked');
  }

  /**
   * Tests that a listing the catalogue no longer answers for is retired not deleted.
   */
  public function testListingTheCatalogueNoLongerAnswersForIsRetiredNotDeleted(): void {
    $this->importer([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3])->import();
    $counts = $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->assertSame(1, $counts['retired']);
    $this->assertTrue((bool) $this->listing('/b')->get('field_retired')->value);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a listing that fails the checks now is retired.
   */
  public function testListingThatFailsTheChecksNowIsRetired(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $changed = $this->item('/a', ['description' => 'new text']);
    $counts = $this->importer([$changed], fn() => ['fail', 3, ['accepts[0].ofac' => ['fail', 'sanctioned (OFAC SDN)']]])->import();
    $this->assertSame(1, $counts['rejected']);
    $this->assertSame(1, $counts['retired']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a listing is asked again against the wallets it declared.
   *
   * A catalogue entry that now names another wallet (the seller's, or someone
   * in the middle) is for the verifier to refuse: it gets the wallets the
   * listing came in with. A new resource has none to compare.
   */
  public function testAnExistingListingIsCheckedAgainstTheWalletsItDeclared(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->assertSame([], $this->payees['https://seller.example/a'], 'A new resource declares its wallet now.');
    $other = '0x' . str_repeat('2', 40);
    $changed = $this->item('/a', ['accepts' => [$this->accept(['payTo' => $other])]]);
    $counts = $this->importer([$changed], fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']]])->import();
    $this->assertSame([strtolower(self::PAY_TO)], array_map('strtolower', $this->payees['https://seller.example/a']));
    $this->assertSame(1, $counts['rejected']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
    $this->assertSame(strtolower(self::PAY_TO), strtolower($this->listing('/a')->get('field_pay_to')->value), 'The wallet on file is not replaced.');
  }

  /**
   * Tests that an unchanged entry is asked again once its check is old.
   *
   * The catalogue may say the same for months while the seller's own answer
   * changes (another wallet): the check has a maximum age.
   */
  public function testAnUnchangedEntryIsAskedAgainWhenItsCheckIsOld(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->asked = [];
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->assertSame([], $this->asked, 'Checked a moment ago: not asked.');

    $state = $this->container->get('state');
    $checked = $state->get('ombu.catalog.checked.bazaar');
    $this->assertCount(1, $checked);
    $state->set('ombu.catalog.checked.bazaar', array_map(fn() => time() - DiscoveryCatalogImporter::MAX_AGE - 60, $checked));
    $other = '0x' . str_repeat('2', 40);
    $counts = $this->importer([$this->item('/a')], fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']], [$this->accept(['payTo' => $other])]])->import();
    $this->assertSame(['https://seller.example/a'], $this->asked, 'Old check: asked again, though the catalogue says the same.');
    $this->assertSame([strtolower(self::PAY_TO)], array_map('strtolower', $this->payees['https://seller.example/a']));
    $this->assertSame(1, $counts['rejected']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
    $changes = $state->get('ombu.catalog.wallet_changed.bazaar');
    $this->assertSame([(int) $this->listing('/a')->id()], array_keys($changes), 'Staff can see whose wallet changed.');
  }

  /**
   * Tests that staff can accept a seller's new wallet: same listing, same id.
   *
   * A listing is its catalogue, its URL and its method, never its wallet: the
   * id, the SKU and the reviews stay.
   */
  public function testStaffAcceptTheNewWalletAndTheListingStaysTheSame(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $id = $this->listing('/a')->id();
    $sku = $this->listing('/a')->get('variations')->entity->getSku();
    $other = '0x' . str_repeat('2', 40);
    $moved = $this->item('/a', ['accepts' => [$this->accept(['payTo' => $other])]]);
    $refuse = fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']]];
    $this->importer([$moved], $refuse)->import();
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);

    // A check that fails for another reason accepts nothing.
    $importer = $this->importer([], fn() => ['fail', 3, ['accepts[0].ofac' => ['fail', 'sanctioned (OFAC SDN)']], [$this->accept(['payTo' => $other])]]);
    $this->assertSame('fail', $importer->acceptWallet($this->listing('/a'))['verdict']);
    $this->assertSame(strtolower(self::PAY_TO), strtolower($this->listing('/a')->get('field_pay_to')->value));

    $live = fn() => ['pass', 3, [], [$this->accept(['payTo' => $other])]];
    $report = $this->importer([], $live)->acceptWallet($this->listing('/a'));
    $this->assertSame('pass', $report['verdict']);
    $this->assertSame([], $this->payees['https://seller.example/a'], 'Asked with no wallet on file: that is what is being replaced.');
    $this->assertSame($other, $this->listing('/a')->get('field_pay_to')->value);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value, 'Retired until the next run compares it with the catalogue.');
    $this->assertSame([], $this->container->get('state')->get('ombu.catalog.wallet_changed.bazaar'));

    $counts = $this->importer([$moved], fn() => ['pass', 3])->import();
    $this->assertSame(1, $counts['updated']);
    $this->assertSame([strtolower($other)], array_map('strtolower', $this->payees['https://seller.example/a']), 'The new wallet is the one on file now.');
    $listing = $this->listing('/a');
    $this->assertFalse((bool) $listing->get('field_retired')->value);
    $this->assertSame($id, $listing->id());
    $this->assertSame($sku, $listing->get('variations')->entity->getSku());
  }

  /**
   * Tests that a listing is not retired when the check could not be asked.
   */
  public function testListingIsNotRetiredWhenTheCheckCouldNotBeAsked(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $changed = $this->item('/a', ['description' => 'new text']);
    $counts = $this->importer([$changed], fn() => ['unchecked', NULL])->import();
    $this->assertSame(1, $counts['unverified']);
    $this->assertSame(0, $counts['retired']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a partial run retires nothing.
   */
  public function testPartialRunRetiresNothing(): void {
    $this->importer([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3])->import();
    $counts = $this->importer([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3])->import(1);
    $this->assertSame(0, $counts['retired']);
  }

  /**
   * Tests that it never touches another sources listing.
   */
  public function testItNeverTouchesAnotherSourcesListing(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3], 'payai')->import();
    $counts = $this->importer([], fn() => ['pass', 3], 'bazaar')->import();
    $this->assertSame(0, $counts['retired']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
    $counts = $this->importer([$this->item('/a')], fn() => ['pass', 3], 'bazaar')->import();
    $this->assertSame(1, $counts['exists']);
    $this->assertSame('payai', $this->listing('/a')->get('field_source')->value);
  }

  /**
   * Tests that a deleted listing stays deleted.
   */
  public function testDeletedListingStaysDeleted(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $listing = $this->listing('/a');
    DiscoveryCatalogImporter::block($listing);
    $listing->delete();
    $counts = $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->assertSame(1, $counts['blocked']);
    $this->assertNull($this->listing('/a'));
  }

  /**
   * Tests that it reads every page of the catalogue.
   */
  public function testItReadsEveryPageOfTheCatalogue(): void {
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    $page2 = [$this->item('/last')];
    $counts = $this->importer([], fn() => ['pass', 3], 'bazaar', 0, 1, [$page1, $page2])->import();
    $this->assertSame(101, $counts['created']);
  }

  /**
   */
  public function testBothCataloguesShapesBecomeOne(): void {
    $bazaar = DiscoveryCatalogImporter::normalize($this->item('/q'));
    $this->assertSame('GET https://seller.example/q', $bazaar['key']);
    $payai = DiscoveryCatalogImporter::normalize([
      'resource' => 'https://pay.example/chat',
      'method' => 'POST',
      'description' => 'AI inference',
      'serviceName' => NULL,
      'tags' => NULL,
      'accepts' => [$this->accept()],
      'inputSchema' => ['body' => ['model' => 'x']],
    ]);
    $this->assertSame('POST https://pay.example/chat', $payai['key']);
    $this->assertSame('pay.example', $payai['provider']);
    $this->assertSame([], $payai['tags']);
    $this->assertSame(['body' => ['model' => 'x']], $payai['input']);
    $this->assertNull(DiscoveryCatalogImporter::normalize([
      'resource' => 'http://plain.example/x',
      'accepts' => [$this->accept()],
    ]), 'https only');
    $this->assertNull(DiscoveryCatalogImporter::normalize(['resource' => 'https://x.example/y', 'accepts' => []]));
  }

  /**
   * A broken answer from the catalogue retires nothing.
   */
  public function testABrokenCatalogueAnswerRetiresNothing(): void {
    $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    foreach (['{}', 'not json', '{"items":"x"}'] as $body) {
      $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
      $importer = new DiscoveryCatalogImporter('bazaar', 'https://catalogue.example/resources', $client, $this->container->get('entity_type.manager'), $this->container->get('logger.factory'), $this->container->get('lock'), $this->verifier(fn() => ['pass', 3]), $this->container->get('state'), $this->container->get('datetime.time'));
      try {
        $importer->import();
        $this->fail("Answer $body was taken for a catalogue.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('did not answer with a list', $e->getMessage());
      }
      $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
    }
  }

  /**
   * A catalogue that suddenly lists less than half is not trusted to retire.
   */
  public function testACatalogueThatShrinksByHalfRetiresNothing(): void {
    $items = [$this->item('/a'), $this->item('/b'), $this->item('/c'), $this->item('/d')];
    $this->importer($items, fn() => ['pass', 3])->import();
    $counts = $this->importer([$this->item('/a')], fn() => ['pass', 3])->import();
    $this->assertSame(0, $counts['retired']);
    $this->assertArrayHasKey('retire_skipped', $counts);
    $this->assertFalse((bool) $this->listing('/d')->get('field_retired')->value);
  }

}
