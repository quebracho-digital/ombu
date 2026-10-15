<?php

/**
 * @file
 * Builds the ombu_sales view once; its YAML is then exported to
 * ombu/config/optional. Run: ddev drush php:script ../scripts/make-sales-view.php
 */

use Drupal\views\Entity\View;

View::load('ombu_sales')?->delete();
$field = fn(string $id, string $table, array $extra = []) => $extra + ['id' => $id, 'table' => $table, 'field' => $id, 'label' => '', 'element_label_colon' => FALSE];
$view = View::create([
  'id' => 'ombu_sales',
  'label' => 'Sales',
  'description' => 'Settled x402 sales, newest first: the latest on the market, per listing, and a page with all of them.',
  'base_table' => 'x402_pay_log',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => [
        'title' => 'Latest sales on chain',
        'access' => ['type' => 'none'],
        'cache' => ['type' => 'tag'],
        'query' => ['type' => 'views_query'],
        'pager' => ['type' => 'some', 'options' => ['items_per_page' => 5, 'offset' => 0]],
        'style' => ['type' => 'html_list', 'options' => ['type' => 'ul', 'class' => 'ombu-sales__list', 'wrapper_class' => '', 'row_class' => '']],
        'row' => ['type' => 'fields', 'options' => ['inline' => ['created' => 'created', 'title' => 'title', 'amount' => 'amount', 'transaction' => 'transaction'], 'separator' => ' ']],
        'relationships' => ['listing' => ['id' => 'listing', 'table' => 'x402_pay_log', 'field' => 'listing', 'plugin_id' => 'standard', 'required' => FALSE]],
        'fields' => [
          'created' => $field('created', 'x402_pay_log', ['plugin_id' => 'date', 'date_format' => 'custom', 'custom_date_format' => 'Y-m-d H:i', 'timezone' => 'UTC', 'alter' => ['alter_text' => TRUE, 'text' => '{{ created }} UTC']]),
          'title' => $field('title', 'commerce_product_field_data', ['relationship' => 'listing', 'entity_type' => 'commerce_product', 'entity_field' => 'title', 'plugin_id' => 'field', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
          'amount' => $field('amount', 'x402_pay_log', ['plugin_id' => 'x402_amount', 'alter' => ['alter_text' => TRUE, 'text' => '{{ amount }} USDC'], 'element_class' => 'ombu-sales__amount', 'element_type' => 'span']),
          'transaction' => $field('transaction', 'x402_pay_log', ['plugin_id' => 'x402_transaction', 'element_class' => 'ombu-sales__tx', 'element_type' => 'span']),
        ],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'x402_pay_log', 'field' => 'status', 'operator' => '=', 'value' => 'settled', 'plugin_id' => 'string'],
        ],
        // The network the site charges (Sales passes it); Arc mainnet when
        // a block is placed alone.
        'arguments' => ['network' => ['id' => 'network', 'table' => 'x402_pay_log', 'field' => 'network', 'plugin_id' => 'string', 'default_action' => 'default', 'default_argument_type' => 'fixed', 'default_argument_options' => ['argument' => 'eip155:5042']]],
        'sorts' => ['created' => ['id' => 'created', 'table' => 'x402_pay_log', 'field' => 'created', 'order' => 'DESC', 'plugin_id' => 'date'], 'id' => ['id' => 'id', 'table' => 'x402_pay_log', 'field' => 'id', 'order' => 'DESC', 'plugin_id' => 'standard']],
        'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => 'No sales yet: be the first.']],
      ],
    ],
    'latest' => ['id' => 'latest', 'display_plugin' => 'block', 'position' => 1, 'display_title' => 'Latest', 'display_options' => ['block_description' => 'Latest sales']],
    'listing' => [
      'id' => 'listing',
      'display_plugin' => 'block',
      'position' => 2,
      'display_title' => 'Per listing',
      'display_options' => [
        'block_description' => 'Latest sales of this listing',
        'defaults' => ['arguments' => FALSE, 'fields' => FALSE, 'row' => FALSE],
        'row' => ['type' => 'fields', 'options' => ['inline' => ['created' => 'created', 'amount' => 'amount', 'transaction' => 'transaction'], 'separator' => ' ']],
        // The listing shown: /product/{id}, or the id passed in.
        'arguments' => ['network' => ['id' => 'network', 'table' => 'x402_pay_log', 'field' => 'network', 'plugin_id' => 'string', 'default_action' => 'default', 'default_argument_type' => 'fixed', 'default_argument_options' => ['argument' => 'eip155:5042']], 'entity_id' => ['id' => 'entity_id', 'table' => 'x402_pay_log', 'field' => 'entity_id', 'plugin_id' => 'string', 'default_action' => 'default', 'default_argument_type' => 'raw', 'default_argument_options' => ['index' => 1, 'use_alias' => FALSE]]],
        'fields' => [
          'created' => $field('created', 'x402_pay_log', ['plugin_id' => 'date', 'date_format' => 'custom', 'custom_date_format' => 'Y-m-d H:i', 'timezone' => 'UTC', 'alter' => ['alter_text' => TRUE, 'text' => '{{ created }} UTC']]),
          'amount' => $field('amount', 'x402_pay_log', ['plugin_id' => 'x402_amount', 'alter' => ['alter_text' => TRUE, 'text' => '{{ amount }} USDC'], 'element_class' => 'ombu-sales__amount', 'element_type' => 'span']),
          'transaction' => $field('transaction', 'x402_pay_log', ['plugin_id' => 'x402_transaction', 'element_class' => 'ombu-sales__tx', 'element_type' => 'span']),
        ],
      ],
    ],
    'page' => [
      'id' => 'page',
      'display_plugin' => 'page',
      'position' => 3,
      'display_title' => 'All sales',
      'display_options' => [
        'path' => 'market/sales',
        'title' => 'Sales on chain',
        'defaults' => ['pager' => FALSE, 'title' => FALSE],
        'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50, 'offset' => 0]],
      ],
    ],
  ],
]);
$view->save();
echo implode("\n", array_map(fn($d) => $d, array_keys($view->get('display')))), "\n";
print_r(views_embed_view('ombu_sales', 'latest') ? 'renders' : 'empty');
echo "\n", trim(strip_tags((string) \Drupal::service('renderer')->renderInIsolation(views_embed_view('ombu_sales', 'latest')))), "\n";
