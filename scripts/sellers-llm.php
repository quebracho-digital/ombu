<?php

/**
 * @file
 * Points the terms and fact-check sellers at minisforum's LLM router.
 *
 * The router's key comes from the environment (LLAMA_API_KEY, in the
 * git-ignored .ddev/.env). Run: ddev drush php:script ../scripts/sellers-llm.php
 * Qwen3.6-35B-A3B by default: a mixture of experts, ~5x faster than the
 * 27B on the router (21 vs 4.6 tokens/s), so a long terms page fits in the
 * payment's timeout. Another: LLM_MODEL=unsloth/Qwen3.8-27B-GGUF:Q4_K_M.
 */

use Drupal\key\Entity\Key;

if (!Key::load('minisforum_llama')) {
  Key::create(['id' => 'minisforum_llama', 'label' => 'llama.cpp minisforum', 'key_type' => 'authentication', 'key_provider' => 'env', 'key_provider_settings' => ['env_variable' => 'LLAMA_API_KEY', 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]])->save();
}
$raw = getenv('LLM_MODEL') ?: 'unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q4_K_XL';
$servers = \Drupal::entityTypeManager()->getStorage('ai_universal_server');
$server = $servers->load('minisforum') ?? $servers->create(['id' => 'minisforum', 'label' => 'minisforum', 'backend' => 'openai_compatible', 'host_name' => 'http://192.168.1.150', 'port' => '8080', 'api_key' => 'minisforum_llama', 'timeout' => 600]);
$filters = array_filter(array_map('trim', explode(',', (string) $server->get('model_filter'))));
$server->set('model_filter', implode(', ', array_unique([...$filters, $raw])))->save();
$model = 'minisforum.' . preg_replace('/[^a-z0-9]+/', '_', strtolower($raw));
$models = \Drupal::entityTypeManager()->getStorage('ai_universal_model');
if (!$models->load($model)) {
  // Qwen thinks unless told not to; reasoning_effort does not stop it.
  $models->create(['id' => $model, 'label' => "minisforum / $raw", 'server_id' => 'minisforum', 'raw_model_id' => $raw, 'detected_operation_types' => ['chat'], 'quality_tier' => 3, 'context_length' => 262144, 'sampling' => ['temperature' => 0.0], 'extra_params' => ['chat_template_kwargs' => ['enable_thinking' => FALSE]]])->save();
}
\Drupal::configFactory()->getEditable('ai_provider_universal_terms.settings')->set('model', $model)->save();
\Drupal::configFactory()->getEditable('ai_provider_universal_factcheck.settings')->set('checker_model', $model)->set('extractor_model', $model)->save();
echo 'terms configured: ', var_export(\Drupal::service('Drupal\ai_provider_universal_terms\Service\TermsAnalyzer')->isConfigured(), TRUE), "\n";
echo 'factcheck configured: ', var_export(\Drupal::service('Drupal\ai_provider_universal_factcheck\Service\FactChecker')->isConfigured(), TRUE), "\n";
