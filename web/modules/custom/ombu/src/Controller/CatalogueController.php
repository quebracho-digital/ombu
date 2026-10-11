<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
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
 * imported ones carry what their source said. Query: search, network,
 * category (term name), limit (max 100), offset.
 */
final class CatalogueController implements ContainerInjectionInterface {

  use AutowireTrait;

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
    if ($network = $request->query->get('network')) {
      $query->addCondition('network', $network);
    }
    if ($category = $request->query->get('category')) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'listing_category', 'name' => $category]);
      $query->addCondition('category', $terms ? (int) reset($terms)->id() : -1);
    }
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
    ]);
  }

  private function item(ProductInterface $product, Request $request): array {
    [$resource, $accepts] = $this->sale($product, $request) ?? [
      $product->get('field_endpoint')->value,
      json_decode($product->get('field_accepts')->value ?? '[]', TRUE) ?: [],
    ];
    $category = $product->get('field_category')->entity?->label();
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
          'docsUrl' => $product->get('field_docs_url')->value,
          'description' => $product->get('body')->value,
          'category' => $category,
          'tags' => array_map(fn($t) => $t->label(), $product->get('field_tags')->referencedEntities()),
        ],
        'method' => $product->get('field_http_method')->value,
        'description' => $product->getTitle(),
        'input' => json_decode($product->get('field_input_schema')->value ?? 'null', TRUE),
        'output' => json_decode($product->get('field_output_schema')->value ?? 'null', TRUE),
        // Ours beyond Circle's shape: who vouches for it, and what buyers say.
        'market' => [
          'url' => $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'source' => $product->get('field_source')->value,
          'verified' => (bool) $product->get('field_verified')->value,
          'rating' => $rating,
        ],
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
