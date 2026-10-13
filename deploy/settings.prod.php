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

// Cloudflare → minisforum's tunnel → here, over the LAN in plain HTTP. Trust
// the proxy's X-Forwarded-* headers: the visitor's https (so URLs and x402
// resources stay https) and their IP (flood limits per visitor, not one for
// everyone). Only the tunnel's host can reach this port.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['192.168.1.150', '192.168.1.66'];

$settings['config_sync_directory'] = '../config/sync';
$config['system.logging']['error_level'] = 'hide';
