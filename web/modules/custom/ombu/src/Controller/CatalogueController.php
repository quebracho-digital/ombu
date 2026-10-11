<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\search_api\Query\QueryInterface;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The catalogue for agents, shaped like Circle's Discovery API (v2).
 *
 * Same item shape (resource, accepts, metadata), so a client written for
 * Circle's Agent Marketplace reads this market unchanged. Our listings are
 * quoted as their sale will be (x402_pay's price event: price, seller
 * wallet), so the catalogue never announces a stale price or payTo; the
 * imported ones carry what their source said.
 *
 * Query: search, and the facets people get on /market, each a comma-
 * separated list (any of them matches): network (CAIP-2 id), category
 * (Circle's code or its name), provider, payment (vanilla, gateway), tag,
 * source (ours, circle). limit (max 100), offset. Retired listings only
 * with retired=1; the operation of the provider's OpenAPI with openapi=1.
 * The response counts every facet's values over the matches.
 */
final class CatalogueController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The facets: index field => what a value is (term, or as indexed).
   */
  private const FACETS = [
    'network' => NULL,
    'category' => 'listing_category',
    'provider_id' => 'listing_provider',
    'rails' => NULL,
    'tags' => 'listing_tag',
    'source' => NULL,
  ];

  /**
   * Query parameter => index field.
   */
  private const PARAMS = [
    'network' => 'network',
    'category' => 'category',
    'provider' => 'provider_id',
    'payment' => 'rails',
    'tag' => 'tags',
    'source' => 'source',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'x402_pay.delivery')]
    private readonly Delivery $delivery,
  ) {}

  public function __invoke(Request $request): JsonResponse {
    $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
    $offset = max(0, (int) $request->query->get('offset', 0));

    $query = $this->entityTypeManager->getStorage('search_api_index')->load('listings')->query()
      ->range($offset, $limit);
    if ($keys = trim((string) $request->query->get('search', ''))) {
      $query->keys($keys);
    }
    foreach (self::PARAMS as $param => $field) {
      $values = array_filter(array_map('trim', explode(',', (string) $request->query->get($param, ''))), 'strlen');
      if ($values) {
        $this->filter($query, $field, $values);
      }
    }
    if (!$request->query->getBoolean('retired')) {
      $query->addCondition('retired', FALSE);
    }
    $query->setOption('search_api_facets', array_map(fn($field) => [
      'field' => $field,
      'limit' => $field === 'tags' ? 30 : 0,
      'operator' => 'and',
      'min_count' => 1,
      'missing' => FALSE,
    ], array_combine(array_keys(self::FACETS), array_keys(self::FACETS))));
    $results = $query->execute();

    $items = [];
    foreach ($results->getResultItems() as $item) {
      $product = $item->getOriginalObject()?->getValue();
      if ($product instanceof ProductInterface) {
        $items[] = $this->item($product, $request);
      }
    }
    return new JsonResponse([
      'x402Version' => 2,
      'items' => $items,
      'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => (int) $results->getResultCount()],
      'facets' => $this->counts($results->getExtraData('search_api_facets', [])),
    ]);
  }

  /**
   * Matches any of the values; term facets take names, not ids.
   */
  private function filter(QueryInterface $query, string $field, array $values): void {
    if ($vid = self::FACETS[$field]) {
      if ($vid === 'listing_category') {
        $values = array_map([CircleCatalogImporter::class, 'categoryLabel'], $values);
      }
      $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)
        ->condition('vid', $vid)->condition('name', $values, 'IN')->execute();
      $values = array_map('intval', $ids) ?: [-1];
    }
    $query->addCondition($field, $values, 'IN');
  }

  /**
   * Facet counts by parameter, keyed by what the parameter takes.
   */
  private function counts(array $facets): array {
    $out = [];
    foreach (self::PARAMS as $param => $field) {
      $counts = [];
      foreach ($facets[$field] ?? [] as $row) {
        $counts[trim((string) $row['filter'], '"')] = (int) $row['count'];
      }
      if ($vid = self::FACETS[$field]) {
        $named = [];
        foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_keys($counts)) as $id => $term) {
          $name = $vid === 'listing_category' ? CircleCatalogImporter::categoryCode($term->label()) : $term->label();
          $named[$name] = $counts[$id];
        }
        $counts = $named;
      }
      $out[$param] = $counts;
    }
    return $out;
  }

  private function item(ProductInterface $product, Request $request): array {
    [$resource, $accepts] = $this->sale($product, $request) ?? [
      $product->get('field_endpoint')->value,
      json_decode($product->get('field_accepts')->value ?? '[]', TRUE) ?: [],
    ];
    $category = $product->get('field_category')->entity?->label();
    $provider = $product->get('field_provider_term')->entity;
    $rails = array_column($product->get('field_rails')->getValue(), 'value');
    $rating = ombu_rating($product);
    return [
      'resource' => $resource,
      'type' => 'http',
      'x402Version' => 2,
      'lastUpdated' => $product->get('field_source_updated')->value ?: date('c', $product->getChangedTime()),
      'accepts' => $accepts,
      'metadata' => [
        'provider' => [
          'name' => $product->get('field_provider')->value,
          'website' => $provider?->get('field_website')->value,
          'docsUrl' => $product->get('field_docs_url')->value,
          'openApiUrl' => $provider?->get('field_openapi_url')->value,
          'description' => $product->get('body')->value,
          'category' => $category === NULL ? NULL : CircleCatalogImporter::categoryCode($category),
          'tags' => array_map(fn($t) => $t->label(), $product->get('field_tags')->referencedEntities()),
        ],
        'method' => $product->get('field_http_method')->value,
        'description' => $product->getTitle(),
        'input' => json_decode($product->get('field_input_schema')->value ?? 'null', TRUE),
        'output' => json_decode($product->get('field_output_schema')->value ?? 'null', TRUE),
        'siwx' => (bool) $product->get('field_siwx')->value,
        'supportsVanillax402' => in_array('vanilla', $rails, TRUE),
        'supportsCircleGateway' => in_array('gateway', $rails, TRUE),
        // Ours beyond Circle's shape: who vouches for it, and what buyers say.
        'market' => [
          'url' => $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'source' => $product->get('field_source')->value,
          'verified' => (bool) $product->get('field_verified')->value,
          'rating' => $rating,
          'retired' => (bool) $product->get('field_retired')->value,
        ] + ($request->query->getBoolean('openapi') ? [
          'openapi' => json_decode((string) $product->get('field_openapi')->value, TRUE),
        ] : []),
      ],
    ];
  }

  /**
   * Where and how a listing sold through x402_pay is paid; NULL otherwise.
   */
  private function sale(ProductInterface $product, Request $request): ?array {
    $item = X402PriceItem::find($product, 'full');
    if (!$item) {
      return NULL;
    }
    try {
      $quote = $this->delivery->quote($request, $product, $item);
      $accepts = $quote->isDenied() ? [] : [$this->delivery->requirements($quote, $item)];
    }
    catch (\RuntimeException | \InvalidArgumentException) {
      // No usable seller key: listed, but not payable until it has one.
      $accepts = [];
    }
    $url = Url::fromRoute('x402_pay.entity', ['entity_type' => 'commerce_product', 'entity' => $product->id(), 'view_mode' => 'full'], ['absolute' => TRUE]);
    return [$url->toString(TRUE)->getGeneratedUrl(), $accepts];
  }

}
