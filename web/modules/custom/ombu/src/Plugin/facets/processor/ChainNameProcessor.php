<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\facets\processor;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\PostQueryProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\ombu\Chains;

/**
 * Shows a chain's name ("Base") instead of its CAIP-2 id.
 *
 * @FacetsProcessor(
 *   id = "ombu_chain_name",
 *   label = @Translation("Chain names"),
 *   description = @Translation("Shows a chain by name instead of its CAIP-2 id."),
 *   stages = {
 *     "post_query" = 50
 *   }
 * )
 */
final class ChainNameProcessor extends ProcessorPluginBase implements PostQueryProcessorInterface {

  use UnchangingCacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  public function postQuery(FacetInterface $facet) {
    foreach ($facet->getResults() as $result) {
      $result->setDisplayValue(Chains::name((string) $result->getRawValue()));
    }
  }

}
