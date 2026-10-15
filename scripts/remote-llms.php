<?php

/**
 * @file
 * The remote LLM servers (Groq, Fireworks, OpenRouter), keys from the env.
 *
 * Long terms documents run on one of them (ombu_long_terms_model in
 * settings.prod.php). Run: drush php:script ../scripts/remote-llms.php,
 * then drush aip:discover-models <server>, and once more for the model settings.
 */

use Drupal\key\Entity\Key;

$servers = \Drupal::entityTypeManager()->getStorage('ai_universal_server');
foreach (['groq' => 'GROQ_API_KEY', 'fireworks' => 'FIREWORKS_API_KEY', 'openrouter' => 'OPENROUTER_API_KEY'] as $backend => $env) {
  if (!getenv($env)) {
    echo "$env not set: $backend skipped\n";
    continue;
  }
  if (!Key::load($backend)) {
    Key::create(['id' => $backend, 'label' => $backend, 'key_type' => 'authentication', 'key_provider' => 'env', 'key_provider_settings' => ['env_variable' => $env, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]])->save();
  }
  if (!$servers->load($backend)) {
    $servers->create(['id' => $backend, 'label' => $backend, 'backend' => $backend, 'api_key' => $backend, 'timeout' => 90])->save();
  }
  echo "$backend ready\n";
}

// Long terms: Qwen3.6-35B-A3B, the local model's twin, without thinking.
// 62k characters in 12 s on 2026-10-15 (Groq's free tier hit its rate
// limit at once).
$models = \Drupal::entityTypeManager()->getStorage('ai_universal_model');
$models->load('openrouter.qwen_qwen3_6_35b_a3b')?->set('extra_params', ['reasoning' => ['enabled' => FALSE]])->set('sampling', ['temperature' => 0.0])->save();

