<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Listings like this one, by the category and tags they share.
 *
 * A term counts by how rare it is (log of listings over listings with it),
 * so "x402", on almost every listing, adds nothing. At most two per
 * provider: one provider has more than half the catalogue, and the block is
 * for alternatives.
 */
final class Similar {

  public const MAX = 6;

  /**
   * The ids of the listings most like this one, best first.
   */
  public static function of(ProductInterface $product): array {
    $terms = array_merge(
      array_column($product->get('field_category')->getValue(), 'target_id'),
      array_column($product->get('field_tags')->getValue(), 'target_id'),
    );
    if (!$terms) {
      return [];
    }
    // ponytail: scores every listing sharing a term on each uncached view
    // (23k term rows, ~2026-10); keep the scores in a table if it gets slow.
    $terms_sql = "SELECT entity_id, field_category_target_id tid FROM {commerce_product__field_category} WHERE deleted = 0
      UNION ALL SELECT entity_id, field_tags_target_id FROM {commerce_product__field_tags} WHERE deleted = 0";
    $rows = \Drupal::database()->query(
      "SELECT t.entity_id id, SUM(LOG(n.n / d.df)) score, MAX(p.field_provider_term_target_id) provider
       FROM ($terms_sql) t
       JOIN (SELECT tid, COUNT(*) df FROM ($terms_sql) x WHERE tid IN (:terms[]) GROUP BY tid) d ON d.tid = t.tid
       CROSS JOIN (SELECT COUNT(*) n FROM {commerce_product_field_data} WHERE type = 'listing' AND status = 1) n
       JOIN {commerce_product_field_data} l ON l.product_id = t.entity_id AND l.status = 1
       LEFT JOIN {commerce_product__field_retired} r ON r.entity_id = t.entity_id
       LEFT JOIN {commerce_product__field_provider_term} p ON p.entity_id = t.entity_id
       WHERE t.entity_id <> :id AND COALESCE(r.field_retired_value, 0) = 0
       GROUP BY t.entity_id
       HAVING score > 0
       ORDER BY score DESC, t.entity_id
       LIMIT 100",
      [':terms[]' => $terms, ':id' => $product->id()],
    )->fetchAll();

    $ids = [];
    $per_provider = [];
    foreach ($rows as $row) {
      $key = $row->provider ?? "none:$row->id";
      if (($per_provider[$key] = ($per_provider[$key] ?? 0) + 1) > 2) {
        continue;
      }
      $ids[] = (int) $row->id;
      if (count($ids) === self::MAX) {
        break;
      }
    }
    return $ids;
  }

  /**
   * The block under a listing: the similar ones, as cards.
   */
  public static function build(ProductInterface $product): array {
    $build = Cards::build(self::of($product), (string) t('Similar services'));
    $build['#attributes']['class'][] = 'ombu-similar';
    return $build;
  }

}
