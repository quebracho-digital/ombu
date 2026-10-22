<?php

/**
 * @file
 * Checks similar services on this site's catalogue.
 *
 * Run with: drush php:script scripts/check-similar.php
 *
 * The ombu_similar view returns Similar's order, at most per_provider per
 * provider, nearest() ranks vectors (three synthetic rows, removed after)
 * and the settings form builds. Needs the Circle catalogue and MariaDB
 * 11.7+; it calls no model.
 */

declare(strict_types=1);

use Drupal\ombu\Embeddings;
use Drupal\ombu\Similar;
use Drupal\views\Views;

$fail = 0;
$check = function (bool $ok, string $what) use (&$fail): void {
  echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
  $fail += (int) !$ok;
};
$ids = \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('type', 'listing')->condition('status', 1)->range(0, 3)->sort('product_id')->execute();

foreach ($ids as $id) {
  $view = Views::getView('ombu_similar');
  $view->setDisplay('embed_1');
  $view->setArguments([$id]);
  $view->execute();
  $got = array_map(fn ($row) => (int) $row->_object->getValue()->id(), $view->result);
  $want = array_slice(array_keys(Similar::scores((int) $id)), 0, count($got));
  $check($got && $got === $want, "the view returns Similar's order for #$id (" . count($got) . ')');
}

$scores = Similar::scores((int) reset($ids));
$per_provider = \Drupal::database()->query('SELECT COUNT(*) FROM {commerce_product__field_provider_term} WHERE entity_id IN (:ids[]) GROUP BY field_provider_term_target_id', [':ids[]' => array_keys($scores) ?: [0]])->fetchCol();
$max = (\Drupal::config('ombu.similar')->get('per_provider') ?: Similar::DEFAULTS['per_provider']);
$check(!$per_provider || max($per_provider) <= $max, "at most $max per provider");

if (Embeddings::available()) {
  [$a, $b, $c] = array_values($ids);
  $db = \Drupal::database();
  $vector = fn (array $head) => json_encode(array_merge($head, array_fill(0, 4096 - count($head), 0.0)));
  $saved = $db->query('SELECT product_id FROM {' . Embeddings::TABLE . '} WHERE product_id IN (:ids[])', [':ids[]' => [$a, $b, $c]])->fetchCol();
  if ($saved) {
    echo "skip vectors: these listings have real ones\n";
  }
  else {
    foreach ([$a => [1, 0], $b => [0.99, 0.1], $c => [0, 1]] as $id => $head) {
      $db->query('INSERT INTO {' . Embeddings::TABLE . "} (product_id, hash, embedding) VALUES (:id, 'check', VEC_FromText(:v))", [':id' => $id, ':v' => $vector($head)]);
    }
    try {
      $near = Embeddings::nearest((int) $a);
      $check(array_key_first($near) === (int) $b && $near[$b] > 0.99 && abs($near[$c]) < 0.01, "nearest(#$a): #$b first, #$c orthogonal");
      $check(isset(Similar::scores((int) $a)[$b]), "#$b counts by its text too");
    }
    finally {
      $db->delete(Embeddings::TABLE)->condition('hash', 'check')->execute();
    }
  }
}

$form = \Drupal::formBuilder()->getForm('Drupal\ombu\Form\SimilarSettingsForm');
$check(isset($form['fields']['field_kind'], $form['fields']['field_tags']), 'the settings form lists field_kind and field_tags');

echo $fail ? "$fail FAILED\n" : "all passed\n";
