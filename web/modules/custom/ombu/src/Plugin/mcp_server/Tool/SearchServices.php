<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search the market: the same search as /market.json.
 */
#[Tool(
  id: 'ombu_search_services',
  label: new TranslatableMarkup('Search services'),
  description: new TranslatableMarkup('Searches the Ombú market of pay-per-call x402 services for AI agents. Returns the services (resource URL, price and network in "accepts", provider, rating) and the filters that narrow the search. Free.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'search' => ['type' => 'string', 'description' => 'Words to search for.'],
      'category' => ['type' => 'string', 'description' => 'A category from the facets of a previous search.'],
      'network' => ['type' => 'string', 'description' => 'CAIP-2 network id, e.g. eip155:8453.'],
      'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
      'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
    ],
    'additionalProperties' => FALSE,
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SearchServices extends CatalogueToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $arguments += ['limit' => 10];
    $arguments['limit'] = min(50, (int) $arguments['limit']);
    $request = Request::create('/market.json', 'GET', array_filter(array_intersect_key($arguments, array_flip([
      'search',
      'category',
      'network',
      'limit',
      'offset',
    ])), fn($v) => $v !== '' && $v !== NULL));
    $search = $this->catalogue->search($request);
    return [
      'items' => array_map(fn($p) => $this->catalogue->item($p, $request), $search['products']),
      'pagination' => ['limit' => $search['limit'], 'offset' => $search['offset'], 'total' => $search['total']],
      'facets' => $search['facets'],
    ];
  }

}
