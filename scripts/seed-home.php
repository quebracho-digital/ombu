<?php

/**
 * @file
 * The home page's node: the front page, whose layout lives in config
 * (Structure > Content types > Home page > Manage display > Manage layout).
 * The node only holds the title, in every language, and the address /home.
 * Idempotent: running again changes nothing that is already right.
 *
 * Run: ddev drush php:script ../scripts/seed-home.php
 */

$titles = ['en' => 'Ombú', 'es' => 'Ombú', 'pt-br' => 'Ombú'];
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$found = $nodes->loadByProperties(['type' => 'home']);
$node = $found ? reset($found) : NULL;
foreach ($titles as $lang => $title) {
  $values = ['title' => $title, 'status' => 1, 'path' => ['alias' => '/home', 'pathauto' => 0]];
  if (!$node) {
    $node = $nodes->create(['type' => 'home', 'uid' => 1, 'langcode' => $lang] + $values);
    $entity = $node;
  }
  elseif (!($entity = $node->hasTranslation($lang) ? $node->getTranslation($lang) : NULL)) {
    $entity = $node->addTranslation($lang, $values);
  }
  foreach ($values as $field => $value) {
    $entity->set($field, $value);
  }
  $entity->save();
}
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/home')->save();
echo "/home → node/{$node->id()}\n";
