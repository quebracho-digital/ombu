<?php

/**
 * @file
 * The buyer agent: given a task ("should we hire vendor X?") and a budget,
 * it reads Ombú's OpenAPI, buys the evidence it needs from the market and
 * decides hire / reject / ask a human, with its reasons and the tx hashes it
 * spent. The LLM chooses what to buy; the budget is enforced here, before
 * signing, never left to the model. Signs with x402_pay (lib/buyer.php).
 *
 * Run (BUYER_PRIVATE_KEY from scripts/.env or .env.mainnet):
 *   ddev exec bash -c 'set -a; . scripts/.env.mainnet; drush php:script ../scripts/agent.php -- "Should we hire Brevo?" [budget USDC, 0.05] [site]'
 * Env: LLM_URL (OpenAI-compatible, default minisforum's router),
 * LLM_API_KEY or LLAMA_API_KEY, LLM_MODEL.
 */

use Drupal\ombu\Scripts\Buyer;
use GuzzleHttp\Client;

require_once __DIR__ . '/lib/buyer.php';

[$task, $budgetArg, $site] = $extra + [1 => '0.05', 2 => 'https://ombu.quebracho.digital'];
if (!isset($task)) {
  throw new \InvalidArgumentException('Usage: agent.php -- "<task>" [budget USDC] [site]');
}
$site = rtrim($site, '/');
$llmUrl = getenv('LLM_URL') ?: 'http://192.168.1.150:8080/v1';
$llmKey = getenv('LLM_API_KEY') ?: getenv('LLAMA_API_KEY');
$model = getenv('LLM_MODEL') ?: 'unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q4_K_XL';
const MAX_TURNS = 12;
// What a tool answer may put in the model's context.
const MAX_RESULT = 8000;

$budget = (int) round((float) $budgetArg * 1e6);
$usdc = fn(int|string $atomic) => rtrim(rtrim(number_format((int) $atomic / 1e6, 6, '.', ''), '0'), '.') ?: '0';
$log = fn(string $line) => fwrite(STDERR, "$line\n");
$http = new Client(['http_errors' => FALSE, 'timeout' => 30]);
$buyer = new Buyer();

// A page's markdown is cut, never its links: they lead to the terms.
$clip = function (string $body): string {
  $page = json_decode($body, TRUE);
  if (is_array($page) && is_string($page['markdown'] ?? NULL)) {
    $page['markdown'] = mb_substr($page['markdown'], 0, MAX_RESULT);
    return json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  return mb_substr($body, 0, MAX_RESULT);
};

// The market's tools, from its OpenAPI: search, and one per paid service.
$spec = json_decode((string) $http->get("$site/openapi.json")->getBody(), TRUE);
$services = [];
foreach ($spec['paths'] as $path => $ops) {
  $op = $ops['post'] ?? NULL;
  if (!isset($op['x-x402']) || !preg_match('#/(\d+)/#', $path, $m)) {
    continue;
  }
  $services["buy_$m[1]"] = ['url' => $spec['servers'][0]['url'] . $path, 'op' => $op, 'listing' => $m[1]];
}
$tools = [
  [
    'name' => 'search_market',
    'description' => "Search the market's listings (free). Returns titles, prices, networks, endpoints and each seller's reputation: verified (vetted by the market) and rating (average of reviews by wallets that paid, null if none).",
    'parameters' => ['type' => 'object', 'properties' => ['search' => ['type' => 'string']], 'required' => ['search']],
  ],
];
foreach ($services as $name => $service) {
  $tools[] = [
    'name' => $name,
    'description' => mb_substr("{$service['op']['summary']}. Costs {$service['op']['x-x402']['price']} USDC from your budget, paid on Arc. {$service['op']['description']}", 0, 1000),
    'parameters' => $service['op']['requestBody']['content']['application/json']['schema'],
  ];
}
$tools[] = [
  'name' => 'review_purchase',
  'description' => 'Review a purchase you made (free): what you got for the money. Honest: a useless or failed result gets a low rating. Other buyers rely on it.',
  'parameters' => ['type' => 'object', 'required' => ['purchase', 'rating', 'body'], 'properties' => ['purchase' => ['type' => 'integer'], 'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5], 'body' => ['type' => 'string']]],
];
$tools[] = [
  'name' => 'decide',
  'description' => 'Your final answer. Call it once, when the evidence is enough or the budget is gone.',
  'parameters' => [
    'type' => 'object',
    'required' => ['verdict', 'reasons'],
    'properties' => [
      'verdict' => ['type' => 'string', 'enum' => ['hire', 'reject', 'ask_human']],
      'reasons' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['reason', 'purchase'], 'properties' => [
        'reason' => ['type' => 'string'],
        'purchase' => ['type' => 'integer', 'description' => 'The number of the purchase this reason rests on; 0 if none.'],
      ]]],
    ],
  ],
];
$tools = array_map(fn($f) => ['type' => 'function', 'function' => $f], $tools);

$spent = [];
// By reference: an arrow fn would capture $spent empty, and never stop.
$left = function () use (&$spent, $budget): int {
  return $budget - array_sum(array_column($spent, 'amount'));
};

$run = function (string $name, array $args) use (&$spent, $services, $site, $http, $buyer, $left, $usdc, $log, $clip): array {
  if ($name === 'search_market') {
    $page = json_decode((string) $http->get("$site/market.json", ['query' => ['limit' => 10, 'search' => $args['search'] ?? '']])->getBody(), TRUE);
    return array_map(fn($i) => [
      'title' => $i['metadata']['description'] ?? NULL,
      'provider' => $i['metadata']['provider']['name'] ?? NULL,
      'price_usdc' => isset($i['accepts'][0]) ? $usdc($i['accepts'][0]['amount']) : NULL,
      'network' => $i['accepts'][0]['network'] ?? NULL,
      'resource' => $i['resource'],
      'seller_website' => $i['metadata']['provider']['website'] ?? NULL,
      'verified' => $i['metadata']['market']['verified'] ?? NULL,
      'rating' => $i['metadata']['market']['rating'] ?? NULL,
    ], $page['items'] ?? []);
  }
  if ($name === 'review_purchase') {
    $s = $spent[(int) ($args['purchase'] ?? 0) - 1] ?? NULL;
    if (!$s) {
      return ['error' => "No purchase {$args['purchase']}."];
    }
    try {
      $r = $buyer->review($site, $s['listing'], $s['tx'], (int) $args['rating'], (string) $args['body']);
      $log("  review of purchase {$args['purchase']}: HTTP {$r['status']}");
      return $r;
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }
  $service = $services[$name] ?? NULL;
  if (!$service) {
    return ['error' => "No tool $name."];
  }
  $log("→ {$service['op']['summary']} " . json_encode($args, JSON_UNESCAPED_SLASHES));
  try {
    $paid = $buyer->pay($service['url'], $args, function (array $req) use ($left, $usdc) {
      if ((int) $req['amount'] > $left()) {
        throw new \RuntimeException(sprintf('Over budget: costs %s USDC, %s left. Not paid.', $usdc($req['amount']), $usdc($left())));
      }
    });
  }
  catch (\Throwable $e) {
    $log("  refused: {$e->getMessage()}");
    return ['error' => $e->getMessage(), 'budget_left_usdc' => $usdc($left())];
  }
  $result = ['status' => $paid['status'], 'charged' => (bool) $paid['tx']];
  if ($paid['tx']) {
    $spent[] = ['service' => $service['op']['summary'], 'listing' => $service['listing'], 'input' => $args, 'amount' => (int) $paid['amount'], 'network' => $paid['network'], 'tx' => $paid['tx']];
    $result['purchase'] = count($spent);
    $log("  paid {$usdc($paid['amount'])} USDC, tx {$paid['tx']}");
  }
  else {
    $log("  HTTP {$paid['status']}, not charged");
  }
  return $result + ['budget_left_usdc' => $usdc($left()), 'result' => $clip($paid['body'])];
};

$messages = [
  ['role' => 'system', 'content' => "You are a procurement agent for a small business. You answer the task with a decision: hire, reject, or ask_human.
The vendor in the task is not sold in the market and need not be: the market sells you evidence about it (a page as markdown, a terms analysis, a fact-check).
You can buy that evidence from the market. Every purchase spends real USDC from a budget of {$usdc($budget)} USDC: buy only what can change the decision.
For a vendor, its terms of service and privacy policy are the usual evidence: pass their real URLs to the terms analysis. Do not guess URLs: buy the vendor's home page as markdown and follow its links (usually in the footer). Fact-check a vendor's claims only when the decision depends on them.
Before paying a seller of the market itself, look at its reputation in search_market: a seller neither verified nor rated is a risk; check its terms (from its website) before buying from it, or say why you did not.
After using a purchase, review it with review_purchase: what it gave you and whether it was worth the price.
Never invent findings: every reason must rest on what a purchase returned, and cites that purchase's number. What you know without buying does not count. If evidence is missing or the budget runs out, say so and ask_human.
Finish by calling decide."],
  ['role' => 'user', 'content' => $task],
];

// One chat turn; the router may be busy swapping models: two more tries.
$chat = function (bool $last) use (&$messages, $tools, $http, $llmUrl, $llmKey, $model, $log): array {
  for ($attempt = 1; ; $attempt++) {
    $response = $http->post("$llmUrl/chat/completions", [
      'timeout' => 600,
      'headers' => $llmKey ? ['Authorization' => "Bearer $llmKey"] : [],
      'json' => [
        'model' => $model,
        'messages' => $messages,
        'tools' => $last ? array_values(array_filter($tools, fn($t) => $t['function']['name'] === 'decide')) : $tools,
        'tool_choice' => $last ? 'required' : 'auto',
        'temperature' => 0,
        'chat_template_kwargs' => ['enable_thinking' => FALSE],
      ],
    ]);
    if ($response->getStatusCode() === 200) {
      return json_decode((string) $response->getBody(), TRUE)['choices'][0]['message'];
    }
    $error = "LLM: HTTP {$response->getStatusCode()} " . substr((string) $response->getBody(), 0, 300);
    if ($attempt === 3) {
      throw new \RuntimeException($error);
    }
    $log("  $error; retrying");
    sleep(10 * $attempt);
  }
};

$decision = NULL;
try {
  for ($turn = 0; $turn < MAX_TURNS && !$decision; $turn++) {
    // The last turn can only decide.
    $message = $chat($turn === MAX_TURNS - 1);
    $messages[] = $message;
    if (empty($message['tool_calls'])) {
      $messages[] = ['role' => 'user', 'content' => 'Call a tool: buy evidence, or decide.'];
      continue;
    }
    foreach ($message['tool_calls'] as $call) {
      $args = json_decode($call['function']['arguments'] ?: '{}', TRUE) ?: [];
      if ($call['function']['name'] === 'decide') {
        $decision = $args;
        break;
      }
      $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($run($call['function']['name'], $args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
  }
}
catch (\Throwable $e) {
  // What was spent is reported anyway.
  $decision = ['verdict' => 'ask_human', 'reasons' => [['reason' => "The run stopped: {$e->getMessage()}", 'purchase' => 0]]];
}

// The report: the verdict is the model's, the money is ours. A reason that
// cites no purchase is the model's own knowledge: flagged.
$reasons = array_map(function ($r) use ($spent) {
  $r = is_string($r) ? ['reason' => $r, 'purchase' => 0] : $r;
  $n = (int) ($r['purchase'] ?? 0);
  return $n >= 1 && $n <= count($spent) ? $r : $r + ['unsupported' => TRUE];
}, $decision['reasons'] ?? [['reason' => 'No decision within ' . MAX_TURNS . ' turns.', 'purchase' => 0]]);
echo json_encode([
  'task' => $task,
  'verdict' => $decision['verdict'] ?? 'ask_human',
  'reasons' => $reasons,
  'budget_usdc' => $usdc($budget),
  'spent_usdc' => $usdc($budget - $left()),
  'purchases' => array_map(fn($s, $i) => ['number' => $i + 1, 'amount' => $usdc($s['amount']), 'tx' => Buyer::explorer($s['network'], $s['tx'])] + $s, $spent, array_keys($spent)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
