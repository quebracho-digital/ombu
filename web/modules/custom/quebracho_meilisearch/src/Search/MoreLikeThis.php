<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Search;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;

/**
 * Finds the reference items of Search API's "More like this" option.
 *
 * The "id" of the search_api_mlt option is an item ID or, as the Views
 * contextual filter passes it, an entity ID. An entity stands for its items
 * (its translations): the first one indexed is the reference, and none of
 * them is a result. Access to the reference is not checked here, as in
 * other backends: validate the Views argument for that. Results go through
 * Search API's access processors like any search.
 *
 * @internal
 *   Use the meilisearch.more_like_this service, by its interface.
 */
final class MoreLikeThis implements MoreLikeThisInterface {

  /**
   * Constructs a MoreLikeThis.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function referenceItemIds(QueryInterface $query, array $mlt): array {
    $index = $query->getIndex();
    $id = (string) ($mlt['id'] ?? '');
    [$datasource_id] = Utility::splitCombinedId($id);
    if ($datasource_id !== NULL && $index->isValidDatasource($datasource_id)) {
      return [$id];
    }
    $datasources = $index->getDatasources();
    if (isset($mlt['datasource'])) {
      $datasources = array_intersect_key($datasources, [$mlt['datasource'] => TRUE]);
    }
    $languages = $query->getLanguages();
    $item_ids = [];
    foreach ($datasources as $datasource_id => $datasource) {
      $entity_type_id = $datasource->getEntityTypeId();
      // A datasource whose entity type is gone has nothing to find.
      $entity = $entity_type_id && $this->entityTypeManager->hasDefinition($entity_type_id)
        ? $this->entityTypeManager->getStorage($entity_type_id)->load($id)
        : NULL;
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      $langcodes = array_keys($entity->getTranslationLanguages());
      if ($languages !== NULL) {
        $langcodes = array_intersect($languages, $langcodes);
      }
      foreach ($langcodes as $langcode) {
        $item_ids[] = Utility::createCombinedId($datasource_id, (string) $datasource->getItemId($entity->getTranslation($langcode)->getTypedData()));
      }
    }
    return $item_ids;
  }

}
