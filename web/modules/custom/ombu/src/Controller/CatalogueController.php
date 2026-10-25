<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ombu\Chains;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ombu\Markdown;
use Drupal\ombu\Rating;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalogue for agents, shaped like Circle's Discovery API (v2).
 *
 * Same item shape (resource, accepts, metadata), so a client written for
 * Circle's Agent Marketplace reads this market unchanged. Our listings are
 * quoted as their sale will be (x402_pay's price event: price, seller
 * wallet), so the catalogue never announces a stale price or payTo; the
 * imported ones carry what their source said.
 *
 * Query: search, and the facets people get on /market, with the same names
 * and values, so the same query string works on both: category, network
 * (CAIP-2 id), provider, payment (vanilla, gateway), tag, seller (ours,
 * circle). Each takes several values (name[]=a&name[]=b, or a,b): any of
 * them matches. limit (max 100), offset. Retired listings only with
 * retired=1; the operation of the provider's OpenAPI with openapi=1.
 *
 * The response lists every facet's values with their counts and the URL
 * that adds each, so an agent narrows by following links; and links.html,
 * the same search for people.
 */
final class CatalogueController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Query parameter => index field. The same on /market, for people.
   */
  private const PARAMS = [
    'category' => 'category',
    'network' => 'network',
    'provider' => 'provider_name',
    'payment' => 'rails',
    'tag' => 'tag',
    'seller' => 'source',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    #[Autowire(service: 'x402_pay.delivery')]
    private readonly Delivery $delivery,
    #[Autowire(service: 'commerce_price.currency_formatter')]
    private readonly CurrencyFormatterInterface $currencyFormatter,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  public function __invoke(Request $request): JsonResponse {
    $search = $this->search($request);
    $page = $request->query->all();
    unset($page['limit'], $page['offset'], $page['openapi']);
    return new JsonResponse([
      'x402Version' => 2,
      'items' => array_map(fn($product) => $this->item($product, $request), $search['products']),
      'pagination' => ['limit' => $search['limit'], 'offset' => $search['offset'], 'total' => $search['total']],
      'facets' => $search['facets'],
      'links' => [
        'html' => Url::fromRoute('view.market.page_1', [], ['query' => $page, 'absolute' => TRUE])->toString(),
      ],
    ]);
  }

  /**
   * The same search as markdown: /market.md, or /market for "text/markdown".
   *
   * For agents that read pages rather than JSON: each listing with its
   * price, provider and rating, linked to its page (also readable as .md),
   * and the filters as links to the same search narrowed.
   */
  public function markdown(Request $request): Response {
    $search = $this->search($request);
    $query = $request->query->all();
    $out = ['# ' . $this->t('Ombú market'), ''];
    // URLs outside t(): its placeholders escape them as HTML (&amp;).
    $out[] = $this->formatPlural($search['total'], '1 service. The same search as JSON:', '@count services. The same search as JSON:') . ' '
      . Url::fromRoute('ombu.catalogue', [], ['query' => $query, 'absolute' => TRUE])->toString();
    $out[] = '';
    foreach ($search['products'] as $product) {
      // In the page's language, as people see it.
      $product = $this->entityRepository->getTranslationFromContext($product);
      $price = $product->getDefaultVariation()?->getPrice();
      $rating = Rating::of($product);
      $facts = array_filter([
        (string) $product->get('field_provider')->value,
        (string) $product->get('field_category')->entity?->label(),
        $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode(), [
          'minimum_fraction_digits' => 0,
          'maximum_fraction_digits' => 6,
        ]) : '',
        $rating ? $this->t('@average/5 (@count)', ['@average' => $rating['average'], '@count' => $rating['count']]) : '',
        $product->get('field_verified')->value ? $this->t('verified') : '',
      ], 'strlen');
      // The page's language: one not translated to it would link to its own.
      $url = $product->toUrl('canonical', [
        'absolute' => TRUE,
        'language' => $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL),
      ])->toString();
      // The facts in a code span: a provider named like a URL is not a link.
      $out[] = '- [' . Markdown::linkText((string) $product->label()) . "]($url)" . ($facts ? ': `' . Markdown::linkText(implode(' · ', $facts), 200) . '`' : '');
    }
    if ($search['offset'] + $search['limit'] < $search['total']) {
      $out[] = '';
      $out[] = $this->t('Next:') . ' ' . self::marketMd(['offset' => $search['offset'] + $search['limit']] + $query);
    }
    foreach ($search['facets'] as $param => $values) {
      $out[] = '';
      $out[] = "## $param";
      $out[] = '';
      foreach ($values as $value) {
        $with = $query;
        unset($with['offset']);
        $with[$param] = array_values(array_unique([...$this->values($request, $param), $value['value']]));
        $out[] = '- [' . Markdown::linkText((string) $value['label']) . '](' . self::marketMd($with) . ') ' . $value['count'] . ($value['selected'] ? ' ✓' : '');
      }
    }
    return new Response(implode("\n", $out) . "\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
  }

  /**
   * The market's markdown URL for a query, in the page's language.
   */
  public static function marketMd(array $query = []): string {
    // The view's paging and sorting mean nothing to the search (offset).
    unset($query['page'], $query['sort_by'], $query['sort_order'], $query['items_per_page']);
    $url = Url::fromRoute('view.market.page_1', [], ['absolute' => TRUE])->toString();
    return $url . '.md' . ($query ? '?' . UrlHelper::buildQuery($query) : '');
  }

  /**
   * Runs the search both answers share.
   *
   * @return array
   *   products (ProductInterface[], this page), total, limit, offset and
   *   facets.
   */
  private function search(Request $request): array {
    $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
    $offset = max(0, (int) $request->query->get('offset', 0));

    $query = $this->entityTypeManager->getStorage('search_api_index')->load('listings')->query()
      ->range($offset, $limit);
    if ($keys = trim((string) $request->query->get('search', ''))) {
      $query->keys($keys);
    }
    $selected = [];
    foreach (self::PARAMS as $param => $field) {
      if ($selected[$param] = $this->values($request, $param)) {
        // Tagged, so this facet still counts its other values (any of them
        // matches), like /market.
        $group = $query->createConditionGroup('OR', ["facet:$field"]);
        $group->addCondition($field, $selected[$param], 'IN');
        $query->addConditionGroup($group);
      }
    }
    if (!$request->query->getBoolean('retired')) {
      $query->addCondition('retired', FALSE);
    }
    $query->setOption('search_api_facets', array_map(fn($field) => [
      'field' => $field,
      'limit' => $field === 'tag' ? 30 : 0,
      'operator' => 'or',
      'min_count' => 1,
      'missing' => FALSE,
    ], array_combine(self::PARAMS, self::PARAMS)));
    $results = $query->execute();

    $products = [];
    foreach ($results->getResultItems() as $item) {
      $product = $item->getOriginalObject()?->getValue();
      if ($product instanceof ProductInterface) {
        $products[] = $product;
      }
    }
    return [
      'products' => $products,
      'total' => (int) $results->getResultCount(),
      'limit' => $limit,
      'offset' => $offset,
      'facets' => $this->facets($results->getExtraData('search_api_facets', []), $selected, $request),
    ];
  }

  /**
   * A parameter's values: name[]=a&name[]=b, as /market sends, or a,b.
   */
  private function values(Request $request, string $param): array {
    $raw = $request->query->all()[$param] ?? [];
    $values = is_array($raw) ? $raw : explode(',', (string) $raw);
    $values = array_values(array_filter(array_map('trim', array_filter($values, 'is_string')), 'strlen'));
    // Circle's category codes work too.
    return $param === 'category' ? array_map([CircleCatalogImporter::class, 'categoryLabel'], $values) : $values;
  }

  /**
   * Every facet's values with their counts, and the URL that adds each.
   */
  private function facets(array $facets, array $selected, Request $request): array {
    $labels = [
      'payment' => ['vanilla' => 'Plain x402', 'gateway' => 'Circle Gateway'],
      'seller' => ['ours' => 'Quebracho', 'circle' => 'Circle Agent Marketplace', 'third_party' => 'Third party'],
    ];
    $query = $request->query->all();
    unset($query['offset']);
    $out = [];
    foreach (self::PARAMS as $param => $field) {
      foreach ($facets[$field] ?? [] as $row) {
        $value = trim((string) $row['filter'], '"');
        $with = $query;
        $with[$param] = array_values(array_unique([...$selected[$param], $value]));
        $out[$param][] = [
          'value' => $value,
          'label' => $param === 'network' ? Chains::name($value) : ($labels[$param][$value] ?? $value),
          'count' => (int) $row['count'],
          'selected' => in_array($value, $selected[$param], TRUE),
          'url' => Url::fromRoute('ombu.catalogue', [], ['query' => $with, 'absolute' => TRUE])->toString(),
        ];
      }
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
    $rating = Rating::of($product);
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
