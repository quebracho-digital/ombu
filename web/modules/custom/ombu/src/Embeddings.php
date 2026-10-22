<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\Cache;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntity;

/**
 * Each listing's text as a vector, in ombu_listing_vector (MariaDB VECTOR).
 *
 * The model is AI's default for embeddings (Fireworks' qwen3-embedding-8b).
 * A text is embedded once: the row keeps sha256(model + text), a listing
 * whose text did not change is skipped, and the same text on another
 * listing is copied, not asked again. It is a table, not a cache bin, so
 * clearing caches costs nothing. Calls run from the ombu_embed queue on
 * cron, never while a page is built.
 */
final class Embeddings {

  public const TABLE = 'ombu_listing_vector';

  public const QUEUE = 'ombu_embed';

  /**
   * The text that stands for a listing: what it does, not who sells it.
   *
   * The provider is left out on purpose: similar listings are for
   * alternatives from other sellers.
   */
  public static function text(ProductInterface $listing): string {
    $terms = fn (string $field) => $listing->hasField($field)
      ? implode(', ', array_map(fn ($t) => $t->label(), $listing->get($field)->referencedEntities()))
      : '';
    return trim(implode("\n", array_filter([
      (string) $listing->label(),
      $terms('field_category'),
      $terms('field_tags'),
      trim(strip_tags((string) $listing->get('body')->value)),
    ])));
  }

  /**
   * AI's default embeddings provider and model, or NULL when none is set.
   */
  public static function model(): ?array {
    return \Drupal::service('ai.provider')->getDefaultProviderForOperationType('embeddings') ?: NULL;
  }

  /**
   * The hash a listing's row must carry to be current.
   *
   * NULL without a model.
   */
  public static function hash(ProductInterface $listing): ?string {
    $model = self::model();
    return $model ? hash('sha256', $model['provider_id'] . ':' . $model['model_id'] . "\n" . self::text($listing)) : NULL;
  }

  /**
   * Whether the listing's row is missing or out of date.
   */
  public static function stale(ProductInterface $listing): bool {
    if (!self::available() || !($hash = self::hash($listing))) {
      return FALSE;
    }
    return \Drupal::database()->query('SELECT hash FROM {' . self::TABLE . '} WHERE product_id = :id', [':id' => $listing->id()])->fetchField() !== $hash;
  }

  /**
   * Embeds the listing if its text changed.
   *
   * @return bool
   *   TRUE if the model was called.
   */
  public static function update(ProductInterface $listing): bool {
    $db = \Drupal::database();
    if (!self::stale($listing)) {
      return FALSE;
    }
    $hash = self::hash($listing);
    $table = '{' . self::TABLE . '}';
    // The same text on another listing: copy its vector.
    // Copied if the row now holds this hash. Not rowCount(): Drupal refuses it
    // on a raw query.
    $db->query("INSERT INTO $table (product_id, hash, embedding)
      SELECT :id, hash, embedding FROM $table WHERE hash = :hash LIMIT 1
      ON DUPLICATE KEY UPDATE hash = VALUES(hash), embedding = VALUES(embedding)",
      [':id' => $listing->id(), ':hash' => $hash]);
    if ($db->query("SELECT hash FROM $table WHERE product_id = :id", [':id' => $listing->id()])->fetchField() === $hash) {
      self::changed($listing);
      return FALSE;
    }
    $model = self::model();
    $vector = \Drupal::service('ai.provider')->createInstance($model['provider_id'])
      ->embeddings(new EmbeddingsInput(self::text($listing)), $model['model_id'], ['ombu', 'ombu_similar'])
      ->getNormalized();
    $db->query("INSERT INTO $table (product_id, hash, embedding) VALUES (:id, :hash, VEC_FromText(:v))
      ON DUPLICATE KEY UPDATE hash = VALUES(hash), embedding = VALUES(embedding)",
      [':id' => $listing->id(), ':hash' => $hash, ':v' => json_encode($vector)]);
    self::changed($listing);
    return TRUE;
  }

  /**
   * The vectors of these listings: id => floats; none for those without.
   *
   * @param int[] $ids
   *   Listing ids.
   */
  public static function vectors(array $ids): array {
    if (!self::available() || !$ids) {
      return [];
    }
    $rows = \Drupal::database()->query('SELECT product_id, VEC_ToText(embedding) FROM {' . self::TABLE . '} WHERE product_id IN (:ids[])', [':ids[]' => array_values($ids)])->fetchAllKeyed();
    return array_map(fn ($text) => json_decode($text), $rows);
  }

  /**
   * A listing's vector changed.
   *
   * Similar listings shown before it are out of date, and indexes that send
   * vectors along (on a server with "Vectors provided by Drupal", see
   * SimilarHooks) must index it again; the others do not hold vectors.
   */
  private static function changed(ProductInterface $listing): void {
    Cache::invalidateTags(['search_api_list:listings', 'commerce_product_list']);
    $item_ids = array_map(fn ($langcode) => $listing->id() . ':' . $langcode, array_keys($listing->getTranslationLanguages()));
    foreach (ContentEntity::getIndexesForEntity($listing) as $index) {
      if (!empty($index->getServerInstanceIfAvailable()?->getBackendConfig()['embedder_dimensions'])) {
        $index->trackItemsUpdated('entity:commerce_product', $item_ids);
      }
    }
  }

  /**
   * The listings whose texts are closest to this one's, top 100.
   *
   * Id => similarity (1 - cosine distance); empty until it has a vector.
   */
  public static function nearest(int $id): array {
    if (!self::available()) {
      return [];
    }
    $table = '{' . self::TABLE . '}';
    // ponytail: a full scan, no vector index (Schema API cannot declare
    // one); 2.8k rows of 4096 floats take milliseconds. Add
    // "VECTOR INDEX (embedding) DISTANCE=cosine" by hand past ~100k rows.
    return array_map('floatval', \Drupal::database()->query(
      "SELECT v.product_id, 1 - VEC_DISTANCE_COSINE(v.embedding, me.embedding) similarity
       FROM $table v JOIN $table me ON me.product_id = :id
       WHERE v.product_id <> :id
       ORDER BY VEC_DISTANCE_COSINE(v.embedding, me.embedding)
       LIMIT 100",
      [':id' => $id],
    )->fetchAllKeyed());
  }

  /**
   * Whether the table exists: only MariaDB 11.7+ has VECTOR.
   */
  public static function available(): bool {
    return self::$exists ??= \Drupal::database()->schema()->tableExists(self::TABLE);
  }

  /**
   * Whether the table exists, once per request.
   */
  private static ?bool $exists = NULL;

}
