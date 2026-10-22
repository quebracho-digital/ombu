<?php

/**
 * @file
 * Checks similar services on this site's catalogue.
 *
 * Run with: drush php:script scripts/check-similar.php
 *
 * The ombu_similar view returns Similar's order, at most per_provider per
 * provider, nearest() ranks vectors (three synthetic rows, removed after),
 * update() copies a vector whose text another listing already has, and the
 * settings form builds, and a listing's markdown links its similar
 * services. With the listings_meilisearch index, Meilisearch's "More like
 * this" ranks by the same vectors. Needs the Circle catalogue and MariaDB
 * 11.7+; it calls no model.
 */

declare(strict_types=1);

use Drupal\commerce_product\Entity\Product;
use Drupal\ombu\Embeddings;
use Drupal\ombu\EventSubscriber\ListingMarkdownSubscriber;
use Drupal\ombu\Similar;
use Drupal\search_api\Entity\Index;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$fail = 0;
$check = function (bool $ok, string $what) use (&$fail): void {
  echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
  $fail += (int) !$ok;
};
$ids = \Drupal::entityQuery('commerce_product')->accessCheck(FALSE)->condition('type', 'listing')->condition('status', 1)->range(0, 5)->sort('product_id')->execute();

foreach (array_slice($ids, 0, 3) as $id) {
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
  [$a, $b, $c, $source, $copy] = array_values($ids);
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

// The copy path: a listing whose text another listing's row already has
// takes that vector, and the model is not called.
if (Embeddings::available() && ($hash = Embeddings::hash(Product::load($copy)))) {
  $db = \Drupal::database();
  if ($db->query('SELECT COUNT(*) FROM {' . Embeddings::TABLE . '} WHERE product_id IN (:ids[])', [':ids[]' => [$source, $copy]])->fetchField()) {
    echo "skip copy: these listings have real vectors\n";
  }
  else {
    $db->query('INSERT INTO {' . Embeddings::TABLE . '} (product_id, hash, embedding) VALUES (:id, :hash, VEC_FromText(:v))', [
      ':id' => $source,
      ':hash' => $hash,
      ':v' => json_encode(array_fill(0, 4096, 0.01)),
    ]);
    try {
      $called = Embeddings::update(Product::load($copy));
      $row = $db->query('SELECT hash FROM {' . Embeddings::TABLE . '} WHERE product_id = :id', [':id' => $copy])->fetchField();
      $check(!$called && $row === $hash, "update(#$copy) copies #$source's vector without calling the model");
    }
    finally {
      $db->delete(Embeddings::TABLE)->condition('product_id', [$source, $copy], 'IN')->condition('hash', $hash)->execute();
    }
  }
}

// Meilisearch's /similar on the vectors SimilarHooks sends: nearest()'s
// similarities, as Meilisearch's scores ((1 + cosine) / 2), best first.
$meili = Index::load('listings_meilisearch');
if ($meili?->status() && $meili->getServerInstance()->isAvailable() && Embeddings::available()) {
  $id = (int) reset($ids);
  $near = Embeddings::nearest($id);
  $query = $meili->query()->range(0, 5);
  $query->setOption('search_api_mlt', ['id' => (string) $id]);
  $scores = [];
  foreach ($query->execute()->getResultItems() as $item_id => $item) {
    $other = (int) explode(':', explode('/', $item_id)[1])[0];
    $scores[] = isset($near[$other]) && abs($item->getScore() - (1 + $near[$other]) / 2) < 0.001 ? $item->getScore() : -1;
  }
  $sorted = $scores;
  rsort($sorted);
  $check($scores && min($scores) >= 0 && $scores === $sorted, "Meilisearch's similar to #$id are nearest()'s, in order");
}

// Agents reading a listing as markdown get the same similar services, as
// links to follow.
$listing = Product::load((int) reset($ids));
$request = new Request();
$request->attributes->set('commerce_product', $listing);
$response = new Response("# Listing\n", 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
(new ListingMarkdownSubscriber())->onResponse(new ResponseEvent(\Drupal::service('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));
$first = Product::load(Similar::ids($listing)[0] ?? 0);
$check($first && str_contains($response->getContent(), "## Similar services\n\n- [") && str_contains($response->getContent(), $first->toUrl()->setAbsolute()->toString()), "the markdown of #{$listing->id()} links its similar services");

$form = \Drupal::formBuilder()->getForm('Drupal\ombu\Form\SimilarSettingsForm');
$check(isset($form['fields']['field_kind'], $form['fields']['field_tags']), 'the settings form lists field_kind and field_tags');

echo $fail ? "$fail FAILED\n" : "all passed\n";
