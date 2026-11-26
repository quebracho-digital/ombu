<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\facets\processor;

/**
 * Shows a listing's on-chain activity by its name ("With transactions").
 *
 * @FacetsProcessor(
 *   id = "ombu_activity_label",
 *   label = @Translation("Activity names"),
 *   description = @Translation("Shows a listing's on-chain activity by its name instead of its key."),
 *   stages = {
 *     "post_query" = 50
 *   }
 * )
 */
final class ActivityLabelProcessor extends ListLabelProcessorBase {

  /**
   * {@inheritdoc}
   */
  protected function field(): string {
    return 'field_activity';
  }

}
