<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Lets a Feeds processor other than the entity one keep its settings.
 *
 * Feeds describes every feed type's processor configuration as its entity
 * processor's, whatever the processor is. Ombú's listing processors have
 * settings of their own (the source, the verification budget, the
 * filters), so the processor's configuration is typed by the processor's
 * id, as Feeds already does for fetchers and parsers; any other processor
 * keeps the entity processor's description.
 */
final class CatalogFeedHooks {

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    // feeds.processor.* (any other processor, as the entity one) is in
    // ombu.schema.yml: this hook may change definitions, not add them.
    if (!isset($definitions['feeds.feed_type.*']['mapping']['processor_configuration'], $definitions['feeds.processor.*'])) {
      return;
    }
    $definitions['feeds.feed_type.*']['mapping']['processor_configuration']['type'] = 'feeds.processor.[%parent.processor]';
  }

}
