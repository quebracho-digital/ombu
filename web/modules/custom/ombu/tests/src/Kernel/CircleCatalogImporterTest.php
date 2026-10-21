<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Circle's catalogue, kept in sync: providers, OpenAPI, retired listings.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class CircleCatalogImporterTest extends CommerceKernelTestBase {

  private const SPEC_URL = 'https://api.example.com/openapi.json';

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
   * How many times the spec was fetched.
   */
  private int $fetches = 0;

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
    $this->installConfig(['commerce_product', 'comment', 'ombu']);
  }

  public function testAListingCarriesItsProviderPaymentAndOperation(): void {
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
    // The price is the exact one's, not the cheaper ceiling.
    $this->assertSame('0.001', (string) (float) $listing->get('variations')->entity->getPrice()->getNumber());
  }

  public function testOneSpecFetchPerProviderPerRun(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertSame(1, $this->fetches);
  }

  public function testAnEditWithoutANewLastUpdatedStillUpdates(): void {
    $this->import([$this->item('/v1/weather')]);
    $this->assertSame(['unchanged' => 1], array_filter($this->import([$this->item('/v1/weather')])));

    $edited = $this->item('/v1/weather');
    $edited['metadata']['description'] = 'Weather, edited';
    $this->assertSame(['updated' => 1], array_filter($this->import([$edited])));
    $this->assertSame('Weather, edited', $this->listing('/v1/weather')->getTitle());
  }

  public function testWhatCircleDropsIsRetiredAndComesBack(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertSame(1, $this->import([$this->item('/v1/weather')])['retired']);
    $gone = $this->listing('/v1/forecast');
    $this->assertTrue((bool) $gone->get('field_retired')->value);
    // Still published: its page and reviews stay.
    $this->assertTrue($gone->isPublished());

    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    $this->assertFalse((bool) $this->listing('/v1/forecast')->get('field_retired')->value);
  }

  public function testADeletedListingIsNotImportedAgain(): void {
    $this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]);
    // A takedown: staff delete it.
    $this->listing('/v1/forecast')->delete();

    $counts = array_filter($this->import([$this->item('/v1/weather'), $this->item('/v1/forecast')]));
    $this->assertSame(['unchanged' => 1, 'blocked' => 1], $counts);
    $this->assertCount(1, Product::loadMultiple());
  }

  public function testAnUnreachableSpecLeavesTheListingWithoutIt(): void {
    $this->import([$this->item('/v1/weather')], spec: NULL);
    $listing = $this->listing('/v1/weather');
    $this->assertTrue($listing->get('field_openapi')->isEmpty());
    $this->assertSame('Example', $listing->get('field_provider_term')->entity->label());
  }

  public function testAListingAddedByHandIsMarkedNeverOverwritten(): void {
    $mine = $this->ownListing(['field_source' => 'third_party', 'field_endpoint' => 'https://api.example.com/v1/weather', 'field_http_method' => 'GET']);

    $this->assertSame(['ours' => 1], array_filter($this->import([$this->item('/v1/weather')])));
    $mine = Product::load($mine->id());
    $this->assertSame('Mine', $mine->getTitle());
    $this->assertSame('third_party', $mine->get('field_source')->value);
    $this->assertSame([['value' => 'circle']], $mine->get('field_markets')->getValue());
    $this->listing('/v1/weather');

    // Gone from Circle: no longer marked.
    $this->import([$this->item('/v1/forecast')]);
    $this->assertTrue(Product::load($mine->id())->get('field_markets')->isEmpty());
  }

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
      ]],
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
   * Runs a full import of these items; $spec is what the fetch returns.
   */
  private function import(array $items, ?string $spec = '{"paths": {"/v1/weather": {"get": {"summary": "Weather now"}}}}'): array {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode(['items' => $items, 'pagination' => ['total' => count($items)]])),
    ]))]);
    $fetcher = $this->createMock(PageFetcher::class);
    $fetcher->method('fetch')->willReturnCallback(function () use ($spec) {
      $this->fetches++;
      return $spec ?? throw PageFetchException::notPublic();
    });
    $importer = new CircleCatalogImporter($client, $this->container->get('entity_type.manager'), $this->container->get('logger.factory'), $this->container->get('lock'), $fetcher, $this->container->get('ombu.sellers'));
    return $importer->import();
  }

  /**
   * The listing for an Example path, fresh from storage.
   */
  private function listing(string $path): ProductInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_endpoint', "https://api.example.com$path")->execute();
    $this->assertCount(1, $ids);
    return Product::load(reset($ids));
  }

}
