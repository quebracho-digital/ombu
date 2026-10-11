<?php
use Drupal\field\Entity\{FieldConfig, FieldStorageConfig};
use Drupal\key\Entity\Key;
use Drupal\node\Entity\NodeType;

// A node type that sells its pages to agents over x402 (roadmap item 2).
if (!NodeType::load('article')) {
  NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
}
if (!FieldConfig::loadByName('node', 'article', 'body')) {
  if (!FieldStorageConfig::loadByName('node', 'body')) {
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
  }
  FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Body'])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')->setComponent('body', ['type' => 'text_textarea_with_summary'])->save();
  \Drupal::service('entity_display.repository')->getViewDisplay('node', 'article')->setComponent('body', ['type' => 'text_default', 'label' => 'hidden'])->save();
}
if (!Key::load('arc_testnet_seller')) {
  Key::create(['id' => 'arc_testnet_seller', 'label' => 'Arc testnet seller', 'key_type' => 'authentication', 'key_provider' => 'env', 'key_provider_settings' => ['env_variable' => 'SELLER_PRIVATE_KEY', 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]])->save();
}
if (!FieldStorageConfig::loadByName('node', 'field_x402_price')) {
  FieldStorageConfig::create(['field_name' => 'field_x402_price', 'entity_type' => 'node', 'type' => 'x402_price'])->save();
}
if (!FieldConfig::loadByName('node', 'article', 'field_x402_price')) {
  FieldConfig::create(['field_name' => 'field_x402_price', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Price for agents', 'settings' => ['key_id' => 'arc_testnet_seller', 'amount' => '0.01', 'allow_override' => TRUE]])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')->setComponent('field_x402_price', ['type' => 'x402_price_default'])->save();
  \Drupal::service('entity_display.repository')->getViewDisplay('node', 'article')->setComponent('field_x402_price', ['type' => 'x402_price_default'])->save();
}
// A second product on the same node: the summary, cheaper, sold only at
// /x402/node/{id}/teaser (the full text is the one on the node's own URL).
if (!FieldStorageConfig::loadByName('node', 'field_x402_summary')) {
  FieldStorageConfig::create(['field_name' => 'field_x402_summary', 'entity_type' => 'node', 'type' => 'x402_price'])->save();
}
if (!FieldConfig::loadByName('node', 'article', 'field_x402_summary')) {
  FieldConfig::create(['field_name' => 'field_x402_summary', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Summary price for agents', 'settings' => ['key_id' => 'arc_testnet_seller', 'amount' => '0.002', 'view_mode' => 'teaser', 'canonical' => FALSE]])->save();
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')->setComponent('field_x402_summary', ['type' => 'x402_price_default'])->save();
  // What is sold is what the display shows: only the trimmed body.
  \Drupal::service('entity_display.repository')->getViewDisplay('node', 'article', 'teaser')
    ->setComponent('body', ['type' => 'text_summary_or_trimmed', 'label' => 'hidden', 'settings' => ['trim_length' => 60]])
    ->removeComponent('title')->removeComponent('uid')->removeComponent('created')->removeComponent('links')
    ->save();
}
$node = \Drupal\node\Entity\Node::create(['type' => 'article', 'title' => 'How x402 pays for this page', 'body' => ['value' => '<p>Agents pay <strong>one cent</strong> in USDC on Arc to read this.</p><ul><li>No account</li><li>No API key</li></ul>', 'format' => 'basic_html'], 'field_x402_price' => ['enabled' => 1], 'field_x402_summary' => ['enabled' => 1], 'status' => 1]);
$node->save();
echo $node->id(), "\n";
