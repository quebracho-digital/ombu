<?php

/**
 * @file
 * Measures similar services engines against a labelled set.
 *
 *   drush php:script ../scripts/eval-similar.php pool   # what to label
 *   drush php:script ../scripts/eval-similar.php        # the scores
 *
 * The set is 50 listings, at most 8 from one provider (one has more than
 * half the catalogue). "pool" writes, for each, the candidates of every
 * engine's top 12 to scripts/eval/similar-pool.json; each candidate is
 * labelled in scripts/eval/similar-labels.json as "alternative" (a buyer
 * could use it instead), "works_with" (used together) or "unrelated".
 * Scoring counts alternatives: precision at 5 and at 12, the mean
 * reciprocal rank of the first one, alternatives from another provider in
 * the top 12 (what the cap per provider is for), and providers per list. A
 * candidate nobody labelled counts as unrelated and is reported, so a new
 * engine's unlabelled answers show up instead of passing silently.
 */

declare(strict_types=1);

use Drupal\views\Views;

const EVAL_DIR = __DIR__ . '/eval';
const EVAL_SIZE = 50;
const EVAL_PER_PROVIDER = 8;
const EVAL_TOP = 12;

$similar = \Drupal::service('ombu.similar');
$embeddings = \Drupal::service('ombu.embeddings');
$db = \Drupal::database();
$providers = $db->query('SELECT entity_id, field_provider_term_target_id FROM {commerce_product__field_provider_term} WHERE deleted = 0')->fetchAllKeyed();
$provider = fn (int $id) => $providers[$id] ?? -$id;

// Each engine: listing id => its top ids, best first.
$engines = [
  'similar' => fn (int $id) => array_slice(array_keys($similar->scores($id)), 0, EVAL_TOP),
  'vector' => fn (int $id) => array_slice(array_keys($embeddings->nearest($id)), 0, EVAL_TOP),
  'meilisearch' => function (int $id): array {
    $view = Views::getView('ombu_similar_meilisearch');
    if (!$view) {
      return [];
    }
    $view->setDisplay('embed_1');
    $view->setArguments([(string) $id]);
    $view->execute();
    $ids = [];
    foreach ($view->result as $row) {
      $ids[(int) $row->_object->getValue()->id()] = TRUE;
    }
    return array_slice(array_keys($ids), 0, EVAL_TOP);
  },
];

$text = function (int $id) use ($db): array {
  $row = $db->query("SELECT title, body_value FROM {commerce_product_field_data} d LEFT JOIN {commerce_product__body} b ON b.entity_id = d.product_id AND b.langcode = d.langcode WHERE d.product_id = :id AND d.default_langcode = 1", [':id' => $id])->fetchAssoc();
  $body = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['body_value'] ?? ''))));
  return ['title' => $row['title'] ?? '', 'body' => mb_substr($body, 0, 300)];
};

if (($extra[0] ?? '') === 'pool') {
  // Listings with a vector, in a fixed random order, at most
  // EVAL_PER_PROVIDER per provider.
  $candidates = $db->query("SELECT d.product_id FROM {commerce_product_field_data} d JOIN {ombu_listing_vector} v ON v.product_id = d.product_id WHERE d.type = 'listing' AND d.status = 1 AND d.default_langcode = 1 ORDER BY RAND(2026)")->fetchCol();
  $set = $count = [];
  foreach ($candidates as $id) {
    $p = $provider((int) $id);
    if (($count[$p] ?? 0) < EVAL_PER_PROVIDER) {
      $count[$p] = ($count[$p] ?? 0) + 1;
      $set[] = (int) $id;
    }
    if (count($set) === EVAL_SIZE) {
      break;
    }
  }
  $pool = [];
  foreach ($set as $id) {
    $found = [];
    foreach ($engines as $name => $engine) {
      foreach ($engine($id) as $other) {
        $found[$other][] = $name;
      }
    }
    ksort($found);
    $pool[$id] = $text($id) + ['candidates' => array_map(fn ($other, $by) => ['id' => $other] + $text($other) + ['engines' => $by], array_keys($found), $found)];
  }
  @mkdir(EVAL_DIR);
  file_put_contents(EVAL_DIR . '/similar-pool.json', json_encode($pool, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  echo count($pool) . ' listings, ' . array_sum(array_map(fn ($l) => count($l['candidates']), $pool)) . " candidates to label\n";
  return;
}

$labels = json_decode((string) @file_get_contents(EVAL_DIR . '/similar-labels.json'), TRUE);
if (!$labels) {
  throw new \RuntimeException('No scripts/eval/similar-labels.json: run "pool" and label it first.');
}
printf("%-12s %6s %6s %6s %8s %10s %11s\n", 'engine', 'P@5', 'P@12', 'MRR', 'other@12', 'providers', 'unlabelled');
foreach ($engines as $name => $engine) {
  $p5 = $p12 = $mrr = $other = $spread = $unlabelled = 0;
  foreach ($labels as $id => $judged) {
    $top = $engine((int) $id);
    $good = array_map(fn ($other) => ($judged[$other] ?? NULL) === 'alternative', $top);
    $unlabelled += count(array_diff($top, array_keys($judged)));
    $p5 += array_sum(array_slice($good, 0, 5)) / 5;
    $p12 += array_sum($good) / EVAL_TOP;
    $first = array_search(TRUE, $good, TRUE);
    $mrr += $first === FALSE ? 0 : 1 / ($first + 1);
    $other += count(array_filter($top, fn ($x) => ($judged[$x] ?? NULL) === 'alternative' && $provider($x) !== $provider((int) $id)));
    $spread += count(array_unique(array_map($provider, $top)));
  }
  $n = count($labels);
  printf("%-12s %6.2f %6.2f %6.2f %8.2f %10.1f %11d\n", $name, $p5 / $n, $p12 / $n, $mrr / $n, $other / $n, $spread / $n, $unlabelled);
}
