<?php

/**
 * @file
 * Production settings (SER5, deploy/compose.yaml), included from the site's
 * settings.php when OMBU_PROD is set. Secrets come from the environment.
 */

$databases['default']['default'] = [
  'driver' => 'mysql',
  'host' => 'db',
  'port' => 3306,
  'database' => getenv('MARIADB_DATABASE'),
  'username' => getenv('MARIADB_USER'),
  'password' => getenv('MARIADB_PASSWORD'),
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// The same salt as before the move: sessions and one-time links survive it.
$settings['hash_salt'] = getenv('OMBU_HASH_SALT');

$settings['trusted_host_patterns'] = [
  '^ombu\.quebracho\.digital$',
  '^ombu\.quebrachos\.digital$',
  '^192\.168\.1\.66$',
];

// TLS ends at Cloudflare and the tunnel speaks plain HTTP: Cloudflare's own
// header says what the visitor used, so URLs (and x402 resources) stay https.
if (str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https')) {
  $_SERVER['HTTPS'] = 'on';
  $_SERVER['SERVER_PORT'] = 443;
}

$settings['config_sync_directory'] = '../config/sync';
$config['system.logging']['error_level'] = 'hide';
