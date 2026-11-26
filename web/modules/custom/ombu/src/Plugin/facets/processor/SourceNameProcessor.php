<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\facets\processor;

/**
 * Shows where a listing comes from by its name ("Coinbase Bazaar").
 *
 * @FacetsProcessor(
 *   id = "ombu_source_name",
 *   label = @Translation("Source names"),
 *   description = @Translation("Shows a listing's source by its name instead of its key."),
 *   stages = {
 *     "post_query" = 50
 *   }
 * )
 */
final class SourceNameProcessor extends ListLabelProcessorBase {

  /**
   * {@inheritdoc}
   */
  protected function field(): string {
    return 'field_source';
  }

}
