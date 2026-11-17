<?php

/**
 * @file
 * Creates the SubQ server (SubQ Snap speaks the System One protocol) and
 * discovers its models, so the judge (and any decision job) can call it as
 * JUDGE_MODEL=<model entity id>, e.g. subq.subq_snap_1_2.
 *
 * The API key is read from the environment (SUBQ_API_KEY) at every call,
 * like every key of this site: the Key entity names the variable and
 * stores no value, so nothing secret reaches the database or config/sync.
 *
 * Run once per site:
 *   SUBQ_API_KEY=… ddev drush php:script ../scripts/setup-subq-server.php
 * The second run is a no-op (the server and the key exist), except that it
 * re-runs discovery.
 */

use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\key\Entity\Key;
use Drupal\ai_provider_universal\Entity\AiUniversalServer;

$server_id = 'subq';

// The backend: `subq`, in ai_provider_universal 1.0.x since the SubQ commit.
// A server an earlier run created with `typesafe` (the same wire) moves to
// `subq` below.
$backend = 'subq';
if (!\Drupal::service(AiServerBackendManager::class)->hasDefinition('subq')) {
  throw new \RuntimeException('The subq backend is missing: composer update drupal/ai_provider_universal (1.0.x-dev).');
}

// The key is read from the environment at every call, like every other key
// of this site (key.key.fireworks): the value is never stored, so neither
// the database nor a `drush cex` into config/sync can carry it.
if ((string) getenv('SUBQ_API_KEY') === '') {
  throw new \RuntimeException('SUBQ_API_KEY is not set in the web container: add it to .ddev/.env (deploy/.env on SER5) and restart.');
}
$key = Key::load('subq_api_key');
if (!$key) {
  Key::create([
    'id' => 'subq_api_key',
    'label' => 'SubQ API key',
    'key_type' => 'authentication',
    'key_provider' => 'env',
    'key_provider_settings' => [
      'env_variable' => 'SUBQ_API_KEY',
      'base64_encoded' => FALSE,
      'strip_line_breaks' => TRUE,
    ],
    'key_input' => 'none',
  ])->save();
  echo "Key subq_api_key created (read from SUBQ_API_KEY).\n";
}
elseif ($key->getKeyProvider()->getPluginId() !== 'env') {
  // A key an earlier run stored as a plain value: point it at the env; the
  // new provider settings replace the ones that held the value.
  $key->set('key_provider', 'env')
    ->set('key_provider_settings', ['env_variable' => 'SUBQ_API_KEY', 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE])
    ->set('key_input', 'none')
    ->set('key_input_settings', [])
    ->save();
  echo "Key subq_api_key moved to SUBQ_API_KEY; the stored value is gone.\n";
}
else {
  echo "Key subq_api_key exists (read from SUBQ_API_KEY).\n";
}

$server = AiUniversalServer::load($server_id);
if (!$server) {
  AiUniversalServer::create([
    'id' => $server_id,
    'label' => $backend === 'subq' ? 'SubQ (Snap)' : 'SubQ (Snap, System One)',
    'backend' => $backend,
    'host_name' => 'https://api.subq.ai',
    'api_key' => 'subq_api_key',
    'timeout' => 60,
  ])->save();
  echo "Server $server_id created ($backend → api.subq.ai).\n";
}
elseif ($server->getBackend() !== $backend) {
  $server->set('backend', $backend)->save();
  echo "Server $server_id moved to the $backend backend.\n";
}
else {
  echo "Server $server_id exists.\n";
}

// Discovery: /v1/models lists what the key can call; subq-snap-1.2 becomes a
// model entity. Its id is what JUDGE_MODEL takes after the dot.
// The same call as `drush aip:discover-models subq`.
\Drupal::service('ai.provider')->createInstance('universal', ['server_id' => $server_id])->discoverModels();
$models = \Drupal::entityTypeManager()->getStorage('ai_universal_model')->loadByProperties(['server_id' => $server_id]);
foreach ($models as $id => $model) {
  echo "Model $id ({$model->getRawModelId()})\n";
}
if (!$models) {
  echo "Discovery found no models: check the key and https://docs.subq.ai/endpoints/models-api/.\n";
  return;
}
echo "Judge with: JUDGE_MODEL=" . array_key_first($models) . " ddev drush php:script ../scripts/judge-similar.php\n";
