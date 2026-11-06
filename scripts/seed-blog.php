<?php

/**
 * @file
 * The blog's posts: one file per post under scripts/blog/, in every language.
 * Idempotent: a post is keyed by its alias (/blog/<file name>); running again
 * updates it, except a post edited on the site since this script wrote it,
 * which is left alone and named (OMBU_SEED_FORCE=1 overwrites it anyway).
 *
 * A post with 'draft' => TRUE is saved unpublished.
 *
 * Run: ddev drush php:script ../scripts/seed-blog.php
 */

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$state = \Drupal::state();
$seeded = $state->get('ombu.seed_blog', []);
$force = (bool) getenv('OMBU_SEED_FORCE');
// A body names a listing as {{sku}}: ids differ between databases, SKUs do not.
// The card's photo: a copy of the module's file as a managed file, once.
$photo = function (string $name) {
  $dir = 'public://blog';
  \Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $data = file_get_contents(__DIR__ . "/../web/modules/custom/ombu/images/blog/$name");
  return \Drupal::service('file.repository')->writeData($data, "$dir/$name", \Drupal\Core\File\FileExists::Replace)->id();
};
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
// The blog's categories, by key: terms are content, so the seed creates them
// (translated) and a post names them in 'categories'. "whats-new" marks the
// post of each deploy.
$categories = [
  'whats-new' => ['en' => "What's new", 'es' => 'Novedades', 'pt-br' => 'Novidades'],
  'how-it-works' => ['en' => 'How it works', 'es' => 'Cómo funciona', 'pt-br' => 'Como funciona'],
  'story' => ['en' => 'Story', 'es' => 'Historia', 'pt-br' => 'História'],
];
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$tids = [];
foreach ($categories as $key => $names) {
  $found = $terms->loadByProperties(['vid' => 'blog_category', 'name' => $names['en'], 'langcode' => 'en']);
  $term = $found ? reset($found) : $terms->create(['vid' => 'blog_category', 'name' => $names['en'], 'langcode' => 'en']);
  foreach ($names as $lang => $name) {
    if ($lang !== 'en') {
      $term->hasTranslation($lang) ? $term->getTranslation($lang)->setName($name) : $term->addTranslation($lang, ['name' => $name]);
    }
  }
  $term->save();
  $tids[$key] = $term->id();
}
$hash = fn($e) => sha1($e->label() . "\n" . $e->get('body')->value . "\n" . $e->get('body')->summary);

foreach (glob(__DIR__ . '/blog/*.php') as $file) {
  $slug = basename($file, '.php');
  $alias = "/blog/$slug";
  $post = require $file;
  $node = NULL;
  foreach (['en', 'es', 'pt-br'] as $lang) {
    if (!isset($post[$lang])) {
      continue;
    }
    [$title, $body, $summary] = $post[$lang] + [2 => ''];
    $body = $link($body, $lang);
    $key = "$alias:$lang";
    $path = \Drupal::service('path_alias.repository')->lookupByAlias($alias, $lang);
    $node ??= $path ? $nodes->load((int) basename($path['path'])) : NULL;
    $entity = $node && $node->hasTranslation($lang) ? $node->getTranslation($lang) : NULL;
    if ($entity && !$force && ($seeded[$key] ?? NULL) !== $hash($entity)) {
      echo "$key: edited on the site, left as it is (OMBU_SEED_FORCE=1 to overwrite)\n";
      continue;
    }
    $values = ['title' => $title, 'field_image' => ['target_id' => $photo($post['image']), 'alt' => $post['alt'][$lang] ?? $title], 'body' => ['value' => $body, 'summary' => $summary, 'format' => 'full_html'], 'field_blog_category' => array_map(fn($k) => ['target_id' => $tids[$k] ?? throw new \RuntimeException("No blog category $k.")], $post['categories'] ?? []), 'status' => empty($post['draft']) ? 1 : 0, 'path' => ['alias' => $alias, 'pathauto' => 0]];
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
