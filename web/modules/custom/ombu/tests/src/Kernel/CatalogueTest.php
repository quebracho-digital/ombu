<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * /market.json: the facets people get on /market, for agents.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class CatalogueTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views',
    'search_api', 'search_api_db', 'facets', 'better_exposed_filters', 'facets_exposed_filters',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['commerce_product', 'comment', 'search_api', 'ombu']);
    \Drupal::moduleHandler()->loadInclude('ombu', 'install');
    _ombu_config('optional', ['search_api.server.market_db', 'search_api.index.listings', 'views.view.market']);
    \Drupal::service('router.builder')->rebuild();
    Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])
      ->grantPermission('view commerce_product')
      ->save();

    $this->listing('Weather', 'Web search research', 'Example', ['vanilla'], 'eip155:5042');
    $this->listing('Captcha', 'Infrastructure', 'Sponge', ['gateway'], 'eip155:8453');
    $this->listing('Gas', 'Infrastructure', 'Example', ['vanilla', 'gateway'], 'eip155:8453');
    $this->listing('Gone', 'Infrastructure', 'Example', ['vanilla'], 'eip155:8453', retired: TRUE);
    \Drupal::entityTypeManager()->getStorage('search_api_index')->load('listings')->indexItems();
  }

  public function testFiltersByNameLikeMarketAndCountsEveryFacet(): void {
    $page = $this->get('/market.json?category=INFRASTRUCTURE&payment[]=vanilla');
    $this->assertSame(['Gas'], $this->titles($page));
    // A facet counts its other values as if it were not filtered: picking
    // one more widens the search.
    $this->assertSame(['gateway' => 2, 'vanilla' => 1], $this->counts($page, 'payment'));
    $this->assertSame(['Infrastructure' => 1, 'Web search research' => 1], $this->counts($page, 'category'));
  }

  public function testSeveralValuesMatchAnyOfThem(): void {
    $this->assertSame(['Captcha', 'Gas', 'Weather'], $this->titles($this->get('/market.json?provider=Example,Sponge')));
    $this->assertSame(['Captcha', 'Gas', 'Weather'], $this->titles($this->get('/market.json?provider[]=Example&provider[]=Sponge')));
  }

  public function testEachFacetValueLinksToTheNarrowerSearch(): void {
    $page = $this->get('/market.json?payment=vanilla');
    $arc = array_values(array_filter($page['facets']['network'], fn($v) => $v['value'] === 'eip155:5042'))[0];
    $this->assertSame('Arc', $arc['label']);
    $this->assertSame(['Weather'], $this->titles($this->get(substr($arc['url'], strpos($arc['url'], '/market.json')))));
    $this->assertStringContainsString('/market?payment=vanilla', urldecode($page['links']['html']));
  }

  public function testTheOpenApiListsWhatTheFiltersHold(): void {
    $spec = $this->get('/openapi.json');
    $parameters = array_column($spec['paths']['/market.json']['get']['parameters'], NULL, 'name');
    $this->assertSame(['Infrastructure', 'Web search research'], $parameters['category']['schema']['items']['enum']);
    $this->assertSame(['Example', 'Sponge'], $parameters['provider']['schema']['items']['enum']);
    $this->assertContains('eip155:5042', $parameters['network']['schema']['items']['enum']);
  }

  public function testRetiredOnlyWhenAsked(): void {
    $this->assertNotContains('Gone', $this->titles($this->get('/market.json')));
    $this->assertContains('Gone', $this->titles($this->get('/market.json?retired=1')));
  }

  /**
   * A listing with these facets.
   */
  private function listing(string $title, string $category, string $provider, array $rails, string $network, bool $retired = FALSE): void {
    Product::create([
      'type' => 'listing',
      'title' => $title,
      'stores' => [$this->store],
      'field_category' => $this->term('listing_category', $category),
      'field_provider' => $provider,
      'field_provider_term' => $this->term('listing_provider', $provider),
      'field_rails' => $rails,
      'field_network' => [$network],
      'field_source' => 'circle',
      'field_retired' => $retired,
    ])->save();
  }

  /**
   * A term by name, created once.
   */
  private function term(string $vid, string $name): int {
    $found = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : Term::create(['vid' => $vid, 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * The decoded JSON of a GET.
   */
  private function get(string $path): array {
    $response = $this->container->get('http_kernel')->handle(Request::create($path));
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * The titles of the items, sorted.
   */
  private function titles(array $page): array {
    $titles = array_map(fn($item) => $item['metadata']['description'], $page['items']);
    sort($titles);
    return $titles;
  }

  /**
   * A facet's counts by value.
   */
  private function counts(array $page, string $param): array {
    $counts = array_column($page['facets'][$param], 'count', 'value');
    ksort($counts);
    return $counts;
  }

}
