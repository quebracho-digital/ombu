<?php

/**
 * @file
 * Creates the SubQ server (SubQ Snap speaks the System One protocol) and
 * discovers its models, so the judge (and any decision job) can call it as
 * JUDGE_MODEL=<model entity id>, e.g. snap.subq_snap.
 *
 * The API key reads the environment (SUBQ_API_KEY), never chat, never
 * exported config: it lands in a Key entity whose value the `key` module
 * takes from the env (OMBU keys are env-backed; this one is the plain value,
 * which `key` keeps out of config), and the server names it.
 *
 * Run once per site:
 *   SUBQ_API_KEY=… ddev drush php:script ../scripts/setup-subq-server.php
 * The second run is a no-op (the server and the key exist), except that it
 * re-runs discovery.
 */

use Drupal\key\Entity\Key;
use Drupal\ai_provider_universal\Commands\UniversalCommands;
use Drupal\ai_provider_universal\Entity\AiUniversalServer;

$server_id = 'subq';
$model_id = 'subq_snap';

$key = Key::load('subq_api_key');
if (!$key) {
  $value = (string) getenv('SUBQ_API_KEY');
  if ($value === '') {
    throw new \RuntimeException('SUBQ_API_KEY is not set in the environment: set it for this run (export SUBQ_API_KEY=…).');
  }
  Key::create([
    'key_id' => 'subq_api_key',
    'label' => 'SubQ API key',
    'key_type' => 'authentication',
    'key_input' => 'plain_text',
    // ponytail: a plain value, not env-backed like the OMBU keys, because
    // `key`'s env input needs the variable at every read; the judge runs on
    // this site only, and the value is written once, from the env, by hand.
    'key_value' => ['current_value' => $value],
  ])->save();
  echo "Key subq_api_key created (the value stays out of config and git).\n";
}
else {
  echo "Key subq_api_key exists.\n";
}

$server = AiUniversalServer::load($server_id);
if (!$server) {
  AiUniversalServer::create([
    'id' => $server_id,
    'label' => 'SubQ (Snap, System One)',
    'backend' => 'typesafe',
    'host_name' => 'https://api.subq.ai',
    'api_key' => 'subq_api_key',
    'timeout' => 60,
  ])->save();
  echo "Server $server_id created (typesafe → api.subq.ai).\n";
}
else {
  echo "Server $server_id exists.\n";
}

// Discovery: /v1/models lists what the key can call; subq-snap-1.2 becomes a
// model entity. Its id is what JUDGE_MODEL takes after the dot.
\Drupal::service(UniversalCommands::class)->discoverModels($server_id);
$models = \Drupal::entityTypeManager()->getStorage('ai_universal_model')->loadByProperties(['server_id' => $server_id]);
foreach ($models as $id => $model) {
  echo "Model $id ({$model->getRawModelId()})\n";
}
if (!$models) {
  echo "Discovery found no models: check the key and https://docs.subq.ai/endpoints/models-api/.\n";
}
echo "Judge with: JUDGE_MODEL={$server_id}." . array_key_first($models ?: [$model_id => 1]) . " ddev drush php:script ../scripts/judge-similar.php\n";
