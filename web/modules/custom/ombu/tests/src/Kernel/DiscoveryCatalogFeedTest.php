<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\feeds\Entity\FeedType;
use Drupal\key\Entity\Key;
use Drupal\ombu\Catalog\CatalogRun;
use Drupal\ombu\DiscoveryCatalogImporter;
use Drupal\ombu\Feeds\Processor\DiscoveryListingProcessor;
use Drupal\ombu\ResourceVerifier;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Bazaar and PayAI feeds: every check of the importer, on Feeds.
 *
 * Each test of DiscoveryCatalogImporterTest has its twin here, run through
 * the feed (CatalogFeeds::import(), Feeds' own import), and one test runs
 * the same fixtures through the importer and the feed and compares what
 * each left: the counts of every run and every listing's fields.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class DiscoveryCatalogFeedTest extends CommerceKernelTestBase {

  private const USDC = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
  private const PAY_TO = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck', 'feeds',
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
   * The requests the catalogue was asked, as query strings.
   *
   * @var string[]
   */
  private array $pages = [];

  /**
   * The headers of each request to the catalogue.
   *
   * @var array<int, array<string, string[]>>
   */
  private array $headers = [];

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('feeds_feed');
    $this->installEntitySchema('feeds_subscription');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('feeds', ['feeds_clean_list']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['commerce_product', 'comment', 'feeds', 'ombu']);
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
   * A verifier answering by path: fn(path) => [verdict, activity, checks, accepts].
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
   * The catalogue's answers: one response per page, as the importer's test.
   */
  private function client(array $pages): Client {
    // A raw answer (a broken page) counts as one more resource in the total.
    $total = array_sum(array_map(fn($p) => is_array($p) ? count($p) : 1, $pages));
    $responses = array_map(fn($p) => is_string($p) ? new Response(200, [], $p) : new Response(200, [], json_encode([
      'items' => $p,
      'pagination' => ['total' => $total],
    ])), $pages);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(function (callable $handler) {
      return function ($request, array $options) use ($handler) {
        $this->pages[] = $request->getUri()->getQuery();
        $this->headers[] = $request->getHeaders();
        return $handler($request, $options);
      };
    });
    return new Client(['handler' => $stack]);
  }

  /**
   * Sets the processor's settings of a source's feed type.
   */
  private function configure(string $source, array $settings): void {
    $type = FeedType::load("ombu_$source");
    $type->set('processor_configuration', $settings + $type->getProcessor()->getConfiguration())->save();
  }

  /**
   * Runs the feed of a source over these pages; returns its counts.
   */
  private function feed(array $items, \Closure $answer, string $source = 'bazaar', int $budget = 200, int $min = 1, ?array $pages = NULL, array $options = [], ?ResourceVerifier $verifier = NULL): array {
    $this->container->set('http_client', $this->client($pages ?? [$items]));
    $this->container->set('ombu.resource_verifier', $verifier ?? $this->verifier($answer));
    $this->configure($source, ['budget' => $budget, 'min_transfers' => $min]);
    return $this->container->get('ombu.catalog_feeds')->import($source, $options);
  }

  /**
   * The importer over these pages, as DiscoveryCatalogImporterTest builds it.
   */
  private function importer(array $items, \Closure $answer, string $source = 'bazaar', int $budget = 200, int $min = 1, ?array $pages = NULL, array $tags = [], ?ResourceVerifier $verifier = NULL, bool $priceMatch = FALSE, int $maxAgeDays = 0): DiscoveryCatalogImporter {
    return new DiscoveryCatalogImporter($source, 'https://catalogue.example/resources', $this->client($pages ?? [$items]), $this->container->get('entity_type.manager'), $this->container->get('logger.factory'), $this->container->get('lock'), $verifier ?? $this->verifier($answer), $this->container->get('state'), $this->container->get('datetime.time'), $budget, $min, $tags, $priceMatch, $maxAgeDays);
  }

  /**
   * Listing.
   */
  private function listing(string $path): ?ProductInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache();
    $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_endpoint', "https://seller.example$path")->execute();
    return $ids ? Product::load(reset($ids)) : NULL;
  }

  /**
   * The processor of a source's feed type.
   */
  private function processor(string $source = 'bazaar'): DiscoveryListingProcessor {
    $this->container->get('entity_type.manager')->getStorage('feeds_feed_type')->resetCache();
    return FeedType::load("ombu_$source")->getProcessor();
  }

  /**
   * The feed types ship with the module, one per catalogue.
   */
  public function testEachCatalogueHasItsFeedType(): void {
    foreach (['bazaar' => 'https://api.cdp.coinbase.com/platform/v2/x402/discovery/resources', 'payai' => 'https://facilitator.payai.network/discovery/resources'] as $source => $url) {
      $type = FeedType::load("ombu_$source");
      $this->assertSame('ombu_catalog', $type->getFetcher()->getPluginId());
      $this->assertSame($url, $type->getFetcher()->getConfiguration('url'));
      $this->assertSame('ombu_discovery', $type->getParser()->getPluginId());
      $this->assertSame($source, $this->processor($source)->source());
      $this->assertSame(200, $this->processor($source)->getConfiguration('budget'));
      $this->assertSame(-1, $type->getImportPeriod(), 'Not scheduled: by hand, as the importer.');
    }
    $feed = $this->container->get('ombu.catalog_feeds')->feed('bazaar');
    $this->assertSame($feed->id(), $this->container->get('ombu.catalog_feeds')->feed('bazaar')->id(), 'One feed per catalogue.');
  }

  /**
   * Tests that a verified active resource becomes a published listing.
   */
  public function testVerifiedActiveResourceBecomesPublishedListing(): void {
    $counts = $this->feed([$this->item('/quote')], fn() => ['pass', 12]);
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
    $this->assertSame('limit=100&offset=0', $this->pages[0], 'Pages of 100, from the start.');
  }

  /**
   * Tests that a resource without activity on chain is not listed.
   */
  public function testResourceWithoutActivityOnChainIsNotListed(): void {
    $counts = $this->feed([$this->item('/quiet')], fn() => ['pass', 0]);
    $this->assertSame(1, $counts['inactive']);
    $this->assertNull($this->listing('/quiet'));
  }

  /**
   * Tests that the minimum activity is a setting.
   */
  public function testTheMinimumActivityIsSetting(): void {
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 4], 'bazaar', 200, 5);
    $this->assertSame(1, $counts['inactive']);
  }

  /**
   * Tests that the reasons a run keeps are capped.
   */
  public function testTheReasonsOfARunAreCapped(): void {
    $run = CatalogRun::create($this->container, 0);
    for ($i = 0; $i < CatalogRun::REASONS + 10; $i++) {
      $run->reason("reason $i");
    }
    $this->assertCount(CatalogRun::REASONS + 1, $run->reasons);
    $this->assertSame(10, $run->reasons['other']);
  }

  /**
   * Tests that a failed check is not listed and is counted.
   */
  public function testFailedCheckIsNotListedAndIsCounted(): void {
    $fail = fn() => ['fail', 9, ['accepts[0]' => ['fail', 'differs from the catalogue (network, asset, payTo or amount)']]];
    $counts = $this->feed([$this->item('/lie')], $fail);
    $this->assertSame(1, $counts['rejected']);
    $this->assertNull($this->listing('/lie'));
    $this->assertSame(['accepts: differs from the catalogue' => 1], $counts['reasons']);
  }

  /**
   * Tests that what cannot be asked is not listed.
   */
  public function testWhatCannotBeAskedIsNotListed(): void {
    $counts = $this->feed([$this->item('/dark')], fn() => ['unchecked', NULL]);
    $this->assertSame(1, $counts['unverified']);
    $this->assertNull($this->listing('/dark'));
  }

  /**
   * Tests that a resource only paid where ombu cannot verify is not asked.
   */
  public function testResourceOnlyPaidWhereOmbuCannotVerifyIsNotAsked(): void {
    $solana = $this->item('/sol', ['accepts' => [$this->accept(['network' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'])]]);
    $counts = $this->feed([$solana], fn() => ['pass', 9]);
    $this->assertSame(1, $counts['unsupported']);
    $this->assertSame([], $this->asked);
  }

  /**
   * Tests that an unchanged entry is not asked again but a changed one is.
   */
  public function testAnUnchangedEntryIsNotAskedAgainButChangedOneIs(): void {
    $this->feed([$this->item('/quote')], fn() => ['pass', 3]);
    $this->asked = [];
    $again = $this->item('/quote', ['quality' => ['l30DaysTotalCalls' => 99], 'lastUpdated' => '2027-01-01T00:00:00Z']);
    $counts = $this->feed([$again], fn() => ['pass', 3]);
    $this->assertSame(1, $counts['unchanged'], 'quality counters and the clock are not a change');
    $this->assertSame([], $this->asked);

    $changed = $this->item('/quote', ['accepts' => [$this->accept(['amount' => '9000'])]]);
    $counts = $this->feed([$changed], fn() => ['pass', 3]);
    $this->assertSame(1, $counts['updated']);
    $this->assertSame(0.009, (float) $this->listing('/quote')->get('variations')->entity->getPrice()->getNumber());
  }

  /**
   * Tests that the budget limits how many are verified and nothing is retired for it.
   */
  public function testTheBudgetLimitsHowManyAreVerifiedAndNothingIsRetiredForIt(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $items = [$this->item('/a', ['description' => 'changed']), $this->item('/b'), $this->item('/c')];
    $counts = $this->feed($items, fn() => ['pass', 3], 'bazaar', 1);
    $this->assertSame(1, $counts['updated'] + $counts['created']);
    $this->assertSame(2, $counts['deferred']);
    $this->assertSame(0, $counts['retired']);
    $this->assertCount(1, array_slice($this->asked, 1), 'one more resource asked');
  }

  /**
   * Tests that a listing the catalogue no longer answers for is retired not deleted.
   */
  public function testListingTheCatalogueNoLongerAnswersForIsRetiredNotDeleted(): void {
    $this->feed([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3]);
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame(1, $counts['retired']);
    $this->assertTrue((bool) $this->listing('/b')->get('field_retired')->value);
    $this->assertTrue($this->listing('/b')->isPublished(), 'Still published: its page and reviews stay.');
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a listing that fails the checks now is retired.
   */
  public function testListingThatFailsTheChecksNowIsRetired(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $changed = $this->item('/a', ['description' => 'new text']);
    $counts = $this->feed([$changed], fn() => ['fail', 3, ['accepts[0].ofac' => ['fail', 'sanctioned (OFAC SDN)']]]);
    $this->assertSame(1, $counts['rejected']);
    $this->assertSame(1, $counts['retired']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a listing is asked again against the wallets it declared.
   */
  public function testAnExistingListingIsCheckedAgainstTheWalletsItDeclared(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame([], $this->payees['https://seller.example/a'], 'A new resource declares its wallet now.');
    $other = '0x' . str_repeat('2', 40);
    $changed = $this->item('/a', ['accepts' => [$this->accept(['payTo' => $other])]]);
    $counts = $this->feed([$changed], fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']]]);
    $this->assertSame([strtolower(self::PAY_TO)], array_map('strtolower', $this->payees['https://seller.example/a']));
    $this->assertSame(1, $counts['rejected']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
    $this->assertSame(strtolower(self::PAY_TO), strtolower($this->listing('/a')->get('field_pay_to')->value), 'The wallet on file is not replaced.');
  }

  /**
   * Tests that one resource listed with two wallets lists neither.
   *
   * The catalogue repeats the resource with another wallet, first: keeping
   * the first would hand a seller's resource to the other wallet, and the
   * second alone would retire the seller's listing. Neither entry lists, and
   * the listing the seller already had stands.
   */
  public function testOneResourceWithTwoWalletsListsNeither(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $before = $this->listing('/a');

    $other = $this->item('/a', ['accepts' => [$this->accept(['payTo' => '0x' . str_repeat('2', 40)])]]);
    $counts = $this->feed([$other, $this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame(0, $counts['created'] + $counts['updated']);
    $this->assertSame(1, $counts['skipped'], 'The conflict is counted once.');
    $this->assertSame(0, $counts['retired']);
    $kept = $this->listing('/a');
    $this->assertSame((int) $before->id(), (int) $kept->id());
    $this->assertSame(self::PAY_TO, $kept->get('field_pay_to')->value);
    $this->assertFalse((bool) $kept->get('field_retired')->value);
  }

  /**
   * Tests that a conflict on a later page takes down what the run wrote.
   *
   * The entries are on different pages: the first is written before the run
   * reads the second, so the marker takes it down again.
   */
  public function testAConflictOnALaterPageTakesDownWhatTheRunWrote(): void {
    $other = $this->item('/c', ['accepts' => [$this->accept(['payTo' => '0x' . str_repeat('2', 40)])]]);
    $counts = $this->feed([], fn() => ['pass', 3], pages: [[$other], [$this->item('/c')]]);
    $this->assertSame(1, $counts['created']);
    $this->assertSame(1, $counts['retired']);
    $listing = $this->listing('/c');
    $this->assertTrue((bool) $listing->get('field_retired')->value, 'Not on sale under either wallet.');
  }

  /**
   * Tests that an unchanged entry is asked again once its check is old.
   */
  public function testAnUnchangedEntryIsAskedAgainWhenItsCheckIsOld(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->asked = [];
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame([], $this->asked, 'Checked a moment ago: not asked.');

    $state = $this->container->get('state');
    $checked = $state->get('ombu.catalog.checked.bazaar');
    $this->assertCount(1, $checked);
    $state->set('ombu.catalog.checked.bazaar', array_map(fn() => time() - DiscoveryListingProcessor::MAX_AGE - 60, $checked));
    $other = '0x' . str_repeat('2', 40);
    $counts = $this->feed([$this->item('/a')], fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']], [$this->accept(['payTo' => $other])]]);
    $this->assertSame(['https://seller.example/a'], $this->asked, 'Old check: asked again, though the catalogue says the same.');
    $this->assertSame([strtolower(self::PAY_TO)], array_map('strtolower', $this->payees['https://seller.example/a']));
    $this->assertSame(1, $counts['rejected']);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);
    $changes = $state->get('ombu.catalog.wallet_changed.bazaar');
    $this->assertSame([(int) $this->listing('/a')->id()], array_keys($changes), 'Staff can see whose wallet changed.');
    $this->assertSame($changes, $this->processor()->walletChanges());
    $this->assertSame([$other], $changes[(int) $this->listing('/a')->id()]['new']);
  }

  /**
   * Tests that staff can accept a seller's new wallet: same listing, same id.
   */
  public function testStaffAcceptTheNewWalletAndTheListingStaysTheSame(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $id = $this->listing('/a')->id();
    $sku = $this->listing('/a')->get('variations')->entity->getSku();
    $other = '0x' . str_repeat('2', 40);
    $moved = $this->item('/a', ['accepts' => [$this->accept(['payTo' => $other])]]);
    $this->feed([$moved], fn() => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']]]);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value);

    // A check that fails for another reason accepts nothing.
    $this->container->set('ombu.resource_verifier', $this->verifier(fn() => ['fail', 3, ['accepts[0].ofac' => ['fail', 'sanctioned (OFAC SDN)']], [$this->accept(['payTo' => $other])]]));
    $this->assertSame('fail', $this->processor()->acceptWallet($this->listing('/a'))['verdict']);
    $this->assertSame(strtolower(self::PAY_TO), strtolower($this->listing('/a')->get('field_pay_to')->value));

    $this->container->set('ombu.resource_verifier', $this->verifier(fn() => ['pass', 3, [], [$this->accept(['payTo' => $other])]]));
    $report = $this->processor()->acceptWallet($this->listing('/a'));
    $this->assertSame('pass', $report['verdict']);
    $this->assertSame([], $this->payees['https://seller.example/a'], 'Asked with no wallet on file: that is what is being replaced.');
    $this->assertSame($other, $this->listing('/a')->get('field_pay_to')->value);
    $this->assertTrue((bool) $this->listing('/a')->get('field_retired')->value, 'Retired until the next run compares it with the catalogue.');
    $this->assertSame([], $this->container->get('state')->get('ombu.catalog.wallet_changed.bazaar'));

    $counts = $this->feed([$moved], fn() => ['pass', 3]);
    $this->assertSame(1, $counts['updated']);
    $this->assertSame([strtolower($other)], array_map('strtolower', $this->payees['https://seller.example/a']), 'The new wallet is the one on file now.');
    $listing = $this->listing('/a');
    $this->assertFalse((bool) $listing->get('field_retired')->value);
    $this->assertSame($id, $listing->id());
    $this->assertSame($sku, $listing->get('variations')->entity->getSku());
  }

  /**
   * A listing of another catalogue is not the feed's to accept.
   */
  public function testAcceptingWalletOfAnotherSourceIsRefused(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3], 'payai');
    $this->expectException(\InvalidArgumentException::class);
    $this->processor('bazaar')->acceptWallet($this->listing('/a'));
  }

  /**
   * Tests that a listing is not retired when the check could not be asked.
   */
  public function testListingIsNotRetiredWhenTheCheckCouldNotBeAsked(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $changed = $this->item('/a', ['description' => 'new text']);
    $counts = $this->feed([$changed], fn() => ['unchecked', NULL]);
    $this->assertSame(1, $counts['unverified']);
    $this->assertSame(0, $counts['retired']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * A verifier that throws (a node down) is `unverified`, the run goes on.
   */
  public function testVerifierThatThrowsIsUnverified(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $changed = $this->item('/a', ['description' => 'new text']);
    $verifier = $this->createMock(ResourceVerifier::class);
    $verifier->method('verify')->willThrowException(new \RuntimeException('node down'));
    $counts = $this->feed([$changed, $this->item('/b')], fn() => NULL, verifier: $verifier);
    $this->assertSame(2, $counts['unverified']);
    $this->assertSame(0, $counts['retired']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * Tests that a partial run retires nothing.
   */
  public function testPartialRunRetiresNothing(): void {
    $this->feed([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3]);
    $last = $this->container->get('state')->get('ombu.bazaar_last');
    $counts = $this->feed([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3], options: ['max' => 1]);
    $this->assertSame(0, $counts['retired']);
    $this->assertSame(1, $counts['unchanged'], 'It stopped after one.');
    $this->assertFalse((bool) $this->listing('/b')->get('field_retired')->value);
    $this->assertSame($last, $this->container->get('state')->get('ombu.bazaar_last'), 'Only a whole run records its time.');
  }

  /**
   * A run with max stops asking for pages and never retires.
   *
   * Not even when the catalogue is shorter than max.
   */
  public function testMaxStopsTheFetcher(): void {
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    $counts = $this->feed([], fn() => ['pass', 3], 'bazaar', 0, 1, [$page1, [$this->item('/last')]], ['max' => 50]);
    $this->assertSame(50, $counts['created']);
    $this->assertCount(1, $this->pages, 'The second page was never asked for.');

    $this->pages = [];
    $counts = $this->feed([$this->item('/p1')], fn() => ['pass', 3], options: ['max' => 500]);
    $this->assertSame(0, $counts['retired'], 'A run with max never retires.');
    $this->assertFalse((bool) $this->listing('/p2')->get('field_retired')->value);
  }

  /**
   * Tests that it never touches another sources listing.
   */
  public function testItNeverTouchesAnotherSourcesListing(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3], 'payai');
    $counts = $this->feed([$this->item('/z')], fn() => ['pass', 3], 'bazaar');
    $this->assertSame(0, $counts['retired']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
    $counts = $this->feed([$this->item('/a'), $this->item('/z')], fn() => ['pass', 3], 'bazaar');
    $this->assertSame(1, $counts['exists']);
    $this->assertSame('payai', $this->listing('/a')->get('field_source')->value);
  }

  /**
   * Tests that a deleted listing stays deleted.
   *
   * Deleting is enough: ListingHooks blocks it on delete, for either way of
   * importing.
   */
  public function testDeletedListingStaysDeleted(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->listing('/a')->delete();
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame(1, $counts['blocked']);
    $this->assertNull($this->listing('/a'));
  }

  /**
   * Tests that it reads every page of the catalogue.
   */
  public function testItReadsEveryPageOfTheCatalogue(): void {
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    $page2 = [$this->item('/last')];
    $counts = $this->feed([], fn() => ['pass', 3], 'bazaar', 0, 1, [$page1, $page2]);
    $this->assertSame(101, $counts['created']);
    $this->assertSame(['limit=100&offset=0', 'limit=100&offset=100'], $this->pages);
  }

  /**
   * The same resource on two pages is read once.
   */
  public function testTheSameResourceListedTwiceIsReadOnce(): void {
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    $counts = $this->feed([], fn() => ['pass', 3], 'bazaar', 0, 1, [$page1, [$this->item('/p1'), $this->item('/last')]]);
    $this->assertSame(101, $counts['created']);
    $this->assertSame(['https://seller.example/p1'], array_values(array_filter($this->asked, fn($u) => $u === 'https://seller.example/p1')));
  }

  /**
   * A broken answer from the catalogue retires nothing.
   */
  public function testBrokenCatalogueAnswerRetiresNothing(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    foreach (['{}', 'not json', '{"items":"x"}'] as $body) {
      try {
        $this->feed([], fn() => ['pass', 3], pages: [$body]);
        $this->fail("Answer $body was taken for a catalogue.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('did not answer with a list', $e->getMessage());
      }
      $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
      $this->assertFalse($this->container->get('ombu.catalog_feeds')->feed('bazaar')->isLocked(), 'The feed is unlocked after a failed run.');
    }
  }

  /**
   * A second page that breaks stops the run: nothing retired, no time kept.
   */
  public function testBrokenSecondPageRetiresNothing(): void {
    $this->feed([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3]);
    $last = $this->container->get('state')->get('ombu.bazaar_last');
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    try {
      $this->feed([], fn() => ['pass', 3], 'bazaar', 0, 1, [$page1, 'not json']);
      $this->fail('A broken second page was taken for the end of the catalogue.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('did not answer with a list', $e->getMessage());
    }
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
    $this->assertSame($last, $this->container->get('state')->get('ombu.bazaar_last'));
  }

  /**
   * A catalogue that suddenly lists less than half is not trusted to retire.
   */
  public function testCatalogueThatShrinksByHalfRetiresNothing(): void {
    $items = [$this->item('/a'), $this->item('/b'), $this->item('/c'), $this->item('/d')];
    $this->feed($items, fn() => ['pass', 3]);
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame(0, $counts['retired']);
    $this->assertSame('read 1, the run before read 4', $counts['retire_skipped']);
    $this->assertFalse((bool) $this->listing('/d')->get('field_retired')->value);
    $this->assertSame(4, $this->container->get('state')->get('ombu.bazaar_read'), 'The last trusted count stays.');

    // The catalogue says the same twice in a row: it really shrank, so what
    // it no longer lists is retired (a reference that only ever moves up
    // would keep them for ever).
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame(3, $counts['retired']);
    $this->assertSame(1, $this->container->get('state')->get('ombu.bazaar_read'));
  }

  /**
   * An empty list of items is a catalogue that reads nothing: no retirement.
   */
  public function testAnEmptyCatalogueRetiresNothing(): void {
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $counts = $this->feed([], fn() => ['pass', 3]);
    $this->assertSame(0, $counts['retired']);
    $this->assertSame('read 0, the run before read 1', $counts['retire_skipped']);
    $this->assertFalse((bool) $this->listing('/a')->get('field_retired')->value);
  }

  /**
   * The granularity: only what carries one of the tags named is asked.
   */
  public function testOnlyTagsNarrowWhatIsAsked(): void {
    $tags = ['ai', 'inference'];
    $items = [
      $this->item('/ai', ['tags' => $tags]),
      $this->item('/other', ['tags' => ['weather']]),
      $this->item('/none', ['tags' => []]),
    ];
    $counts = $this->feed($items, fn() => ['pass', 3], options: ['only_tags' => $tags]);
    $this->assertSame(1, $counts['created']);
    $this->assertSame(2, $counts['skipped']);
    $this->assertSame(['https://seller.example/ai'], $this->asked, 'Only the tagged resource was asked.');
    $this->assertNull($this->listing('/other'));
    $this->assertNull($this->listing('/none'));
  }

  /**
   * The tags can also be the feed type's setting, for every run.
   */
  public function testOnlyTagsAsTheProcessorsSetting(): void {
    $this->configure('bazaar', ['only_tags' => ['ai']]);
    $items = [$this->item('/ai', ['tags' => ['ai']]), $this->item('/other', ['tags' => ['weather']])];
    $this->container->set('http_client', $this->client([$items]));
    $this->container->set('ombu.resource_verifier', $this->verifier(fn() => ['pass', 3]));
    $counts = $this->container->get('ombu.catalog_feeds')->import('bazaar');
    $this->assertSame(1, $counts['skipped']);
    $this->assertSame(['https://seller.example/ai'], $this->asked);
  }

  /**
   * A listing already imported is not retired because a narrower run skips it.
   */
  public function testSkippedListingOfTheSourceIsNotRetired(): void {
    $this->feed([$this->item('/a'), $this->item('/b')], fn() => ['pass', 3]);
    $items = [$this->item('/a', ['tags' => ['ai']]), $this->item('/b', ['tags' => ['weather']])];
    $counts = $this->feed($items, fn() => ['pass', 3], options: ['only_tags' => ['ai']]);
    $this->assertSame(1, $counts['skipped'], '/b has none of the tags');
    $this->assertSame(0, $counts['retired'], 'A skipped listing of this source stays.');
    $this->assertFalse((bool) $this->listing('/b')->get('field_retired')->value);
  }

  /**
   * One run of the feed with a verifier that reports these transfers.
   */
  private function feedWithTransfers(array $items, array $transfers, bool $priceMatch = TRUE, int $maxAgeDays = 0): array {
    $detail = [
      'count' => count($transfers),
      'newest' => NULL,
      'transfers' => array_map(fn(array $t) => ['amount' => $t[0], 'time' => $t[1]], $transfers),
    ];
    $verifier = $this->createMock(ResourceVerifier::class);
    $verifier->method('verify')->willReturnCallback(fn(string $url, string $method, array $announced) => [
      'verdict' => 'pass',
      'checks' => [],
      'accepts' => $announced,
      'activity' => max(1, count($transfers)),
      'transfers' => ['accepts[0]' => $detail],
    ]);
    return $this->feed($items, fn() => NULL, verifier: $verifier, options: ['price_match' => $priceMatch ?: NULL, 'max_age_days' => $maxAgeDays ?: NULL]);
  }

  /**
   * Price match, first import: a transfer of exactly the current price.
   */
  public function testPriceMatchOnFirstImportTakesTheCurrentPrice(): void {
    $items = [$this->item('/cheap')];
    $counts = $this->feedWithTransfers($items, [[1000000, time()], [100, time()]]);
    $this->assertSame(1, $counts['inactive'], '1 USDC and 0.0001 USDC are not sales of a 0.005 listing.');
    $this->assertSame(['no incoming transfer of the listing\'s price' => 1], $counts['reasons']);
    $this->assertNull($this->listing('/cheap'));

    $counts = $this->feedWithTransfers($items, [[1000000, time()], [5000, time() - 86400]]);
    $this->assertSame(1, $counts['created'], 'One transfer of the price is evidence.');
  }

  /**
   * Price match, with history: the price the listing had on the transfer's day.
   */
  public function testPriceMatchDatesEachTransferByThePriceHistory(): void {
    $day = 86400;
    $this->feedWithTransfers([$this->item('/a', ['accepts' => [$this->accept(['amount' => '3000'])]])], [[3000, time()]]);
    $this->feedWithTransfers([$this->item('/a')], [[3000, time()]]);
    $listing = $this->listing('/a');
    $past = json_decode((string) $listing->get('field_past_accepts')->value, TRUE);
    $this->assertSame('3000', $past[0]['amount'], 'The old price is in the history.');
    $past[0]['until'] = time() - 10 * $day;
    $listing->set('field_past_accepts', json_encode($past))->save();

    $counts = $this->feedWithTransfers([$this->item('/a', ['description' => 'Changed, so it is verified again'])], [[3000, time() - 5 * $day]]);
    $this->assertSame(1, $counts['inactive'], 'Five days ago the price was already 5000: a 3000 transfer is not a sale.');

    $counts = $this->feedWithTransfers([$this->item('/a', ['description' => 'Changed again'])], [[3000, time() - 20 * $day]]);
    $this->assertSame(1, $counts['updated'], 'Twenty days ago the price was 3000: that transfer was a sale.');
  }

  /**
   * Coherence: the newest incoming transfer inside the window.
   */
  public function testMaxAgeDaysDemandsRecentTransfer(): void {
    $items = [$this->item('/stale')];
    $counts = $this->feedWithTransfers($items, [[5000, time() - 90 * 86400]], FALSE, 30);
    $this->assertSame(1, $counts['inactive']);
    $this->assertSame(['no incoming transfer in the window' => 1], $counts['reasons']);

    $counts = $this->feedWithTransfers($items, [[5000, time() - 2 * 86400]], FALSE, 30);
    $this->assertSame(1, $counts['created'], 'A sale two days ago is inside the window.');
  }

  /**
   * One import of a catalogue at a time: the importer's lock is honoured.
   */
  public function testTheFeedWaitsForTheImporterOfItsSource(): void {
    // Kernel tests run with core's null lock: this one says the importer of
    // Bazaar holds its lock, and nothing else is held.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('lockMayBeAvailable')->willReturnCallback(fn(string $name) => $name !== 'ombu.bazaar_import');
    $lock->method('acquire')->willReturn(TRUE);
    $this->container->set('lock', $lock);
    try {
      $this->feed([$this->item('/a')], fn() => ['pass', 3]);
      $this->fail('Two imports of one catalogue ran at once.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('A bazaar catalogue import is already running.', $e->getMessage());
    }
    $this->assertNull($this->listing('/a'));
    $this->assertSame([], $this->pages, 'Not a page was read.');
  }

  /**
   * A locked feed (an import in progress elsewhere) is not started again.
   */
  public function testLockedFeedIsNotStartedAgain(): void {
    $feed = $this->container->get('ombu.catalog_feeds')->feed('bazaar');
    $feed->lock();
    try {
      $this->feed([$this->item('/a')], fn() => ['pass', 3]);
      $this->fail('A locked feed ran.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('already running', $e->getMessage());
    }
    finally {
      $feed->unlock();
    }
  }

  /**
   * Without a node key the run fails before reading the catalogue.
   */
  public function testWithoutTheNodeKeyNothingIsRead(): void {
    $this->container->set('http_client', $this->client([[$this->item('/a')]]));
    // The real factory, with no ALCHEMY_API_KEY in the test environment.
    putenv('ALCHEMY_API_KEY');
    $this->container->set('ombu.resource_verifier', NULL);
    try {
      $this->container->get('ombu.catalog_feeds')->import('bazaar');
      $this->fail('It ran without a node.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('ALCHEMY_API_KEY', $e->getMessage());
    }
    $this->assertSame([], $this->pages);
  }

  /**
   * The fetcher: the catalogue's own query stays, a key goes in its header.
   *
   * The key is the Key module's (here a config key); the feed type holds
   * only its name.
   */
  public function testTheFetcherSendsTheKeyAndKeepsTheQuery(): void {
    Key::create(['id' => 'catalogue', 'label' => 'Catalogue', 'key_provider' => 'config', 'key_provider_settings' => ['key_value' => 'k-123']])->save();
    $type = FeedType::load('ombu_bazaar');
    $type->set('fetcher_configuration', [
      'url' => 'https://catalogue.example/resources?type=http',
      'auth_header' => 'X-Api-Key',
      'auth_key' => 'catalogue',
    ] + $type->getFetcher()->getConfiguration())->save();
    $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $this->assertSame('limit=100&offset=0&type=http', $this->pages[0]);
    $this->assertSame(['k-123'], $this->headers[0]['X-Api-Key']);
  }

  /**
   * The fetcher: https only, and a page no bigger than its limit.
   */
  public function testTheFetcherRefusesHttpAndOversizedPages(): void {
    $type = FeedType::load('ombu_bazaar');
    $type->set('fetcher_configuration', ['url' => 'http://catalogue.example/resources'] + $type->getFetcher()->getConfiguration())->save();
    try {
      $this->feed([$this->item('/a')], fn() => ['pass', 3]);
      $this->fail('A catalogue was read over http.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The catalogue address must be https.', $e->getMessage());
    }
    $this->assertSame([], $this->pages);

    $type = FeedType::load('ombu_bazaar');
    $type->set('fetcher_configuration', ['url' => 'https://catalogue.example/resources', 'max_bytes' => 100] + $type->getFetcher()->getConfiguration())->save();
    try {
      $this->feed([$this->item('/a')], fn() => ['pass', 3]);
      $this->fail('An oversized page was read.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('more than 100 bytes', $e->getMessage());
    }
    $this->assertNull($this->listing('/a'));
  }

  /**
   * A cron run, step by step through Feeds' queue, keeps every rule.
   *
   * Each step loads the feed again (as a request of its own would), so the
   * run's state, the clean list and the keys read have to survive between
   * steps: two pages, a change, a resource dropped and retired at the end.
   */
  public function testCronRunInStepsKeepsTheRules(): void {
    $this->feed([$this->item('/a'), $this->item('/b'), $this->item('/c')], fn() => ['pass', 3]);
    $page1 = array_map(fn($i) => $this->item("/p$i"), range(1, 100));
    $page2 = [$this->item('/a'), $this->item('/b', ['description' => 'changed']), $this->item('/p1')];
    $this->container->set('http_client', $this->client([$page1, $page2]));
    $this->container->set('ombu.resource_verifier', $this->verifier(fn() => ['pass', 3]));
    $this->configure('bazaar', ['budget' => 0]);
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('feeds_feed_type')->resetCache();
    $this->container->get('ombu.catalog_feeds')->feed('bazaar')->startCronImport();

    $queue = $this->container->get('queue')->get('feeds_feed_refresh:ombu_bazaar');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('feeds_feed_refresh:ombu_bazaar');
    $steps = 0;
    while ($item = $queue->claimItem()) {
      $etm->getStorage('feeds_feed')->resetCache();
      $etm->getStorage('feeds_feed_type')->resetCache();
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $steps++;
    }
    $this->assertGreaterThan(100, $steps, 'One queue item per resource, at least.');
    $report = $this->container->get('ombu.catalog_feeds')->lastReport('bazaar');
    $this->assertTrue($report['whole']);
    $counts = array_filter($report['counts']);
    $this->assertSame(['created' => 100, 'updated' => 1, 'unchanged' => 1, 'retired' => 1], $counts);
    $this->assertTrue((bool) $this->listing('/c')->get('field_retired')->value);
    $this->assertFalse((bool) $this->listing('/p1')->get('field_retired')->value);
    $this->assertSame(102, $this->container->get('state')->get('ombu.bazaar_read'));
    $this->assertFalse($this->container->get('ombu.catalog_feeds')->feed('bazaar')->isLocked());
  }

  /**
   * The run's report is kept: drush ombu:feed-report shows it.
   */
  public function testTheLastReportIsKept(): void {
    $counts = $this->feed([$this->item('/a')], fn() => ['pass', 3]);
    $report = $this->container->get('ombu.catalog_feeds')->lastReport('bazaar');
    $this->assertSame($counts, $report['counts']);
    $this->assertTrue($report['whole']);
    $this->assertSame([], \Drupal::keyValue(CatalogRun::STORE)->get('done.' . $this->container->get('ombu.catalog_feeds')->feed('bazaar')->id(), []), 'The keys read are forgotten after the run.');
  }

  /**
   * The same fixtures through the importer and through the feed.
   *
   * A sequence of runs that touches every outcome (created, unchanged,
   * updated, rejected, inactive, unverified, unsupported, deferred, exists,
   * blocked, skipped, retired, the guard and a partial run): each run's
   * counts and, after each run, every listing's fields and the state the
   * importer keeps must be the same.
   */
  public function testTheFeedAndTheImporterLeaveTheSame(): void {
    $other = '0x' . str_repeat('2', 40);
    $answer = fn(string $path) => match ($path) {
      '/quiet' => ['pass', 0],
      '/lie' => ['fail', 9, ['accepts[0]' => ['fail', 'differs from the catalogue (network, asset, payTo or amount)']]],
      '/dark' => ['unchecked', NULL],
      '/moved' => ['fail', 3, ['accepts[0].payee' => ['fail', 'payto_not_allowed']], [$this->accept(['payTo' => $other])]],
      default => ['pass', 3],
    };
    $sol = $this->item('/sol', ['accepts' => [$this->accept(['network' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'])]]);
    $runs = [
      // [items, budget, options].
      [[$this->item('/a'), $this->item('/b'), $this->item('/c'), $this->item('/d'), $this->item('/moved'), $this->item('/quiet'), $this->item('/lie'), $this->item('/dark'), $sol, $this->item('/a')], 200, []],
      [[$this->item('/a'), $this->item('/b', ['description' => 'changed']), $this->item('/c'), $this->item('/d'), $this->item('/moved', ['accepts' => [$this->accept(['payTo' => $other])]]), $this->item('/e'), $this->item('/f')], 1, []],
      [[$this->item('/a'), $this->item('/b', ['description' => 'changed']), $this->item('/e'), $this->item('/f'), $this->item('/g', ['tags' => ['ai']])], 200, ['only_tags' => ['ai']]],
      [[$this->item('/a')], 200, []],
      [[$this->item('/a'), $this->item('/b', ['description' => 'changed']), $this->item('/e')], 200, ['max' => 2]],
    ];
    $byImporter = $this->sequence($runs, $answer, TRUE);
    $this->wipe();
    $byFeed = $this->sequence($runs, $answer, FALSE);
    $this->assertSame($byImporter, $byFeed);
    // The sequence did reach every outcome it claims to.
    $totals = [];
    foreach ($byFeed as $run) {
      foreach (array_filter($run['counts'], 'is_int') as $outcome => $n) {
        $totals[$outcome] = ($totals[$outcome] ?? 0) + $n;
      }
    }
    $seen = array_keys(array_filter($totals));
    sort($seen);
    $this->assertSame('read 1, the run before read 5', $byFeed[3]['counts']['retire_skipped']);
    $this->assertSame(['created', 'deferred', 'inactive', 'rejected', 'retired', 'skipped', 'unchanged', 'unsupported', 'unverified', 'updated'], $seen);
  }

  /**
   * Runs a sequence through the importer or the feed; what each run left.
   */
  private function sequence(array $runs, \Closure $answer, bool $importer): array {
    $out = [];
    foreach ($runs as [$items, $budget, $options]) {
      $this->asked = [];
      if ($importer) {
        $counts = $this->importer($items, $answer, 'bazaar', $budget, 1, NULL, $options['only_tags'] ?? [])->import($options['max'] ?? 0);
      }
      else {
        $counts = $this->feed($items, $answer, 'bazaar', $budget, 1, NULL, $options);
      }
      ksort($counts);
      $state = $this->container->get('state');
      $out[] = [
        'counts' => $counts,
        'asked' => $this->asked,
        'listings' => $this->snapshot(),
        'read' => $state->get('ombu.bazaar_read'),
        'last' => (bool) $state->get('ombu.bazaar_last'),
        'wallet_changed' => array_values(array_map(fn($c) => [$c['url'], $c['old'], $c['new']], $state->get('ombu.catalog.wallet_changed.bazaar', []))),
        'checked' => count($state->get('ombu.catalog.checked.bazaar', [])),
      ];
    }
    return $out;
  }

  /**
   * Every listing's fields that an import writes, by method and URL.
   */
  private function snapshot(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache();
    $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->resetCache();
    $out = [];
    foreach ($storage->loadMultiple() as $p) {
      $values = [];
      foreach (['title', 'field_endpoint', 'field_http_method', 'field_pay_to', 'field_accepts', 'field_input_schema', 'field_output_schema', 'field_provider', 'field_retired', 'field_source_hash', 'field_source', 'field_verified', 'field_source_updated', 'field_siwx', 'status'] as $field) {
        $values[$field] = $p->get($field)->value;
      }
      $values['body'] = $p->get('body')->value;
      $values['field_network'] = array_column($p->get('field_network')->getValue(), 'value');
      $values['field_markets'] = array_column($p->get('field_markets')->getValue(), 'value');
      $values['field_rails'] = array_column($p->get('field_rails')->getValue(), 'value');
      $values['field_tags'] = array_map(fn($t) => $t->label(), $p->get('field_tags')->referencedEntities());
      $values['provider'] = $p->get('field_provider_term')->entity?->label();
      $values['website'] = $p->get('field_provider_term')->entity?->get('field_website')->value;
      $variation = $p->get('variations')->entity;
      $values['sku'] = $variation?->getSku();
      $values['price'] = $variation?->getPrice()?->getNumber();
      $out[$values['field_http_method'] . ' ' . $values['field_endpoint']] = $values;
    }
    ksort($out);
    return $out;
  }

  /**
   * Forgets every listing, term and note between the two sequences.
   */
  private function wipe(): void {
    $etm = $this->container->get('entity_type.manager');
    foreach (['commerce_product', 'commerce_product_variation', 'taxonomy_term'] as $type) {
      $storage = $etm->getStorage($type);
      $storage->delete($storage->loadMultiple());
    }
    $state = $this->container->get('state');
    $state->deleteMultiple(['ombu.bazaar_read', 'ombu.bazaar_read_next', 'ombu.bazaar_last', 'ombu.bazaar_blocked', 'ombu.catalog.checked.bazaar', 'ombu.catalog.wallet_changed.bazaar']);
  }

}
