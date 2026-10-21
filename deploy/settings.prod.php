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
  '^' . preg_quote(getenv('OMBU_HOST') ?: 'ombu.quebracho.digital') . '$',
  '^192\.168\.1\.66$',
];

// Cloudflare → minisforum's tunnel → here, over the LAN in plain HTTP. Trust
// the proxy's X-Forwarded-* headers: the visitor's https (so URLs and x402
// resources stay https) and their IP (flood limits per visitor, not one for
// everyone). Only the tunnel's host can reach this port.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['192.168.1.150', '192.168.1.66'];

// The tunnel drops responses slower than 100 s (524): a sale that outlives
// it is not charged, since the buyer never gets the body. Until async jobs.
$settings['x402_pay_deadline'] = 95;
// A terms document over 20k characters runs here instead of the local
// model, which outlives that limit (scripts/remote-llms.php).
$settings['ombu_long_terms_model'] = 'openrouter.qwen_qwen3_6_35b_a3b';

// Fact-check, AI-text and plagiarism sellers run on minisforum's
// ai_provider_universal_factcheck (it holds the Tavily and Serper keys), as
// its "ombu" user (role factcheck_api) over HTTP Basic on the LAN.
if ($factcheck_auth = getenv('OMBU_FACTCHECK_API_AUTH')) {
  $settings['ombu_factcheck_api'] = [
    'url' => getenv('OMBU_FACTCHECK_API_URL') ?: 'http://192.168.1.150:8088',
    'auth' => explode(':', $factcheck_auth, 2),
  ];
}

// Mail through Brevo's SMTP relay, with core's Symfony mailer: the image
// has no sendmail. The key is the one Stalwart relays with, from the env
// only, so it never lands in exported config. ombu@quebracho.digital is a
// verified sender in Brevo.
if ($brevo_key = getenv('OMBU_BREVO_SMTP_KEY')) {
  $config['system.mail']['interface']['default'] = 'symfony_mailer';
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => 'smtp',
    'host' => 'smtp-relay.brevo.com',
    'user' => 'af4e22001@smtp-brevo.com',
    'password' => $brevo_key,
    'port' => 587,
    'options' => [],
  ];
}

$settings['config_sync_directory'] = '../config/sync';
$config['system.logging']['error_level'] = 'hide';
