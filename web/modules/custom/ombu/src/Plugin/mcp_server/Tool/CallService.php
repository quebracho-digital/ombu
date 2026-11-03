<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\ombu\Mcp\McpPayments;
use Drupal\ombu\Sellers;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Buys a call to one of our sellers, paid over x402 in the MCP call itself.
 *
 * The payment is McpPayments' (x402 MCP transport): this runs only once it
 * admitted the call. A failing seller throws, so the call is not charged.
 */
#[Tool(
  id: McpPayments::TOOL,
  label: new TranslatableMarkup('Call a service'),
  description: new TranslatableMarkup('Calls one of the services Ombú sells itself (ping, page to markdown, terms analysis, fact-check, name check; find them with ombu_search_services, seller "ours", and use the id in their market URL). It costs what the service charges: without payment the result is an error with the x402 PaymentRequired; pay it and call again with the payment in _meta["x402/payment"]. A failing call is not charged.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'id' => ['type' => 'integer', 'description' => 'The listing id.'],
      'input' => ['type' => ['object', 'array'], 'description' => "The service's input, as its listing describes it."],
    ],
    'required' => ['id'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: TRUE,
)]
final class CallService extends CatalogueToolBase {

  private McpPayments $payments;

  private Sellers $sellers;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->payments = $container->get('ombu.mcp_payments');
    $tool->sellers = $container->get('ombu.sellers');
    return $tool;
  }

  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $product = $this->payments->listing(['name' => McpPayments::TOOL, 'arguments' => $arguments])
      ?? throw new ToolCallException('No such service for sale.');
    // Not admitted by the payment middleware (another transport): sell nothing.
    if (!$this->payments->isPaid($product)) {
      throw new ToolCallException('Payment required.');
    }
    $input = is_array($arguments['input'] ?? NULL) ? $arguments['input'] : [];
    $response = $this->sellers->deliver((string) $product->get('field_service')->value, $product, Request::create('/', 'POST', [], [], [], [], json_encode($input)));
    $answer = json_decode((string) $response->getContent(), TRUE);
    if (!$response->isSuccessful()) {
      throw new ToolCallException(is_array($answer) && isset($answer['error']) ? (string) $answer['error'] : 'The service failed; not charged.');
    }
    return $answer;
  }

}
