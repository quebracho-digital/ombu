<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\ombu\Chains;
use Drupal\ombu\Sellers;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The site's OpenAPI: searching the market, and buying from our sellers.
 *
 * The search's filters list the values the catalogue holds right now, so an
 * agent knows what to ask without trying. Each of our sellers is one paid
 * operation, with its input and its list price (x402: the 402 says the
 * price of the sale, which a price event may change).
 */
final class OpenApiController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Builds the spec.
   */
  public function __invoke(): JsonResponse {
    $base = rtrim(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), '/');
    $paths = ['/market.json' => ['get' => $this->search()], '/market/listing/{id}/reviews' => ['post' => $this->review()]];
    foreach ($this->ours() as $listing) {
      if ($operation = $this->sale($listing)) {
        $paths["/x402/commerce_product/{$listing->id()}/full"] = ['post' => $operation];
      }
    }
    return new JsonResponse([
      'openapi' => '3.1.0',
      'info' => [
        'title' => 'Ombú',
        'version' => '1',
        'description' => 'A mall where AI agents sell to people and to other agents, paid in USDC over x402. Search every listing (ours and the Circle Agent Marketplace) with /market.json; buy from our sellers by POSTing to their URL: the first answer is a 402 with the payment requirements, the second, with a PAYMENT-SIGNATURE header, the service.',
      ],
      'servers' => [['url' => $base]],
      'paths' => $paths,
    ]);
  }

  /**
   * The /market.json search, its filters listing what the catalogue holds.
   */
  private function search(): array {
    $list = fn(string $name, string $description, ?array $enum = NULL) => [
      'name' => $name,
      'in' => 'query',
      'description' => $description . ' Several values: name[]=a&name[]=b, or a,b; any of them matches.',
      'schema' => ['type' => 'array', 'items' => ['type' => 'string'] + ($enum ? ['enum' => $enum] : [])],
      'style' => 'form',
      'explode' => FALSE,
    ];
    return [
      'summary' => 'Search the listings',
      'description' => 'Every listing in the shape of Circle\'s Discovery API, with the facets people get on /market (same query string). Each facet value comes with its count and the URL that adds it: narrow by following links. Free.',
      'parameters' => [
        ['name' => 'search', 'in' => 'query', 'description' => 'Words, also matched against each listing\'s OpenAPI.', 'schema' => ['type' => 'string']],
        $list('category', 'Category.', $this->names('listing_category')),
        $list('network', 'Chain, as a CAIP-2 id: ' . implode(', ', array_map(fn($id, $name) => "$id ($name)", array_keys(Chains::NAMES), Chains::NAMES)) . '.', array_keys(Chains::NAMES)),
        $list('provider', 'Provider.', $this->names('listing_provider')),
        $list('payment', 'vanilla: plain x402, payable from any wallet; gateway: Circle Gateway, needs a deposit at Circle.', ['vanilla', 'gateway']),
        $list('tag', 'Tag.'),
        $list('seller', 'ours: sold by Ombú itself, settled only on delivery; circle: listed on the Circle Agent Marketplace.', ['ours', 'circle']),
        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]],
        ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]],
        ['name' => 'retired', 'in' => 'query', 'description' => '1: include listings no longer offered.', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
        ['name' => 'openapi', 'in' => 'query', 'description' => '1: include each listing\'s OpenAPI operation.', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
      ],
      'responses' => [
        '200' => [
          'description' => 'items (Circle Discovery items), pagination, facets (per parameter: value, label, count, selected, url) and links.html (the same search for people).',
          'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['items', 'pagination', 'facets']]]],
        ],
      ],
    ];
  }

  /**
   * Reviewing a purchase: signed in with the wallet that paid.
   */
  private function review(): array {
    return [
      'summary' => 'Review a listing you bought',
      'description' => 'Sign in first with your wallet (Sign-In With Ethereum: GET /wallet/nonce, POST /wallet/login with {message, signature}) and keep the session cookie; send the token from GET /session/token as X-CSRF-Token. tx must pay this listing\'s seller in USDC from your wallet: it is checked on chain, and backs one review only. Free.',
      'parameters' => [['name' => 'id', 'in' => 'path', 'required' => TRUE, 'description' => 'The listing: its id in /market.json (metadata.market.url ends with it).', 'schema' => ['type' => 'integer']]],
      'requestBody' => [
        'required' => TRUE,
        'content' => ['application/json' => ['schema' => [
          'type' => 'object',
          'required' => ['rating', 'body', 'tx'],
          'properties' => [
            'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'body' => ['type' => 'string'],
            'tx' => ['type' => 'string', 'pattern' => '^0x[0-9a-fA-F]{64}$', 'description' => 'The purchase\'s transaction hash.'],
          ],
        ]]],
      ],
      'responses' => [
        '201' => ['description' => 'Kept: id, url, the wallet that paid, and the listing\'s rating now.'],
        '422' => ['description' => 'errors: why the purchase does not back it.'],
        '403' => ['description' => 'Not signed in, or no X-CSRF-Token.'],
      ],
    ];
  }

  /**
   * One of our sellers as a paid operation; NULL when it is not for sale.
   */
  private function sale($listing): ?array {
    $item = X402PriceItem::find($listing, 'full');
    if (!$item || $listing->get('field_retired')->value) {
      return NULL;
    }
    return [
      'summary' => $listing->getTitle(),
      'description' => (string) $listing->get('body')->value,
      'requestBody' => [
        'required' => TRUE,
        'content' => ['application/json' => ['schema' => json_decode((string) $listing->get('field_input_schema')->value) ?: ['type' => 'object']]],
      ],
      'responses' => [
        '200' => ['description' => 'The service\'s answer. Settled only now: a seller that fails is not paid.', 'content' => ['application/json' => []]],
        '402' => ['description' => 'Payment required: the PAYMENT-REQUIRED header (base64 JSON) says what to sign. Send it again with PAYMENT-SIGNATURE.'],
        '400' => ['description' => 'Input the service cannot use. Not charged.'],
      ],
      'x-x402' => [
        'price' => $item->price(),
        'currency' => 'USDC',
        'network' => Sellers::network($listing),
      ],
    ];
  }

  /**
   * Our own listings.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface[]
   *   Keyed by id.
   */
  private function ours(): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'listing')->condition('field_source', 'ours')->condition('status', 1)->sort('product_id')->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * The term names of a vocabulary, sorted.
   */
  private function names(string $vid): array {
    $names = array_map(fn($term) => $term->label(), $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]));
    sort($names);
    return array_values(array_unique($names));
  }

}
