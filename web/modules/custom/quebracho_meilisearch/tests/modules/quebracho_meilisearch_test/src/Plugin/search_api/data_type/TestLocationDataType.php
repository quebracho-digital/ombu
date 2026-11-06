<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_test\Plugin\search_api\data_type;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiDataType;
use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Stands in for search_api_location's "location" type: "lat,lon" strings.
 */
#[SearchApiDataType(
  id: 'location',
  label: new TranslatableMarkup('Test location'),
  fallback_type: 'string',
)]
class TestLocationDataType extends DataTypePluginBase {
}
