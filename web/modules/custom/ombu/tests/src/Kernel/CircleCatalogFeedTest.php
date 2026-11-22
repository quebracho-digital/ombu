<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\feeds\Entity\FeedType;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Circle's catalogue on Feeds: what the importer does, and the guards.
 *
 * Each test of CircleCatalogImporterTest has its twin here, run through the
 * feed. One test runs the same fixtures through the importer and the feed
 * and compares what each left. The guards are the deliberate difference:
 * the importer retires every Circle listing on an empty or broken answer
 * and on a catalogue that shrinks by half; the feed does not.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class CircleCatalogFeedTest extends CommerceKernelTestBase {

  private const SPEC_URL = 'https://api.example.com/openapi.json';

  private const SPEC = '{"paths": {"/v1/weather": {"get": {"summary": "Weather now"}}}}';

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
   * How many times the spec was fetched.
   */
  private int $fetches = 0;

  /**
   * The last import's HTTP mock: what it was not asked for stays queued.
   */
  private MockHandler $http;

  /**
   * {@inheritdoc}
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
   * The feed type ships with the module.
   */
  public function testCircleHasItsFeedType(): void {
    $type = FeedType::load('ombu_circle');
    $this->assertSame(CircleCatalogImporter::API, $type->getFetcher()->getConfiguration('url'));
    $this->assertSame('ombu_circle', $type->getParser()->getPluginId());
    $this->assertSame('circle', $type->getProcessor()->source());
    $this->assertSame(-1, $type->getImportPeriod(), 'Not scheduled while the importer runs from cron.');
  }

  /**
   * Tests that a full run leaves another sources listings alone.
   */
  public function testFullRunLeavesAnotherSourcesListingsAlone(): void {
    $other = $this->ownListing([
      'title' => 'From another catalogue',
      'field_endpoint' => 'https://other.example/x',
      'field_http_method' => 'GET',
      'field_source' => 'bazaar',
      'field_markets' => ['bazaar'],
      'field_retired' => FALSE,
    ]);
    $counts = $this->import([$this->item('/v1/weather')]);
    $this->assertSame(0, $counts['retired']);
    $other = Product::load($other->id());
    $this->assertFalse((bool) $other->get('field_retired')->value);
    $this->assertSame(['bazaar'], array_column($other->get('field_markets')->getValue(), 'value'));
  }

  /**
   * Tests that a listing carries its provider payment and operation.
   */
  public function testListingCarriesItsProviderPaymentAndOperation(): void {
    $this->import([$this->item('/v1/weather')]);
    $listing = $this->listing('/v1/weather');
    $provider = $listing->get('field_provider_term')->entity;
    $this->assertSame('Example', $provider->label());
    $this->assertSame(self::SPEC_URL, $provider->get('field_openapi_url')->value);
    $this->assertSame('https://example.com', $provider->get('field_website')->value);
    $this->assertSame(['gateway'], array_column($listing->get('field_rails')->getValue(), 'value'));
    $this->assertTrue((bool) $listing->get('field_siwx')->value);
    $this->assertSame('Web search research', $listing->get('field_category')->entity->label());
    $this->assertSame('listing_tag', $listing->get('field_tags')->entity->bundle());
    $operation = json_decode($listing->get('field_openapi')->value, TRUE);
    $this->assertSame('Weather now', $operation['summary']);
    $this->assertFalse((bool) $listing->get('field_retired')->value);
  }

  /**
   * Tests that every chain it is paid on whatever the scheme.
   */
  public function testEveryChainItIsPaidOnWhateverTheScheme(): void {
    $item = $this->item('/v1/weather');
    // cspell:disable
    $item['accepts'][] = [
      'scheme' => 'upto',
      'network' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
      'amount' => '500',
      'payTo' => 'DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz',
      'asset' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
    ];
    // cspell:enable
    $this->import([$item]);
    $listing = $this->listing('/v1/weather');
    $this->assertSame(['eip155:8453', 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'], array_column($listing->get('field_network')->getValue(), 'value'));
    $this->assertCount(2, json_decode($listing->get('field_accepts')->value, TRUE));
    $this->assertSame('0.001', (string) (float) $listing->get('variations')->entity->getPrice()->getNumber());
  }

  /**
   * An entry with no exact requirement is skipped.
   */
  public function testAnEntryWithoutAnExactRequirementIsSkipped(): void {
    $item = $this->item('/v1/weather');
    $item['accepts'][0]['scheme'] = 'upto';
    $counts = $this->import([$item, $this->item('/v1/forecast')]);
    $this->assertSame(1, $counts['skipped']);
    $this->assertSame(1, $counts['created']);
  }

  /**
   * Tests that one spec fetch per provider per run.
   */
  public function testOneSpecFetchPerProviderPerRun(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertSame(1, $this->fetches);
  }

  /**
   * Tests that an edit without a new last updated still updates.
   */
  public function testAnEditWithoutNewLastUpdatedStillUpdates(): void {
    $this->import([$this->item('/v1/weather')]);
    $this->assertSame(['unchanged' => 1], array_filter($this->import([$this->item('/v1/weather')])));

    $edited = $this->item('/v1/weather');
    $edited['metadata']['description'] = 'Weather, edited';
    $this->assertSame(['updated' => 1], array_filter($this->import([$edited])));
    $this->assertSame('Weather, edited', $this->listing('/v1/weather')->getTitle());
  }

  /**
   * Tests that what circle drops is retired and comes back.
   */
  public function testWhatCircleDropsIsRetiredAndComesBack(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertSame(1, $this->import([$this->item('/v1/weather')])['retired']);
    $gone = $this->listing('/v1/forecast');
    $this->assertTrue((bool) $gone->get('field_retired')->value);
    $this->assertTrue($gone->isPublished());

    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertFalse((bool) $this->listing('/v1/forecast')->get('field_retired')->value);
  }

  /**
   * Tests that a deleted listing is not imported again.
   */
  public function testDeletedListingIsNotImportedAgain(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->listing('/v1/forecast')->delete();
    $counts = array_filter($this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]));
    $this->assertSame(['unchanged' => 1, 'blocked' => 1], $counts);
    $this->assertCount(1, Product::loadMultiple());
  }

  /**
   * Tests that an unreachable spec leaves the listing without it.
   */
  public function testAnUnreachableSpecLeavesTheListingWithoutIt(): void {
    $this->import([$this->item('/v1/weather')], spec: NULL);
    $listing = $this->listing('/v1/weather');
    $this->assertTrue($listing->get('field_openapi')->isEmpty());
    $this->assertSame('Example', $listing->get('field_provider_term')->entity->label());
  }

  /**
   * Tests that a listing added by hand is marked never overwritten.
   */
  public function testListingAddedByHandIsMarkedNeverOverwritten(): void {
    $mine = $this->ownListing([
      'field_source' => 'third_party',
      'field_endpoint' => 'https://api.example.com/v1/weather',
      'field_http_method' => 'GET',
    ]);
    $this->assertSame(['ours' => 1], array_filter($this->import([$this->item('/v1/weather')])));
    $mine = Product::load($mine->id());
    $this->assertSame('Mine', $mine->getTitle());
    $this->assertSame('third_party', $mine->get('field_source')->value);
    $this->assertSame([['value' => 'circle']], $mine->get('field_markets')->getValue());

    // Gone from Circle: no longer marked.
    $this->import([$this->item('/v1/forecast')]);
    $this->assertTrue(Product::load($mine->id())->get('field_markets')->isEmpty());
  }

  /**
   * Tests that our listing on circle is recognized by its url and pay to.
   */
  public function testOurListingOnCircleIsRecognizedByItsUrlAndPayTo(): void {
    $ours = $this->ownListing(['field_seller_address' => '0x6302D9e6DBB22fEC3c350551568Bb39B4b35Ad57']);
    $item = $this->item('/v1/weather');
    $item['resource'] = "https://ombu.example/x402/commerce_product/{$ours->id()}/full";
    $this->assertSame(['ours' => 1], array_filter($this->import([$item])));
    $this->assertSame([['value' => 'circle']], Product::load($ours->id())->get('field_markets')->getValue());
    $this->assertCount(1, Product::loadMultiple());

    // Same path on another site, paid to someone else: not ours.
    $item['accepts'][0]['payTo'] = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    $this->assertSame(['created' => 1], array_filter($this->import([$item])));
  }

  /**
   * Tests that a provider gets its site icon once.
   */
  public function testProviderGetsItsSiteIconOnce(): void {
    // A 1×1 PNG.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $icon = fn() => new Response(200, ['Content-Type' => 'image/png'], $png);
    $this->assertSame(0, $this->import([$this->item('/v1/weather')], logo: new Response(404, ['Content-Type' => 'image/png'], $png))['logos']);
    $this->assertSame(1, $this->import([$this->item('/v1/weather')], logo: $icon())['logos']);
    $logo = $this->listing('/v1/weather')->get('field_provider_term')->entity->get('field_logo')->entity;
    $this->assertSame('public://providers/example.com.png', $logo->getFileUri());
    $this->assertSame(0, $this->import([$this->item('/v1/weather')], logo: $icon())['logos']);
    $this->assertCount(1, $this->http);
  }

  /**
   * A partial run retires nothing and unmarks nothing.
   *
   * Max counts skipped entries too, as the importer's sum of outcomes did.
   */
  public function testPartialRunRetiresNothing(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $skipped = $this->item('/v1/x');
    $skipped['accepts'][0]['scheme'] = 'upto';
    $counts = array_filter($this->import([$skipped, $this->item('/v1/weather'), $this->item('/v1/forecast')], options: ['max' => 2]));
    $this->assertSame(['unchanged' => 1, 'skipped' => 1], $counts);
    $this->assertFalse((bool) $this->listing('/v1/forecast')->get('field_retired')->value);
  }

  /**
   * The guard the importer lacks: an empty or broken answer retires nothing.
   *
   * CircleCatalogImporter reads `items ?? []`: a 200 without items is an
   * empty catalogue to it, and every Circle listing is retired. The feed
   * stops with an error instead.
   */
  public function testAnEmptyOrBrokenAnswerRetiresNothingUnlikeTheImporter(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    foreach (['{}', 'not json', '{"items":"x"}', ''] as $body) {
      try {
        $this->import([], body: $body);
        $this->fail("Answer '$body' was taken for a catalogue.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('did not answer with a list', $e->getMessage());
      }
      $this->assertFalse((bool) $this->listing('/v1/weather')->get('field_retired')->value);
    }

    // The importer, on the same answer, retires them all.
    $counts = $this->importer([], body: '{}');
    $this->assertSame(2, $counts['retired']);
  }

  /**
   * The guard the importer lacks: a catalogue that shrinks by half.
   */
  public function testCatalogueThatShrinksByHalfRetiresNothingUnlikeTheImporter(): void {
    $items = array_map(fn($p) => $this->item("/v1/$p"), ['a', 'b', 'c', 'd']);
    $this->import($items);
    $counts = $this->import([$this->item('/v1/a')]);
    $this->assertSame(0, $counts['retired']);
    $this->assertSame('read 1, the run before read 4', $counts['retire_skipped']);
    $this->assertFalse((bool) $this->listing('/v1/d')->get('field_retired')->value);

    $this->assertSame(3, $this->importer([$this->item('/v1/a')])['retired'], 'The importer retires them.');
  }

  /**
   * An empty list reads nothing: no retirement, our listings stay marked.
   */
  public function testAnEmptyCatalogueRetiresAndUnmarksNothing(): void {
    $mine = $this->ownListing([
      'field_source' => 'third_party',
      'field_endpoint' => 'https://api.example.com/v1/weather',
      'field_http_method' => 'GET',
    ]);
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $counts = $this->import([]);
    $this->assertSame('read 0, the run before read 2', $counts['retire_skipped']);
    $this->assertFalse((bool) $this->listing('/v1/forecast')->get('field_retired')->value);
    $this->assertSame([['value' => 'circle']], Product::load($mine->id())->get('field_markets')->getValue());
  }

  /**
   * The same fixtures through the importer and through the feed.
   *
   * A sequence that the guards do not stop (they are the one deliberate
   * difference): created, unchanged, updated, ours, skipped, blocked,
   * retired, coming back, a partial run. Each run's counts and, after each,
   * every listing's fields and its provider must be the same.
   */
  public function testTheFeedAndTheImporterLeaveTheSame(): void {
    $byImporter = $this->sequence(TRUE);
    $this->wipe();
    $byFeed = $this->sequence(FALSE);
    $this->assertSame($byImporter, $byFeed);
    $totals = [];
    foreach ($byFeed as $run) {
      foreach (array_filter($run['counts'], 'is_int') as $outcome => $n) {
        $totals[$outcome] = ($totals[$outcome] ?? 0) + $n;
      }
    }
    $seen = array_keys(array_filter($totals));
    sort($seen);
    $this->assertSame(['blocked', 'created', 'ours', 'retired', 'skipped', 'unchanged', 'updated'], $seen);
  }

  /**
   * The comparison's runs, through the importer or the feed.
   */
  private function sequence(bool $importer): array {
    $this->ownListing([
      'title' => 'Mine',
      'field_source' => 'third_party',
      'field_endpoint' => 'https://api.example.com/v1/mine',
      'field_http_method' => 'GET',
    ]);
    $skipped = $this->item('/v1/x');
    $skipped['accepts'][0]['scheme'] = 'upto';
    $edited = $this->item('/v1/weather');
    $edited['metadata']['description'] = 'Weather, edited';
    $other = $this->item('/v2/news');
    $other['metadata']['provider'] = ['name' => 'Other', 'website' => 'https://other.example', 'category' => 'NEWS', 'tags' => ['news', 'daily']];
    $runs = [
      [[$this->item('/v1/weather'), $this->item('/v1/forecast'), $this->item('/v1/mine'), $skipped, $other], []],
      [[$edited, $this->item('/v1/forecast'), $this->item('/v1/mine'), $other], []],
      [[$edited, $other, $this->item('/v1/mine')], []],
      'delete',
      [[$edited, $this->item('/v1/forecast'), $other], []],
      [[$edited, $other, $this->item('/v1/forecast')], ['max' => 2]],
    ];
    $out = [];
    foreach ($runs as $run) {
      if ($run === 'delete') {
        $this->listing('/v1/forecast')->delete();
        continue;
      }
      [$items, $options] = $run;
      $this->fetches = 0;
      $counts = $importer ? $this->importer($items, max: $options['max'] ?? 0) : $this->import($items, options: $options);
      ksort($counts);
      $out[] = ['counts' => $counts, 'fetches' => $this->fetches, 'listings' => $this->snapshot()];
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
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
    $out = [];
    foreach ($storage->loadMultiple() as $p) {
      $values = [];
      foreach (['title', 'field_endpoint', 'field_http_method', 'field_pay_to', 'field_accepts', 'field_input_schema', 'field_output_schema', 'field_provider', 'field_openapi', 'field_retired', 'field_source_hash', 'field_source', 'field_verified', 'field_source_updated', 'field_siwx', 'field_docs_url', 'status'] as $field) {
        $values[$field] = $p->get($field)->value;
      }
      $values['body'] = $p->get('body')->value;
      foreach (['field_network', 'field_markets', 'field_rails'] as $field) {
        $values[$field] = array_column($p->get($field)->getValue(), 'value');
      }
      $values['field_tags'] = array_map(fn($t) => $t->label(), $p->get('field_tags')->referencedEntities());
      $values['category'] = $p->get('field_category')->entity?->label();
      $term = $p->get('field_provider_term')->entity;
      $values['provider'] = $term ? [
        $term->label(),
        $term->getDescription(),
        $term->get('field_website')->value,
        $term->get('field_docs_url')->value,
        $term->get('field_openapi_url')->value,
      ] : NULL;
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
    $this->container->get('state')->deleteMultiple(['ombu.circle_read', 'ombu.circle_last', CircleCatalogImporter::BLOCKED]);
  }

  /**
   * A listing of ours, "Mine".
   */
  private function ownListing(array $values): ProductInterface {
    $listing = Product::create($values + ['type' => 'listing', 'title' => 'Mine', 'field_source' => 'ours']);
    $listing->save();
    return $listing;
  }

  /**
   * One Circle Discovery item of the Example provider.
   */
  private function item(string $path): array {
    return [
      'resource' => "https://api.example.com$path",
      'type' => 'http',
      'x402Version' => 2,
      'lastUpdated' => '2026-10-01T00:00:00Z',
      'accepts' => [[
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'amount' => '1000',
        'payTo' => '0x6302D9e6DBB22fEC3c350551568Bb39B4b35Ad57',
        'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
      ],
      ],
      'metadata' => [
        'provider' => [
          'name' => 'Example',
          'website' => 'https://example.com',
          'openApiUrl' => self::SPEC_URL,
          'description' => 'Weather for agents',
          'category' => 'WEB_SEARCH_RESEARCH',
          'tags' => ['weather'],
        ],
        'path' => $path,
        'method' => 'GET',
        'description' => "Weather $path",
        'siwx' => TRUE,
        'supportsVanillax402' => FALSE,
        'supportsCircleGateway' => TRUE,
      ],
    ];
  }

  /**
   * The catalogue (one page of $items, or $body as it is) and the logo.
   */
  private function client(array $items, ?Response $logo, ?string $body): Client {
    $this->http = new MockHandler([
      new Response(200, [], $body ?? json_encode(['items' => $items, 'pagination' => ['total' => count($items)]])),
      ...($logo ? [$logo] : []),
    ]);
    return new Client(['handler' => HandlerStack::create($this->http)]);
  }

  /**
   * The guarded fetch, answering $spec or refusing.
   */
  private function fetcher(?string $spec): PageFetcher {
    $fetcher = $this->createMock(PageFetcher::class);
    $fetcher->method('fetch')->willReturnCallback(function () use ($spec) {
      $this->fetches++;
      return $spec ?? throw PageFetchException::notPublic();
    });
    return $fetcher;
  }

  /**
   * Runs the Circle feed over these items.
   *
   * $spec is what the guarded fetch returns, $logo what Google's favicon
   * service answers.
   */
  private function import(array $items, ?string $spec = self::SPEC, ?Response $logo = NULL, ?string $body = NULL, array $options = []): array {
    $this->container->set('http_client', $this->client($items, $logo, $body));
    $this->container->set(PageFetcher::class, $this->fetcher($spec));
    return $this->container->get('ombu.catalog_feeds')->import('circle', $options);
  }

  /**
   * Runs the importer over the same, as CircleCatalogImporterTest does.
   */
  private function importer(array $items, ?string $spec = self::SPEC, ?Response $logo = NULL, ?string $body = NULL, int $max = 0): array {
    $importer = new CircleCatalogImporter($this->client($items, $logo, $body), $this->container->get('entity_type.manager'), $this->container->get('logger.factory'), $this->container->get('lock'), $this->fetcher($spec), $this->container->get('ombu.sellers'));
    return $importer->import($max);
  }

  /**
   * The listing for an Example path, fresh from storage.
   */
  private function listing(string $path): ProductInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache();
    $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_endpoint', "https://api.example.com$path")->execute();
    $this->assertCount(1, $ids);
    return Product::load(reset($ids));
  }

}
