<?php
use GuzzleHttp\Client;
use Quebracho\X402\{EvmExact, PaymentHeaders, Signer};
// A payment whose nonce ends in a line break, signed by a throwaway key with
// no funds: it must be refused as malformed (402 invalid_payload), never 500.
$site = rtrim($extra[0] ?? 'https://ombu.ddev.site', '/');
$http = new Client(['http_errors' => FALSE, 'verify' => FALSE, 'timeout' => 30]);
$items = json_decode((string) $http->get("$site/market.json", ['query' => ['seller' => 'ours']])->getBody(), TRUE)['items'];
$url = $items[0]['resource'];
$first = $http->post($url, ['json' => new stdClass()]);
echo "unpaid: HTTP {$first->getStatusCode()} $url\n";
$required = PaymentHeaders::decode($first->getHeaderLine(PaymentHeaders::REQUIRED));
$req = current(array_filter($required['accepts'], fn($a) => str_starts_with($a['network'], 'eip155:')));
$payment = (new EvmExact(new Signer('0x' . bin2hex(random_bytes(32)))))->pay($required, $req);
$payment['payload']['authorization']['nonce'] .= "\n";
$paid = $http->post($url, ['json' => new stdClass(), 'headers' => [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]]);
$again = $paid->getHeaderLine(PaymentHeaders::REQUIRED);
echo "nonce with a line break: HTTP {$paid->getStatusCode()} ", $again ? (PaymentHeaders::decode($again)['error'] ?? '') : substr((string) $paid->getBody(), 0, 200), "\n";
