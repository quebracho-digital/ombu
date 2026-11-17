<?php

/**
 * @file
 * An LLM judge over the similar-services engines, on the labelled set.
 *
 *   drush php:script ../scripts/judge-similar.php pool       # build the pairs
 *   drush php:script ../scripts/judge-similar.php            # run the judge
 *   drush php:script ../scripts/judge-similar.php scores     # the table
 *
 * "pool" takes scripts/eval/similar-pool.json (eval-similar.php pool) and
 * writes scripts/eval/similar-judge-pairs.json: for each labelled listing,
 * every candidate both engines named, unlabelled, with both texts, the
 * engines that named it and its provider.
 *
 * The judge (no argument) asks the model each pair and records every answer
 * in scripts/eval/similar-judge.json, resumable (an answered pair is not
 * asked again), shaped by JUDGE_MODEL and JUDGE_LIMIT (pairs this run, 0 =
 * all). Two judges:
 * - JUDGE_MODEL=subq:snap (with SUBQ_API_KEY): the SubQ Decisions API,
 *   subq-snap-1.2: a choice among alternative/works_with/unrelated with
 *   probabilities and confidence, billing input only. The default: the
 *   1,433 pairs of the labelled set cost a couple of cents.
 * - JUDGE_MODEL=fireworks.<model id> (or AI's chat default, without the var):
 *   the AI module's chat, one word of text per pair.
 *
 * "scores" rebuilds each engine's top 12 from the pairs' engine tags, scores
 * the lists through the judge's answers instead of the labels, and prints
 * eval-similar.php's table per engine judged by this judge, plus how much the
 * judge agrees with the labels. Compare: a judge-P@5 over a list higher than
 * that list's own P@5 says the judge picks better than the list ranks.
 */

declare(strict_types=1);

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

const EVAL_DIR = __DIR__ . '/eval';
const POOL = EVAL_DIR . '/similar-pool.json';
const LABELS = EVAL_DIR . '/similar-labels.json';
const PAIRS = EVAL_DIR . '/similar-judge-pairs.json';
const JUDGED = EVAL_DIR . '/similar-judge.json';
const JUDGE_TOP = 12;

$mode = $extra[0] ?? '';
$judge_model = getenv('JUDGE_MODEL') ?: NULL;
$limit = (int) (getenv('JUDGE_LIMIT') ?: 0);

// --- pool: the pairs the judge answers, with both texts ---------------------

if ($mode === 'pool') {
  $pool = json_decode((string) @file_get_contents(POOL), TRUE);
  $labels = json_decode((string) @file_get_contents(LABELS), TRUE);
  if (!$pool || !$labels) {
    throw new \RuntimeException('Run `eval-similar.php pool` and label scripts/eval/similar-labels.json first.');
  }
  // Each listing's provider, from the DB, for the table's other@12 column.
  $providers = \Drupal::database()->query('SELECT entity_id, field_provider_term_target_id FROM {commerce_product__field_provider_term} WHERE deleted = 0')->fetchAllKeyed();
  $pairs = [];
  foreach ($pool as $id => $listing) {
    if (empty($labels[$id])) {
      continue;
    }
    // A pair per candidate the pool brought: the labels say what most of
    // them are, the judge says what it thinks. Candidates carry their text,
    // the engines that named them (to rebuild the lists in "scores") and
    // their provider (for other@12).
    $pairs[$id] = [
      'title' => $listing['title'],
      'body' => $listing['body'],
      'provider' => (int) ($providers[$id] ?? -$id),
      'pairs' => array_map(
        fn (array $c) => ['id' => $c['id'], 'title' => $c['title'], 'body' => $c['body'], 'engines' => array_values($c['engines']), 'provider' => (int) ($providers[$c['id']] ?? -$c['id'])],
        $listing['candidates'],
      ),
    ];
  }
  @mkdir(EVAL_DIR);
  file_put_contents(PAIRS, json_encode($pairs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  echo count($pairs) . ' listings, ' . array_sum(array_map(fn ($l) => count($l['pairs']), $pairs)) . " pairs to judge\n";
  return;
}

if (!is_file(PAIRS)) {
  throw new \RuntimeException('Run the script with "pool" first.');
}
$pairs = json_decode((string) file_get_contents(PAIRS), TRUE);
$labels = json_decode((string) @file_get_contents(LABELS), TRUE) ?: [];
$judged = is_file(JUDGED) ? json_decode((string) file_get_contents(JUDGED), TRUE) ?: [] : [];

// --- the judge: one model answer per pair, resumable ------------------------

if ($mode === '') {
  // Two judges, one file:
  // - JUDGE_MODEL=fireworks.<id> (or AI's chat default): the AI module's chat.
  // - JUDGE_MODEL=subq:snap: the SubQ Decisions API (api.subq.ai/v1/systemone,
  //   SUBQ_API_KEY): subq-snap-1.2 answers a choice with probabilities, and
  //   bills input only ($0.04/1M). The 1,433 pairs of the labelled set are a
  //   couple of cents.
  $system = <<<TXT
    You judge marketplace service listings. Two listings follow. A buyer of the first is looking for an alternative: a different service that does the same job and could replace it for the same need. Answer exactly one word:
    - "alternative": a buyer of the first could use the second instead.
    - "works_with": the second is not a replacement but they are used together (one feeds the other).
    - "unrelated": neither.
    One word, nothing else.
    TXT;
  if (str_starts_with((string) $judge_model, 'subq:')) {
    $key = (string) (getenv('SUBQ_API_KEY') ?: throw new \RuntimeException('SUBQ_API_KEY is not set: the SubQ judge needs it (.ddev/.env or the shell).'));
    $judge_model = 'subq:subq-snap-1.2';
    $ask = static function (string $prompt) use ($key): array {
      $start = microtime(TRUE);
      $response = \Drupal::service('http_client')->request('POST', 'https://api.subq.ai/v1/systemone', [
        'timeout' => 60,
        'headers' => ['Authorization' => "Bearer $key"],
        'json' => [
          'model' => 'subq-snap-1.2',
          'state' => $prompt,
          'questions' => [
            'judgment' => [
              'type' => 'choice',
              'instructions' => 'How does the buyer of Listing A relate to Listing B?',
              'criteria' => [
                'alternative' => 'A buyer of the first could use the second instead (the same job, a different service)',
                'works_with' => 'Not a replacement, but they are used together (one feeds the other)',
                'unrelated' => 'Neither',
              ],
            ],
          ],
        ],
      ]);
      $answer = json_decode((string) $response->getBody(), TRUE);
      $judgment = $answer['answers']['judgment'] ?? NULL;
      if (!is_array($judgment)) {
        throw new \RuntimeException('The Decisions API answered no judgment: ' . substr((string) $response->getBody(), 0, 200));
      }
      $probabilities = $judgment['probabilities'] ?? [];
      arsort($probabilities);
      $best = array_key_first($probabilities);
      return [
        'text' => (string) $best,
        'probabilities' => array_map('floatval', $probabilities),
        'confidence' => (float) ($judgment['confidence'] ?? 0),
        'tokens_in' => (int) ($answer['usage']['input_tokens'] ?? 0),
        'tokens_out' => 0,
        'ms' => (int) round((microtime(TRUE) - $start) * 1000),
      ];
    };
    $normalize = static fn (string $text): ?string => in_array($word = strtolower(trim($text)), ['alternative', 'works_with', 'unrelated'], TRUE) ? $word : NULL;
  }
  else {
    if (!$judge_model) {
      $default = \Drupal::service('ai.provider')->getDefaultProviderForOperationType('chat');
      $judge_model = $default ? $default['provider_id'] . '.' . $default['model_id'] : NULL;
    }
    $judge_model ??= throw new \RuntimeException('Set JUDGE_MODEL (e.g. subq:snap, or fireworks.<model id>): the AI module has no chat default.');
    [$provider_id, $model_id] = array_pad(explode('.', $judge_model, 2), 2, '');
    $provider = \Drupal::service('ai.provider')->createInstance($provider_id);
    $provider->setChatSystemRole($system);
    $ask = static function (string $prompt) use ($provider, $model_id): array {
      $start = microtime(TRUE);
      $response = $provider->chat(new ChatInput([new ChatMessage('user', $prompt)]), $model_id, ['ombu_judge']);
      $usage = $response->getTokenUsage();
      return [
        'text' => trim((string) $response->getNormalized()->getText()),
        'tokens_in' => $usage->input ?? NULL,
        'tokens_out' => $usage->output ?? NULL,
        'ms' => (int) round((microtime(TRUE) - $start) * 1000),
      ];
    };
    $normalize = static fn (string $text): ?string => in_array($word = strtolower(trim($text)), ['alternative', 'works_with', 'unrelated'], TRUE) ? $word : NULL;
  }
  echo "Judge: $judge_model\n";

  $asked = $skipped = $bad = 0;
  foreach ($pairs as $id => $listing) {
    $judged[$id] ??= ['model' => $judge_model, 'answers' => []];
    foreach ($listing['pairs'] as $pair) {
      $other_id = (string) $pair['id'];
      if (isset($judged[$id]['answers'][$other_id]) && $judged[$id]['answers'][$other_id]['model'] === $judge_model) {
        $skipped++;
        continue;
      }
      if ($limit && $asked >= $limit) {
        break 2;
      }
      // Shuffle who is first, from the pair itself, so a run is repeatable.
      $first_a = ((int) $id + (int) $other_id) % 2 === 0;
      [$title_a, $body_a, $title_b, $body_b] = $first_a
        ? [$listing['title'], $listing['body'], $pair['title'], $pair['body']]
        : [$pair['title'], $pair['body'], $listing['title'], $listing['body']];
      $answer = $ask(sprintf("Listing A:\nTitle: %s\n%s\n\nListing B:\nTitle: %s\n%s", $title_a, $body_a, $title_b, $body_b));
      $judged[$id]['answers'][$other_id] = [
        'model' => $judge_model,
        'verdict' => $normalize($answer['text']),
        'raw' => $answer['text'],
        'first' => $first_a ? 'a' : 'b',
        'tokens_in' => $answer['tokens_in'],
        'tokens_out' => $answer['tokens_out'],
        'ms' => $answer['ms'],
      ] + (isset($answer['probabilities']) ? ['probabilities' => $answer['probabilities'], 'confidence' => $answer['confidence']] : []);
      if ($judged[$id]['answers'][$other_id]['verdict'] === NULL) {
        $bad++;
        fwrite(STDERR, "Not one word (listing $id vs $other_id): {$answer['text']}\n");
      }
      $asked++;
    }
    // Keep the file on every listing: a crash loses at most one listing's
    // pending answers, and the run is resumable.
    file_put_contents(JUDGED, json_encode($judged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  }
  echo "Asked $asked, skipped $skipped (already judged)" . ($bad ? ", $bad not one word" : ', all answers clean') . ".\n";
  echo "Next: drush php:script ../scripts/judge-similar.php scores\n";
  return;
}

// --- scores: the lists through the judge, beside the labels -----------------

if ($mode === 'scores') {
  if (!$labels) {
    throw new \RuntimeException('No scripts/eval/similar-labels.json: the labels score the judges.');
  }
  // Each engine's top 12, rebuilt from the pairs' engine tags, in the pool's
  // order. The order is the pool's, not the engine's, so P@5/P@12 measure
  // the set, not the ranking: MRR under it means little. The labels' table
  // (eval-similar.php) is the ranking's verdict; this one says how the
  // judge would have scored the same sets.
  $lists = [];
  $providers = [];
  foreach ($pairs as $id => $listing) {
    $providers[(int) $id] = $listing['provider'];
    $by_engine = [];
    foreach ($listing['pairs'] as $pair) {
      $providers[(int) $pair['id']] = $pair['provider'];
      foreach ($pair['engines'] as $engine) {
        $by_engine[$engine][] = (int) $pair['id'];
      }
    }
    foreach ($by_engine as $engine => $top) {
      $lists[$engine][(string) $id] = array_slice($top, 0, JUDGE_TOP);
    }
  }
  $agree = $asked_pairs = 0;
  printf("%-12s %6s %6s %6s %8s %10s %11s\n", 'engine', 'P@5', 'P@12', 'MRR', 'other@12', 'providers', 'unlabelled');
  foreach ($lists as $engine => $engine_lists) {
    $p5 = $p12 = $mrr = $other = $spread = $unlabelled = 0;
    foreach ($engine_lists as $id => $top) {
      $answers = $judged[$id]['answers'] ?? [];
      $good = [];
      foreach ($top as $pos => $other_id) {
        $verdict = $answers[(string) $other_id]['verdict'] ?? NULL;
        $good[$pos] = $verdict;
        if ($verdict !== NULL) {
          $asked_pairs++;
          if (($labels[$id][(string) $other_id] ?? NULL) === $verdict) {
            $agree++;
          }
        }
      }
      $bools = array_map(fn ($v) => $v === 'alternative', $good);
      $unlabelled += count(array_filter($good, fn ($v) => $v === NULL));
      $p5 += array_sum(array_slice($bools, 0, 5)) / 5;
      $p12 += array_sum($bools) / JUDGE_TOP;
      $first = array_search(TRUE, $bools, TRUE);
      $mrr += $first === FALSE ? 0 : 1 / ($first + 1);
      $other += count(array_filter($top, fn ($x, $pos) => $good[$pos] === 'alternative' && ($providers[$x] ?? -$x) !== ($providers[(int) $id] ?? -(int) $id), ARRAY_FILTER_USE_BOTH));
      $spread += count(array_unique(array_map(fn ($x) => $providers[$x] ?? -$x, $top)));
    }
    $n = count($engine_lists);
    printf("%-12s %6.2f %6.2f %6.2f %8.2f %10.1f %11d\n", $engine . ' (judge)', $p5 / $n, $p12 / $n, $mrr / $n, $other / $n, $spread / $n, $unlabelled);
  }
  printf("Judge agreement with the labels: %.2f over %d judged pairs\n", $asked_pairs ? $agree / $asked_pairs : 0, $asked_pairs);
  echo "Beside eval-similar.php's table (the labels): a judge-P@5 over a list higher than that list's own P@5 says the judge picks better than the list ranks.\n";
  return;
}

throw new \RuntimeException("Unknown mode: $mode (pool, nothing = judge, scores).");
