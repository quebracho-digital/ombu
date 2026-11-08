<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\mcp_server\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Drupal\ombu\Controller\CatalogueController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base of the MCP tools over the catalogue: free, read-only, public.
 *
 * They answer with what /market.json answers, because they call the same
 * controller; anonymous may use them, as anonymous may read the market.
 */
abstract class CatalogueToolBase extends ToolPluginBase {

  protected CatalogueController $catalogue;

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->catalogue = $container->get('class_resolver')->getInstanceFromDefinition(CatalogueController::class);
    $tool->entityTypeManager = $container->get('entity_type.manager');
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

}
