<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Listings like this one: the terms they share, how close their texts are.
 *
 * - Terms: each term weighs log(listings / listings with it), so "x402", on
 *   almost every listing, adds nothing; each field has its own weight
 *   (field_kind, the internal category, counts once it is filled).
 * - Texts: cosine similarity of their embeddings (Embeddings), 0 to 1.
 *
 * The terms' score is scaled to 0–1 by the best candidate's, then both are
 * weighted and added. At most a few per provider: one provider has more
 * than half the catalogue, and similar listings are for alternatives.
 *
 * The weights live in ombu.similar (Configuration » Search » Similar
 * services). Views reach this through Search API's "More like this"
 * (SimilarHooks); the block under a listing calls it directly when the
 * ombu_similar view is off.
 */
final class Similar {

  public const MAX = 12;

  /**
   * Used when ombu.similar is missing or lacks a key.
   */
  public const DEFAULTS = [
    'terms' => 1.0,
    'vector' => 1.0,
    'per_provider' => 2,
    'fields' => ['field_category' => 1.0, 'field_tags' => 1.0, 'field_kind' => 2.0],
  ];

  /**
   * The ids of the listings most like this one, best first.
   */
  public static function of(ProductInterface $product): array {
    return array_slice(array_keys(self::scores((int) $product->id())), 0, self::MAX);
  }

  /**
   * The view that lists them under a listing, NULL when both are off.
   *
   * Its copy on the Meilisearch index where that is enabled and answers,
   * else ombu_similar, so admins can change what is shown.
   */
  public static function view(): ?string {
    return self::$view ??= current(array_filter(['ombu_similar_meilisearch', 'ombu_similar'], function (string $name): bool {
      $view = View::load($name);
      if (!$view?->status()) {
        return FALSE;
      }
      $index = SearchApiQuery::getIndexFromTable((string) $view->get('base_table'));
      return (bool) $index?->status() && (bool) $index->getServerInstanceIfAvailable()?->isAvailable();
    })) ?: NULL;
  }

  /**
   * The view's name, once per request: a Meilisearch server is asked.
   */
  private static ?string $view = NULL;

  /**
   * The ids the page shows: the view's results, or of()'s without a view.
   */
  public static function ids(ProductInterface $product): array {
    $name = self::view();
    if (!$name) {
      return self::of($product);
    }
    $view = Views::getView($name);
    $view->setDisplay('embed_1');
    $view->setArguments([$product->id()]);
    $view->execute();
    // A listing's translations are items of their own: list it once.
    // Search API's rows carry the item as a dynamic property.
    // @phpstan-ignore property.notFound
    return array_values(array_unique(array_map(fn ($row) => (int) $row->_object->getValue()->id(), $view->result)));
  }

  /**
   * Published, not retired listings like this one: id => score, best first.
   */
  public static function scores(int $id): array {
    $settings = (\Drupal::config('ombu.similar')->get() ?: []) + self::DEFAULTS;
    $terms = self::termScores($id, $settings['fields']);
    $best = $terms ? max($terms) : 0;
    $scores = [];
    foreach ($terms as $other => $score) {
      $scores[$other] = $settings['terms'] * $score / $best;
    }
    if ($settings['vector'] > 0) {
      foreach (Embeddings::nearest($id) as $other => $similarity) {
        $scores[$other] = ($scores[$other] ?? 0) + $settings['vector'] * $similarity;
      }
    }
    if (!$scores) {
      return [];
    }
    // Best first; ties by id, so every caller cuts the same list.
    uksort($scores, fn ($a, $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);

    // Who sells each, and only those people can buy.
    $providers = \Drupal::database()->query(
      "SELECT l.product_id, p.field_provider_term_target_id FROM {commerce_product_field_data} l
       LEFT JOIN {commerce_product__field_retired} r ON r.entity_id = l.product_id
       LEFT JOIN {commerce_product__field_provider_term} p ON p.entity_id = l.product_id
       WHERE l.product_id IN (:ids[]) AND l.status = 1 AND COALESCE(r.field_retired_value, 0) = 0",
      [':ids[]' => array_keys($scores)],
    )->fetchAllKeyed();
    $out = [];
    $per_provider = [];
    foreach ($scores as $other => $score) {
      if (!array_key_exists($other, $providers)) {
        continue;
      }
      $key = $providers[$other] ?? "none:$other";
      if (($per_provider[$key] = ($per_provider[$key] ?? 0) + 1) <= $settings['per_provider']) {
        $out[$other] = $score;
      }
    }
    return $out;
  }

  /**
   * The block under a listing: the similar ones, as cards.
   */
  public static function build(ProductInterface $product): array {
    $build = Cards::build(self::of($product), (string) t('Similar services'), TRUE, TRUE);
    $build['#cache']['tags'][] = 'config:ombu.similar';
    $build['#attributes']['class'][] = 'ombu-similar';
    return $build;
  }

  /**
   * Listings sharing a term with this one: id => weighted rarity, top 100.
   *
   * @param int $id
   *   The listing.
   * @param array $fields
   *   Term reference field => weight; fields not on the site are skipped.
   */
  private static function termScores(int $id, array $fields): array {
    $selects = [];
    foreach (array_filter($fields) as $field => $weight) {
      if (FieldStorageConfig::loadByName('commerce_product', $field)) {
        $selects[] = sprintf('SELECT entity_id, %s_target_id tid, %F w FROM {commerce_product__%s} WHERE deleted = 0', $field, $weight, $field);
      }
    }
    if (!$selects) {
      return [];
    }
    $terms = implode(' UNION ALL ', $selects);
    // ponytail: scores every listing sharing a term on each uncached view
    // (23k term rows, ~2026-10); keep the scores in a table if it gets slow.
    return \Drupal::database()->query(
      "SELECT t.entity_id, SUM(t.w * LOG(n.n / d.df)) score
       FROM ($terms) t
       JOIN (SELECT tid, COUNT(*) df FROM ($terms) x
             WHERE tid IN (SELECT tid FROM ($terms) mine WHERE entity_id = :id) GROUP BY tid) d ON d.tid = t.tid
       CROSS JOIN (SELECT COUNT(*) n FROM {commerce_product_field_data} WHERE type = 'listing' AND status = 1) n
       WHERE t.entity_id <> :id
       GROUP BY t.entity_id
       HAVING score > 0
       ORDER BY score DESC
       LIMIT 100",
      [':id' => $id],
    )->fetchAllKeyed();
  }

}
