<?php

/**
 * @file
 * The blog's posts: one file per post under scripts/blog/, in every language.
 * Idempotent: a post is keyed by its alias (/blog/<file name>); running again
 * updates it, except a post edited on the site since this script wrote it,
 * which is left alone and named (OMBU_SEED_FORCE=1 overwrites it anyway).
 *
 * Run: ddev drush php:script ../scripts/seed-blog.php
 */

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$state = \Drupal::state();
$seeded = $state->get('ombu.seed_blog', []);
$force = (bool) getenv('OMBU_SEED_FORCE');
// A body names a listing as {{sku}}: ids differ between databases, SKUs do not.
$link = function (string $body, string $lang) {
  return preg_replace_callback('/\{\{([a-z0-9-]+)\}\}/', function ($m) use ($lang) {
    $variation = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->loadBySku($m[1]);
    if (!$variation || !$variation->getProduct()) {
      throw new \RuntimeException("No listing with SKU {$m[1]}.");
    }
    $language = \Drupal::languageManager()->getLanguage($lang);
    return $variation->getProduct()->toUrl('canonical', ['language' => $language])->toString();
  }, $body);
};
$hash = fn($e) => sha1($e->label() . "\n" . $e->get('body')->value);

foreach (glob(__DIR__ . '/blog/*.php') as $file) {
  $slug = basename($file, '.php');
  $alias = "/blog/$slug";
  $post = require $file;
  $node = NULL;
  foreach (['en', 'es', 'pt-br'] as $lang) {
    if (!isset($post[$lang])) {
      continue;
    }
    [$title, $body] = $post[$lang];
    $body = $link($body, $lang);
    $key = "$alias:$lang";
    $path = \Drupal::service('path_alias.repository')->lookupByAlias($alias, $lang);
    $node ??= $path ? $nodes->load((int) basename($path['path'])) : NULL;
    $entity = $node && $node->hasTranslation($lang) ? $node->getTranslation($lang) : NULL;
    if ($entity && !$force && ($seeded[$key] ?? NULL) !== $hash($entity)) {
      echo "$key: edited on the site, left as it is (OMBU_SEED_FORCE=1 to overwrite)\n";
      continue;
    }
    $values = ['title' => $title, 'body' => ['value' => $body, 'format' => 'full_html'], 'status' => 1, 'path' => ['alias' => $alias, 'pathauto' => 0]];
    if (!$node) {
      $node = $nodes->create(['type' => 'blog', 'uid' => 1, 'langcode' => $lang, 'created' => strtotime($post['date'])] + $values);
      $entity = $node;
    }
    elseif (!$entity) {
      $entity = $node->addTranslation($lang, $values);
    }
    foreach ($values as $field => $value) {
      $entity->set($field, $value);
    }
    $entity->save();
    $seeded[$key] = $hash($entity);
  }
  echo "$alias → node/{$node->id()}\n";
}
$state->set('ombu.seed_blog', $seeded);
