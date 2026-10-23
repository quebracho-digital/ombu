<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutableFactory;

/**
 * Listings like this one: the terms they share, how close their texts are.
 *
 * - Terms: each term weighs log(listings / listings with it), so "x402", on
 *   almost every listing, adds nothing; each field has its own weight
 *   (field_kind, the internal category, counts once it is filled).
 * - Texts: cosine similarity of their embeddings (Embeddings), 0 to 1.
 *
 * The two lists are fused by their places (Reciprocal Rank Fusion), each
 * times its weight. At most a few per provider: one provider has more than
 * half the catalogue, and similar listings are for alternatives.
 *
 * The weights live in ombu.similar (Configuration » Search » Similar
 * services). Views reach this through Search API's "More like this" on the
 * database server (SimilarDatabase); on Meilisearch, its /similar answers.
 * Under a listing: the first of VIEWS that answers, else these scores as
 * cards. Service: ombu.similar.
 */
final class Similar {

  use StringTranslationTrait;

  public const MAX = 12;

  /**
   * Reciprocal Rank Fusion's constant.
   *
   * A list's first place adds weight / 61, its tenth weight / 70, so no
   * single list decides.
   */
  public const RRF_K = 60;

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
   * The views that list them under a listing, preferred first.
   *
   * Their "embed_1" display takes the listing's id.
   */
  public const VIEWS = ['ombu_similar_meilisearch', 'ombu_similar'];

  /**
   * Published, not retired listings and who sells each, as SQL.
   *
   * A listing without a provider is its own. Both candidate lists cut at
   * 100 after keeping at most per_provider of each: one provider has more
   * than half the catalogue, and cut first, its listings would fill the 100
   * and leave two after the cap.
   */
  private const LIVE = "SELECT l.product_id, COALESCE(p.field_provider_term_target_id, -l.product_id) provider
    FROM {commerce_product_field_data} l
    LEFT JOIN {commerce_product__field_retired} r ON r.entity_id = l.product_id AND r.deleted = 0
    LEFT JOIN {commerce_product__field_provider_term} p ON p.entity_id = l.product_id AND p.deleted = 0
    WHERE l.type = 'listing' AND l.status = 1 AND l.default_langcode = 1 AND COALESCE(r.field_retired_value, 0) = 0";

  /**
   * How long a server's answer to a ping is trusted, in seconds.
   */
  private const PING_TTL = 60;

  /**
   * Whether each server answers, within this request: server id => bool.
   *
   * @var array<string, bool>
   */
  private array $answers = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly Embeddings $embeddings,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The ids of the listings most like this one, best first.
   */
  public function of(ProductInterface $product): array {
    return array_slice(array_keys($this->scores((int) $product->id())), 0, self::MAX);
  }

  /**
   * Published, not retired listings like this one: id => score, best first.
   */
  public function scores(int $id): array {
    return array_map(fn (array $why) => $why['score'], $this->explain($id));
  }

  /**
   * Why each listing is like this one: id => reasons, best first.
   *
   * - score: the lists' Reciprocal Rank Fusion, each list times its weight:
   *   weight / (RRF_K + place in it). Places, not raw scores, so lists on
   *   different scales (rarity sums, cosines) add up fairly.
   * - terms, terms_rank: the shared terms' weighted rarity and its place,
   *   NULL when none is shared.
   * - vector, vector_rank: the texts' similarity and its place, NULL
   *   without vectors.
   * - provider: who sells it (its term id; NULL for none).
   *
   * Cached until a listing, its vector or the weights change: computing
   * them reads every listing sharing a term and every vector.
   */
  public function explain(int $id): array {
    $cid = "ombu.similar:explain:$id";
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $explain = $this->compute($id);
    $tags = ['commerce_product_list', 'config:ombu.similar'];
    $this->cache->set($cid, $explain, CacheBackendInterface::CACHE_PERMANENT, $tags);
    return $explain;
  }

  /**
   * Computes explain().
   */
  private function compute(int $id): array {
    $settings = ($this->configFactory->get('ombu.similar')->get() ?: []) + self::DEFAULTS;
    $per_provider = max(1, (int) $settings['per_provider']);
    $why = [];
    $place = 0;
    foreach ($this->termScores($id, $settings['fields'], $per_provider) as $other => $score) {
      $why[$other] = ['terms' => (float) $score, 'terms_rank' => ++$place];
    }
    if ($settings['vector'] > 0) {
      $place = 0;
      foreach ($this->vectorScores($id, $per_provider) as $other => $similarity) {
        $why[$other] = ($why[$other] ?? []) + ['vector' => $similarity, 'vector_rank' => ++$place];
      }
    }
    if (!$why) {
      return [];
    }
    foreach ($why as &$reasons) {
      $reasons += ['terms' => NULL, 'terms_rank' => NULL, 'vector' => NULL, 'vector_rank' => NULL];
      $reasons['score'] = ($reasons['terms_rank'] ? $settings['terms'] / (self::RRF_K + $reasons['terms_rank']) : 0)
        + ($reasons['vector_rank'] ? $settings['vector'] / (self::RRF_K + $reasons['vector_rank']) : 0);
    }
    unset($reasons);
    // Best first; ties by id, so every caller cuts the same list.
    uksort($why, fn ($a, $b) => [$why[$b]['score'], $a] <=> [$why[$a]['score'], $b]);

    // Who sells each, and only those people can buy.
    $providers = $this->database->query(
      "SELECT l.product_id, p.field_provider_term_target_id FROM {commerce_product_field_data} l
       LEFT JOIN {commerce_product__field_retired} r ON r.entity_id = l.product_id
       LEFT JOIN {commerce_product__field_provider_term} p ON p.entity_id = l.product_id
       WHERE l.product_id IN (:ids[]) AND l.status = 1 AND COALESCE(r.field_retired_value, 0) = 0",
      [':ids[]' => array_keys($why)],
    )->fetchAllKeyed();
    $out = [];
    $count = [];
    foreach ($why as $other => $reasons) {
      if (!array_key_exists($other, $providers)) {
        continue;
      }
      $key = $providers[$other] ?? "none:$other";
      if (($count[$key] = ($count[$key] ?? 0) + 1) <= $per_provider) {
        $out[$other] = $reasons + ['provider' => $providers[$other] === NULL ? NULL : (int) $providers[$other]];
      }
    }
    return $out;
  }

  /**
   * The block under a listing: the first view that answers, else cards.
   *
   * Wrapped with the listing's id and the engine, for similar.js to report
   * what was shown and clicked (SimilarLog).
   */
  public function build(ProductInterface $product): array {
    $suggestion = $this->suggest($product);
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ombu-similar'],
        'data-ombu-similar' => $product->id(),
        'data-ombu-engine' => $suggestion['engine'],
      ],
      '#attached' => ['library' => ['ombu/similar']],
      'similar' => $suggestion['view']
        ? ($suggestion['view']->render() ?? [])
        : Cards::build(array_keys($suggestion['items']), (string) $this->t('Similar services'), TRUE, TRUE),
      '#cache' => ['tags' => ['config:ombu.similar']],
    ];
    // A fallback lasts until the view it stands in for answers again.
    if ($suggestion['fell_back']) {
      $build['#cache']['max-age'] = self::PING_TTL;
    }
    return $build;
  }

  /**
   * The ids the block shows, in its order.
   */
  public function ids(ProductInterface $product): array {
    return array_keys($this->suggest($product)['items']);
  }

  /**
   * What the block under a listing shows, and who chose it.
   *
   * @return array
   *   - engine: the view that answered (one of VIEWS), or "scores" for
   *     Similar's own cards.
   *   - fell_back: whether a preferred view was skipped, its server down.
   *   - items: listing id => the engine's score, in order. A listing's
   *     translations are items of their own in a view: listed once.
   *   - view: the executed view, NULL for the cards.
   */
  public function suggest(ProductInterface $product): array {
    [$view, $fell_back] = $this->executedView($product);
    if (!$view) {
      return [
        'engine' => 'scores',
        'fell_back' => $fell_back,
        'items' => array_slice($this->scores((int) $product->id()), 0, self::MAX, TRUE),
        'view' => NULL,
      ];
    }
    $items = [];
    foreach ($view->result as $row) {
      // Search API's rows carry the item as dynamic properties.
      $items[(int) $row->_object->getValue()->id()] ??= (float) $row->_item->getScore();
    }
    return ['engine' => $view->storage->id(), 'fell_back' => $fell_back, 'items' => $items, 'view' => $view];
  }

  /**
   * The first of VIEWS that answers for this listing, executed.
   *
   * A view answers when it is enabled, its server answered a ping in the
   * last minute and its search did not fail; a search that fails marks the
   * server down for that minute, and the next view is tried. A view whose
   * argument validation refuses the listing answers too, with nothing.
   *
   * @return array
   *   The view, or NULL when none answers; and whether an enabled view was
   *   skipped because its server is down.
   */
  private function executedView(ProductInterface $product): array {
    $fell_back = FALSE;
    foreach (self::VIEWS as $name) {
      $storage = $this->entityTypeManager->getStorage('view')->load($name);
      if (!$storage instanceof ViewEntityInterface || !$storage->status() || !($server = $this->server($storage))) {
        continue;
      }
      if ($this->answers($server)) {
        $view = $this->viewExecutableFactory->get($storage);
        $view->setDisplay('embed_1');
        $view->setArguments([(string) $product->id()]);
        $view->execute();
        if (!empty($view->build_info['fail']) || !$view->query instanceof SearchApiQuery || !$view->query->shouldAbort()) {
          return [$view, $fell_back];
        }
        $this->remember($server, FALSE);
      }
      $fell_back = TRUE;
    }
    return [NULL, $fell_back];
  }

  /**
   * The id of the Search API server under a view, NULL if it has none.
   */
  private function server(ViewEntityInterface $view): ?string {
    $index = SearchApiQuery::getIndexFromTable((string) $view->get('base_table'), $this->entityTypeManager);
    return $index?->status() ? $index->getServerId() : NULL;
  }

  /**
   * Whether a server answers: asked once a minute, not on every page.
   */
  private function answers(string $server_id): bool {
    if (isset($this->answers[$server_id])) {
      return $this->answers[$server_id];
    }
    $cached = $this->cache->get("ombu.similar:answers:$server_id");
    if ($cached) {
      return $this->answers[$server_id] = (bool) $cached->data;
    }
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);
    return $this->remember($server_id, (bool) $server?->status() && $server->isAvailable());
  }

  /**
   * Keeps whether a server answers, for this request and the next minute.
   */
  private function remember(string $server_id, bool $answers): bool {
    $this->cache->set("ombu.similar:answers:$server_id", $answers, $this->time->getRequestTime() + self::PING_TTL);
    return $this->answers[$server_id] = $answers;
  }

  /**
   * Listings sharing a term with this one: id => weighted rarity, top 100.
   *
   * @param int $id
   *   The listing.
   * @param array $fields
   *   Term reference field => weight; fields not on the site are skipped.
   * @param int $per_provider
   *   At most this many of each provider among the 100.
   */
  private function termScores(int $id, array $fields, int $per_provider): array {
    $storage = $this->entityTypeManager->getStorage('field_storage_config');
    $selects = [];
    foreach (array_filter($fields) as $field => $weight) {
      // Only fields that exist: their names go into the SQL.
      if ($storage->load("commerce_product.$field")) {
        $selects[] = sprintf('SELECT entity_id, %s_target_id tid, %F w FROM {commerce_product__%s} WHERE deleted = 0', $field, $weight, $field);
      }
    }
    if (!$selects) {
      return [];
    }
    $terms = implode(' UNION ALL ', $selects);
    // ponytail: scores every listing sharing a term on each uncached view
    // (23k term rows, ~2026-10); keep the scores in a table if it gets slow.
    return $this->database->query(
      "SELECT entity_id, score FROM (
         SELECT s.entity_id, s.score, ROW_NUMBER() OVER (PARTITION BY live.provider ORDER BY s.score DESC, s.entity_id) nth
         FROM (
           SELECT t.entity_id, SUM(t.w * LOG(n.n / d.df)) score
           FROM ($terms) t
           JOIN (SELECT tid, COUNT(*) df FROM ($terms) x
                 WHERE tid IN (SELECT tid FROM ($terms) mine WHERE entity_id = :id) GROUP BY tid) d ON d.tid = t.tid
           CROSS JOIN (SELECT COUNT(*) n FROM {commerce_product_field_data} WHERE type = 'listing' AND status = 1) n
           WHERE t.entity_id <> :id
           GROUP BY t.entity_id
           HAVING score > 0
         ) s
         JOIN (" . self::LIVE . ") live ON live.product_id = s.entity_id
       ) ranked
       WHERE nth <= :per_provider
       ORDER BY score DESC, entity_id
       LIMIT 100",
      [':id' => $id, ':per_provider' => $per_provider],
    )->fetchAllKeyed();
  }

  /**
   * Listings whose texts are closest to this one's: id => similarity.
   *
   * Top 100, 1 - cosine distance; empty until it has a vector.
   *
   * @param int $id
   *   The listing.
   * @param int $per_provider
   *   At most this many of each provider among the 100.
   */
  private function vectorScores(int $id, int $per_provider): array {
    if (!$this->embeddings->available()) {
      return [];
    }
    $table = '{' . Embeddings::TABLE . '}';
    // ponytail: a full scan, as Embeddings::nearest().
    return array_map('floatval', $this->database->query(
      "SELECT product_id, similarity FROM (
         SELECT v.product_id, 1 - VEC_DISTANCE_COSINE(v.embedding, me.embedding) similarity,
           ROW_NUMBER() OVER (PARTITION BY live.provider ORDER BY VEC_DISTANCE_COSINE(v.embedding, me.embedding), v.product_id) nth
         FROM $table v
         JOIN $table me ON me.product_id = :id
         JOIN (" . self::LIVE . ") live ON live.product_id = v.product_id
         WHERE v.product_id <> :id
       ) ranked
       WHERE nth <= :per_provider
       ORDER BY similarity DESC, product_id
       LIMIT 100",
      [':id' => $id, ':per_provider' => $per_provider],
    )->fetchAllKeyed());
  }

}
