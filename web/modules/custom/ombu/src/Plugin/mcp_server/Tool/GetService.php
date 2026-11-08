<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\mcp_server\Tool;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Symfony\Component\HttpFoundation\Request;

/**
 * One service of the market, as /market.json shows it.
 */
#[Tool(
  id: 'ombu_get_service',
  label: new TranslatableMarkup('Get a service'),
  description: new TranslatableMarkup('Returns one service of the Ombú market by its id (the number in its market URL, /market/listing/{id}): how to call it, what it charges, input and output schemas, provider and rating. Free.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'id' => ['type' => 'integer', 'description' => 'The listing id.'],
    ],
    'required' => ['id'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetService extends CatalogueToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $product = $this->entityTypeManager->getStorage('commerce_product')->load((int) ($arguments['id'] ?? 0));
    // Only what anonymous sees on /market: published listings.
    if (!$product instanceof ProductInterface || !$product->isPublished() || $product->bundle() !== 'listing') {
      throw new ToolCallException('No such service.');
    }
    return $this->catalogue->item($product, Request::create('/market.json'));
  }

}
