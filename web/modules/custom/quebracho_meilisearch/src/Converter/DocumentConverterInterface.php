<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Converter;

/**
 * Converts Search API items to Meilisearch documents.
 */
interface DocumentConverterInterface {

  /**
   * The primary key of every document.
   *
   * Search API reserves the "search_api_" prefix, so no indexed field can
   * use this name.
   */
  public const PRIMARY_KEY = 'search_api_document_id';

  /**
   * Fields every document carries, besides the indexed fields.
   */
  public const SPECIAL_FIELDS = ['search_api_id', 'search_api_datasource', 'search_api_language'];

  /**
   * Converts Search API items to Meilisearch documents.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   Search API items keyed by item ID.
   *
   * @return array[]
   *   Documents, each with the primary key and the special fields.
   */
  public function convertToDocuments(array $items): array;

}
