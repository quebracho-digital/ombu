<?php

/**
 * @file
 * Points the terms and fact-check sellers at minisforum's LLM router.
 *
 * The router's key comes from the environment (LLAMA_API_KEY, in the
 * git-ignored .ddev/.env). Run: ddev drush php:script ../scripts/sellers-llm.php
 */

use Drupal\key\Entity\Key;

if (!Key::load('minisforum_llama')) {
  Key::create(['id' => 'minisforum_llama', 'label' => 'llama.cpp minisforum', 'key_type' => 'authentication', 'key_provider' => 'env', 'key_provider_settings' => ['env_variable' => 'LLAMA_API_KEY', 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]])->save();
}
$servers = \Drupal::entityTypeManager()->getStorage('ai_universal_server');
if (!$servers->load('minisforum')) {
  $servers->create(['id' => 'minisforum', 'label' => 'minisforum', 'backend' => 'openai_compatible', 'host_name' => 'http://192.168.1.150', 'port' => '8080', 'api_key' => 'minisforum_llama', 'timeout' => 600, 'model_filter' => '*Qwen3.8-27B*'])->save();
}
$model = 'minisforum.unsloth_qwen3_8_27b_gguf_q4_k_m';
$models = \Drupal::entityTypeManager()->getStorage('ai_universal_model');
if (!$models->load($model)) {
  // Qwen thinks unless told not to; reasoning_effort does not stop it.
  $models->create(['id' => $model, 'label' => 'minisforum / Qwen3.8-27B', 'server_id' => 'minisforum', 'raw_model_id' => 'unsloth/Qwen3.8-27B-GGUF:Q4_K_M', 'detected_operation_types' => ['chat'], 'quality_tier' => 3, 'context_length' => 262144, 'sampling' => ['temperature' => 0.0], 'extra_params' => ['chat_template_kwargs' => ['enable_thinking' => FALSE]]])->save();
}
\Drupal::configFactory()->getEditable('ai_provider_universal_terms.settings')->set('model', $model)->save();
\Drupal::configFactory()->getEditable('ai_provider_universal_factcheck.settings')->set('checker_model', $model)->set('extractor_model', $model)->save();
echo 'terms configured: ', var_export(\Drupal::service('Drupal\ai_provider_universal_terms\Service\TermsAnalyzer')->isConfigured(), TRUE), "\n";
echo 'factcheck configured: ', var_export(\Drupal::service('Drupal\ai_provider_universal_factcheck\Service\FactChecker')->isConfigured(), TRUE), "\n";
