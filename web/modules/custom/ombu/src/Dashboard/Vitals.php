<?php

declare(strict_types=1);

namespace Drupal\ombu\Dashboard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\Markup;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\ombu\Screening\ScreeningLists;
use Drupal\ombu\Stop;
use Drupal\ombu\WalletChanges;
use Drupal\x402_pay\Stop as PaymentsStop;
use Psr\Log\LoggerInterface;

/**
 * The dashboard's vitals, each one read from where its data lives.
 *
 * Every block returns plain data: a title, facts (label, value, state) and
 * links. No HTML, so the controller can draw it and a kernel test can assert
 * the numbers without a browser.
 *
 * No block may break the page: blocks() catches whatever one throws and puts
 * "no data" in its place. A block is "attention" when one of its facts says
 * so, "no data" when it could not read anything, "ok" otherwise.
 */
final class Vitals {

  use StringTranslationTrait;

  /**
   * The vitals, in the order the dashboard shows them: id => method.
   *
   * The id is also the block derivative's id, so a block placed in Layout
   * Builder and the page's default layout share the same machine name.
   */
  public const PARTS = [
    'stop' => 'stop',
    'sales' => 'sales',
    'search' => 'search',
    'cron' => 'cron',
    'imports' => 'imports',
    'screening' => 'screening',
    'wallet_changes' => 'walletChanges',
    'errors' => 'errors',
    'moderation' => 'moderation',
    'blog' => 'blog',
  ];

  /**
   * A small, font-safe glyph per card; decoration only (aria-hidden).
   */
  private const GLYPHS = [
    'stop' => '⏸',
    'sales' => '€',
    'search' => '⌕',
    'cron' => '⏱',
    'imports' => '⇣',
    'screening' => '⊘',
    'wallet_changes' => '⇄',
    'errors' => '!',
    'moderation' => '✓',
    'blog' => '✎',
  ];

  /**
   * A day, in seconds.
   */
  private const DAY = 86400;

  /**
   * A week, in seconds.
   */
  private const WEEK = 604800;

  /**
   * A catalogue older than this is worth a look, in seconds: two weeks.
   *
   * The imports are run by hand, not from cron, so the threshold is generous.
   */
  private const IMPORT_STALE = 1209600;

  /**
   * A queue over this many items is a backlog, not a normal wait.
   */
  private const QUEUE_BACKLOG = 1000;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly QueueFactory $queue,
    private readonly QueueWorkerManagerInterface $queueWorkers,
    private readonly Stop $stop,
    private readonly PaymentsStop $payments,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ScreeningLists $screeningLists,
    private readonly WalletChanges $walletChangeService,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Every block, in the order the dashboard shows them.
   *
   * @return array<int, array<string, mixed>>
   *   The blocks: id, title, status, facts, links, note and, where it
   *   applies, posts or changes.
   */
  public function blocks(): array {
    $blocks = [];
    foreach (array_keys(self::PARTS) as $id) {
      $blocks[] = $this->part($id);
    }
    return $blocks;
  }

  /**
   * One block, by its machine name.
   *
   * @param string $id
   *   The block id, one of self::PARTS.
   *
   * @return array<string, mixed>
   *   The block; a "no data" one when the id is unknown or it failed.
   */
  public function block(string $id): array {
    if (!isset(self::PARTS[$id])) {
      return $this->failed($id, $this->t('Unknown block.'));
    }
    return $this->part($id);
  }

  /**
   * The page: the cards and the summary strip above them.
   *
   * Small cards keep their order; wide cards (errors, blog) go last so they
   * span the full width under the compact grid.
   *
   * @return array{blocks: array<int, array<string, mixed>>,
   *   summary: array<string, mixed>}
   *   The blocks and the summary.
   */
  public function page(): array {
    $blocks = $this->blocks();
    $small = array_values(array_filter($blocks, fn (array $block): bool => empty($block['wide'])));
    $wide = array_values(array_filter($blocks, fn (array $block): bool => !empty($block['wide'])));
    return [
      'blocks' => array_merge($small, $wide),
      'summary' => $this->summary($blocks),
    ];
  }

  /**
   * The summary strip: how many cards need attention and which.
   *
   * @param array<int, array<string, mixed>> $blocks
   *   The blocks, as blocks() returns them.
   *
   * @return array{status: string, label: mixed,
   *   items: array<int, array{id: string, title: mixed}>}
   *   The summary.
   */
  public function summary(array $blocks): array {
    $attention = array_values(array_filter($blocks, fn (array $block): bool => $block['status'] === 'attention'));
    if ($attention) {
      return [
        'status' => 'attention',
        'label' => $this->formatPlural(
          count($attention),
          '1 thing to look at',
          '@count things to look at',
        ),
        'items' => array_map(fn (array $block): array => [
          'id' => $block['id'],
          'title' => $block['title'],
        ], $attention),
      ];
    }
    $nodata = array_filter($blocks, fn (array $block): bool => $block['status'] === 'nodata');
    return [
      'status' => $nodata && count($nodata) === count($blocks) ? 'nodata' : 'ok',
      'label' => count($nodata) === count($blocks) ? $this->t('No data') : $this->t('Everything in order'),
      'items' => [],
    ];
  }

  /**
   * Runs one part and turns whatever it throws into "no data".
   */
  private function part(string $id): array {
    try {
      // Named, not called by string: a renamed method fails here, loudly.
      return match ($id) {
        'stop' => $this->stop(),
        'sales' => $this->sales(),
        'search' => $this->search(),
        'cron' => $this->cron(),
        'imports' => $this->imports(),
        'screening' => $this->screening(),
        'wallet_changes' => $this->walletChanges(),
        'errors' => $this->errors(),
        'moderation' => $this->moderation(),
        'blog' => $this->blog(),
      };
    }
    catch (\Throwable $e) {
      $this->logger->error('Dashboard block @block could not be built: @error', [
        '@block' => $id,
        '@error' => $e->getMessage(),
      ]);
      return $this->failed($id);
    }
  }

  /**
   * A block that could not be read.
   */
  private function failed(string $id, mixed $note = NULL): array {
    return $this->card($id, $this->t('@block', ['@block' => ucfirst(str_replace('_', ' ', $id))]), [
      $this->fact('error', $this->t('Could not be read'), $this->t('no data'), 'nodata'),
    ], [], $note ?? $this->t('This block failed; the rest of the dashboard is unaffected.'));
  }

  /**
   * The stop switch: the site and payments.
   */
  private function stop(): array {
    $engaged = $this->stop->engaged();
    $payments = $this->payments->engaged();
    $layers = $this->payments->layers();
    $note = $this->stop->note();
    $facts = [
      $this->fact(
        'site',
        $this->t('Site'),
        $engaged ? $this->t('Stopped (read-only)') : $this->t('Running'),
        $engaged ? 'attention' : 'ok',
        ['kind' => 'text', 'primary' => TRUE],
      ),
      $this->fact(
        'payments',
        $this->t('Payments'),
        $payments ? $this->t('Paused') : $this->t('Running'),
        $payments ? 'attention' : 'ok',
      ),
      $this->fact('layers', $this->t('Payment switches'), $this->t('library @library, module @module', [
        '@library' => $layers['library'] ? $this->t('on') : $this->t('off'),
        '@module' => $layers['module'] ? $this->t('on') : $this->t('off'),
      ]), 'ok'),
    ];
    $reason = NULL;
    if ($engaged && $note) {
      $reason = $this->t('Stopped @date by user @uid: @why', [
        '@date' => $this->date($note['time']),
        '@uid' => $note['uid'],
        '@why' => $note['why'] === '' ? $this->t('(no reason given)') : $note['why'],
      ]);
    }
    $action = $this->action($this->t('Stop or resume Ombú'), 'ombu.stop', [], '/admin/config/services/ombu-stop');
    return $this->card('stop', $this->t('Stop switch'), $facts, $action ? [$action] : [], $reason);
  }

  /**
   * Sales from x402_pay's log: settled and refused payments.
   */
  private function sales(): array {
    $now = $this->time->getCurrentTime();
    $count = function (string $status, int $since) use ($now): int {
      return (int) $this->database->select('x402_pay_log', 'l')
        ->condition('status', $status)
        ->condition('created', $now - $since, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
    };
    $refused = function (int $since) use ($now): int {
      return (int) $this->database->select('x402_pay_log', 'l')
        ->condition('status', ['not_settled', 'failed'], 'IN')
        ->condition('created', $now - $since, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
    };

    $settledDay = $count('settled', self::DAY);
    $settledWeek = $count('settled', self::WEEK);
    $refusedDay = $refused(self::DAY);
    $refusedWeek = $refused(self::WEEK);
    $facts = [
      $this->fact(
        'settled_24h',
        $this->t('Settled, last 24 hours'),
        $settledDay,
        'ok',
        ['kind' => 'number', 'primary' => TRUE],
      ),
      $this->fact('settled_7d', $this->t('Settled, last 7 days'), $settledWeek, 'ok', ['kind' => 'number']),
      $this->fact(
        'refused_24h',
        $this->t('Refused, last 24 hours'),
        $refusedDay,
        $refusedDay > 0 ? 'attention' : 'ok',
        ['kind' => 'number'],
      ),
      $this->fact('refused_7d', $this->t('Refused, last 7 days'), $refusedWeek, 'ok', ['kind' => 'number']),
    ];
    return $this->card('sales', $this->t('Sales'), $facts, [
      $this->link($this->t('Sales on chain'), 'view.ombu_sales.page', [], '/market/sales'),
    ]);
  }

  /**
   * Search API: indexed items over the total, and whether the server answers.
   */
  private function search(): array {
    $facts = [];
    $healthy = 0;
    $configured = 0;
    $known = 2;
    $storage = $this->entityTypeManager->getStorage('search_api_index');
    foreach (['listings', 'listings_meilisearch'] as $id) {
      $index = $storage->load($id);
      if (!$index) {
        $facts[] = $this->fact(
          "index_$id",
          $this->t('Index @id', ['@id' => $id]),
          $this->t('Not configured'),
          'nodata',
          ['kind' => 'text'],
        );
        continue;
      }
      $configured++;
      $label = (string) $index->label();
      $trackerOk = FALSE;
      try {
        $tracker = $index->getTrackerInstanceIfAvailable();
        if (!$tracker) {
          $facts[] = $this->fact(
            "indexed_$id",
            $this->t('@label: indexed', ['@label' => $label]),
            $this->t('no tracker'),
            'nodata',
            ['kind' => 'number'],
          );
        }
        else {
          $total = (int) $tracker->getTotalItemsCount();
          $indexed = (int) $tracker->getIndexedItemsCount();
          $trackerOk = $indexed >= $total;
          $facts[] = $this->fact(
            "indexed_$id",
            $this->t('@label: indexed', ['@label' => $label]),
            $this->t('@indexed / @total', [
              '@indexed' => $indexed,
              '@total' => $total,
            ]),
            $indexed < $total ? 'attention' : 'ok',
            ['kind' => 'number'],
          );
        }
      }
      catch (\Throwable $e) {
        $facts[] = $this->fact(
          "indexed_$id",
          $this->t('@label: indexed', ['@label' => $label]),
          $this->t('no data'),
          'nodata',
          ['kind' => 'number'],
        );
      }
      $serverOk = FALSE;
      try {
        $server = $index->getServerInstanceIfAvailable();
        $available = $server && $server->isAvailable();
        $serverOk = (bool) $available;
        $facts[] = $this->fact(
          "server_$id",
          $this->t('@label: server', ['@label' => $label]),
          $available ? $this->t('Responds') : $this->t('Does not respond'),
          $available ? 'ok' : 'attention',
          ['kind' => 'text'],
        );
      }
      catch (\Throwable $e) {
        $facts[] = $this->fact(
          "server_$id",
          $this->t('@label: server', ['@label' => $label]),
          $this->t('no data'),
          'nodata',
          ['kind' => 'text'],
        );
      }
      if ($trackerOk && $serverOk) {
        $healthy++;
      }
    }
    $primaryStatus = $configured === 0 ? 'nodata' : ($healthy < $known ? 'attention' : 'ok');
    $facts = array_merge([
      $this->fact(
        'indexes_ok',
        $this->t('Indexes up to date'),
        $this->t('@ok / @total', ['@ok' => $healthy, '@total' => $known]),
        $primaryStatus,
        ['kind' => 'number', 'primary' => TRUE],
      ),
    ], $facts);
    return $this->card('search', $this->t('Search'), $facts, [
      $this->link($this->t('Search API'), 'search_api.overview', [], '/admin/config/search/search-api'),
    ]);
  }

  /**
   * Cron's last run and the pending items of Ombú's own queues.
   */
  private function cron(): array {
    $now = $this->time->getCurrentTime();
    $last = (int) $this->state->get('system.cron_last', 0);
    $queueFacts = [];
    $pendingTotal = 0;
    $backlog = FALSE;
    foreach ($this->queueWorkers->getDefinitions() as $id => $definition) {
      // Other modules' queues are their own problem.
      if (($definition['provider'] ?? '') !== 'ombu') {
        continue;
      }
      $pending = (int) $this->queue->get($id)->numberOfItems();
      $pendingTotal += $pending;
      $backlog = $backlog || $pending > self::QUEUE_BACKLOG;
      $queueFacts[] = $this->fact(
        "queue_$id",
        $this->t('Queue @id', ['@id' => $id]),
        $pending,
        $pending > self::QUEUE_BACKLOG ? 'attention' : 'ok',
        ['kind' => 'number'],
      );
    }
    $facts = array_merge([
      $this->fact(
        'queue_pending',
        $this->t('Pending queue items'),
        $pendingTotal,
        $backlog ? 'attention' : 'ok',
        ['kind' => 'number', 'primary' => TRUE],
      ),
      $this->fact(
        'cron_last',
        $this->t('Last cron'),
        $last ? $this->date($last) : $this->t('Never'),
        !$last || $now - $last > self::DAY ? 'attention' : 'ok',
        ['kind' => 'text'],
      ),
    ], $queueFacts);
    return $this->card('cron', $this->t('Cron and queues'), $facts, [
      $this->link($this->t('Status report'), 'system.status', [], '/admin/reports/status'),
    ]);
  }

  /**
   * Catalogue imports: the last complete run of each source.
   *
   * Only a complete run records the time (DiscoveryCatalogImporter and
   * CircleCatalogImporter set it after the last page), so a partial run with
   * --max does not move it. That is the honest datum; nothing invents one.
   */
  private function imports(): array {
    $now = $this->time->getCurrentTime();
    $importFacts = [];
    $upToDate = 0;
    $labels = ['bazaar' => 'Bazaar', 'payai' => 'PayAI', 'circle' => 'Circle'];
    foreach ($labels as $source => $label) {
      $key = $source === 'circle' ? 'ombu.circle_last' : "ombu.{$source}_last";
      $last = (int) $this->state->get($key, 0);
      $fresh = $last && $now - $last <= self::IMPORT_STALE;
      if ($fresh) {
        $upToDate++;
      }
      $importFacts[] = $this->fact(
        "import_$source",
        $this->t('@source', ['@source' => $label]),
        $last ? $this->date($last) : $this->t('Never'),
        !$last || $now - $last > self::IMPORT_STALE ? 'attention' : 'ok',
        ['kind' => 'text'],
      );
    }
    $facts = array_merge([
      $this->fact(
        'imports_ok',
        $this->t('Sources up to date'),
        $this->t('@ok / @total', ['@ok' => $upToDate, '@total' => count($labels)]),
        $upToDate === count($labels) ? 'ok' : 'attention',
        ['kind' => 'number', 'primary' => TRUE],
      ),
    ], $importFacts);
    return $this->card('imports', $this->t('Catalogue imports'), $facts, [
      $this->link($this->t('Listings'), 'entity.commerce_product.collection', [], '/admin/commerce/products'),
    ], $this->t('The last complete run. A partial run (--max) does not update it.'));
  }

  /**
   * The wallets on our own deny and allow lists.
   */
  private function screening(): array {
    $lists = $this->screeningLists->lists();
    $deny = count($lists['deny'] ?? []);
    $allow = count($lists['allow'] ?? []);
    $facts = [
      $this->fact(
        'deny_count',
        $this->t('Blocked wallets'),
        $deny,
        'ok',
        ['kind' => 'number', 'primary' => TRUE],
      ),
      $this->fact('allow_count', $this->t('Always allowed'), $allow, 'ok', ['kind' => 'number']),
    ];
    $manage = $this->action($this->t('Manage blocked wallets'), 'ombu.screening', [], '/admin/ombu/screening');
    return $this->card('screening', $this->t('Screening'), $facts, $manage ? [$manage] : [], $this->t('A wallet on the deny list cannot buy on Ombú.'));
  }

  /**
   * Listings retired because their seller answers with another wallet.
   */
  private function walletChanges(): array {
    $all = $this->walletChangeService->waiting();
    $counts = array_fill_keys(WalletChanges::SOURCES, 0);
    foreach ($all as $change) {
      $counts[$change['source']] = ($counts[$change['source']] ?? 0) + 1;
    }
    $facts = [
      $this->fact(
        'wallet_total',
        $this->t('Waiting for review'),
        count($all),
        $all ? 'attention' : 'ok',
        ['kind' => 'number', 'primary' => TRUE],
      ),
    ];
    $labels = ['bazaar' => 'Bazaar', 'payai' => 'PayAI'];
    foreach ($labels as $source => $label) {
      $facts[] = $this->fact(
        "wallet_$source",
        $this->t('@source', ['@source' => $label]),
        $counts[$source] ?? 0,
        ($counts[$source] ?? 0) > 0 ? 'attention' : 'ok',
        ['kind' => 'number'],
      );
    }
    $changes = [];
    foreach ($all as $id => $change) {
      $changes[] = [
        'id' => $id,
        'source' => $change['source'],
        'listing' => $change['url'],
        'old' => $change['old'],
        'new' => $change['new'],
        'old_html' => $this->addresses($change['old']),
        'new_html' => $this->addresses($change['new']),
        'date' => $change['time'] ? $this->date($change['time']) : '',
        'action' => $this->action(
          $this->t('Review and accept'),
          'ombu.wallet_change_accept',
          ['commerce_product' => $id],
          "/admin/ombu/wallet-changes/$id/accept",
        ),
      ];
    }
    $card = $this->card('wallet_changes', $this->t('Changed wallets'), $facts, [
      $this->link($this->t('Listings'), 'entity.commerce_product.collection', [], '/admin/commerce/products'),
    ], $this->t('Each listing stays retired until its new wallet is accepted.'));
    $card['changes'] = $changes;
    return $card;
  }

  /**
   * Errors in dblog: how many in a day and the five latest.
   */
  private function errors(): array {
    $now = $this->time->getCurrentTime();
    try {
      $count = (int) $this->database->select('watchdog', 'w')
        ->condition('severity', RfcLogLevel::ERROR, '<=')
        ->condition('timestamp', $now - self::DAY, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
      $facts = [
        $this->fact(
          'errors_24h',
          $this->t('Errors, last 24 hours'),
          $count,
          $count > 0 ? 'attention' : 'ok',
          ['kind' => 'number', 'primary' => TRUE],
        ),
      ];
      $rows = $this->database->select('watchdog', 'w')
        ->fields('w', ['wid', 'type', 'message', 'variables', 'timestamp'])
        ->condition('severity', RfcLogLevel::ERROR, '<=')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 5)
        ->execute()
        ->fetchAll();
      foreach ($rows as $row) {
        $message = $this->message($row);
        $facts[] = $this->fact(
          "error_{$row->wid}",
          $this->date((int) $row->timestamp) . ' · ' . $row->type,
          $message,
          'ok',
          [
            'kind' => 'entry',
            'url' => $this->url(
              'dblog.event',
              ['event_id' => $row->wid],
              "/admin/reports/dblog/event/{$row->wid}",
            ),
            'value_html' => $this->markup($message),
          ],
        );
      }
    }
    catch (\Throwable $e) {
      $facts = [
        $this->fact(
          'errors_24h',
          $this->t('Errors, last 24 hours'),
          $this->t('no data'),
          'nodata',
          ['kind' => 'number', 'primary' => TRUE],
        ),
      ];
    }
    return $this->card('errors', $this->t('Errors'), $facts, [
      $this->link($this->t('Recent log messages'), 'dblog.overview', [], '/admin/reports/dblog'),
    ], NULL, TRUE);
  }

  /**
   * Comments waiting for approval and recent contact messages.
   */
  private function moderation(): array {
    $now = $this->time->getCurrentTime();
    $facts = [];
    try {
      $pending = (int) $this->entityTypeManager->getStorage('comment')->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 0)
        ->count()
        ->execute();
      $facts[] = $this->fact(
        'comments_pending',
        $this->t('Comments awaiting approval'),
        $pending,
        $pending > 0 ? 'attention' : 'ok',
        ['kind' => 'number', 'primary' => TRUE],
      );
    }
    catch (\Throwable $e) {
      $facts[] = $this->fact(
        'comments_pending',
        $this->t('Comments awaiting approval'),
        $this->t('no data'),
        'nodata',
        ['kind' => 'number', 'primary' => TRUE],
      );
    }
    try {
      $storage = $this->entityTypeManager->getStorage('contact_message');
      $recent = (int) $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('created', $now - self::WEEK, '>=')
        ->count()
        ->execute();
      $facts[] = $this->fact(
        'contact_7d',
        $this->t('Contact messages, last 7 days'),
        $recent,
        $recent > 0 ? 'attention' : 'ok',
        ['kind' => 'number'],
      );
      $latest = $storage->getQuery()
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->execute();
      if ($latest) {
        $message = $storage->load(reset($latest));
        if ($message) {
          $facts[] = $this->fact(
            'contact_last',
            $this->t('Latest contact message'),
            $this->date((int) $message->get('created')->value),
            'ok',
            ['kind' => 'text'],
          );
        }
      }
    }
    catch (\Throwable $e) {
      $facts[] = $this->fact(
        'contact_7d',
        $this->t('Contact messages, last 7 days'),
        $this->t('no data'),
        'nodata',
        ['kind' => 'number'],
      );
    }
    return $this->card('moderation', $this->t('Moderation'), $facts, [
      $this->link($this->t('Unapproved comments'), 'comment.admin_approval', [], '/admin/content/comment/approval'),
      $this->link(
        $this->t('Contact messages'),
        'entity.contact_message.collection',
        [],
        '/admin/structure/contact/messages',
      ),
    ]);
  }

  /**
   * The latest blog posts, published or not, with their edit links.
   */
  private function blog(): array {
    $posts = [];
    $error = FALSE;
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog')
        ->sort('created', 'DESC')
        ->range(0, 5)
        ->execute();
      foreach ($ids ? $storage->loadMultiple($ids) : [] as $node) {
        $posts[] = [
          'title' => $node->label(),
          'url' => $node->toUrl(),
          'edit' => $node->toUrl('edit-form'),
          'published' => $node->isPublished(),
        ];
      }
    }
    catch (\Throwable $e) {
      $error = TRUE;
    }
    $card = $this->card('blog', $this->t('Blog'), [
      $this->fact(
        'blog_count',
        $this->t('Latest posts'),
        count($posts),
        $error ? 'nodata' : 'ok',
        ['kind' => 'number', 'primary' => TRUE],
      ),
    ], [
      $this->link($this->t('New post'), 'entity.node.add_form', ['node_type' => 'blog'], '/node/add/blog', TRUE),
    ], NULL, TRUE);
    $card['posts'] = $posts;
    return $card;
  }

  /**
   * One block, with its state derived from its facts.
   *
   * @param string $id
   *   A machine name, for the template and tests.
   * @param string|\Drupal\Component\Render\MarkupInterface $title
   *   The block's heading.
   * @param array<int, array<string, mixed>> $facts
   *   The facts, as fact() builds them.
   * @param array<int, array{title: mixed, url: \Drupal\Core\Url,
   *   primary: bool}> $links
   *   Where the block's action lives.
   * @param mixed $note
   *   An optional line under the facts.
   * @param bool $wide
   *   TRUE for a card that spans the whole grid (errors, blog).
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function card(
    string $id,
    mixed $title,
    array $facts,
    array $links = [],
    mixed $note = NULL,
    bool $wide = FALSE,
  ): array {
    $attention = FALSE;
    $data = FALSE;
    foreach ($facts as $fact) {
      $attention = $attention || $fact['status'] === 'attention';
      $data = $data || $fact['status'] !== 'nodata';
    }
    return [
      'id' => $id,
      'title' => $title,
      'status' => $attention ? 'attention' : ($data ? 'ok' : 'nodata'),
      'glyph' => self::GLYPHS[$id] ?? '',
      'wide' => $wide,
      'facts' => $facts,
      'links' => $links,
      'note' => $note,
    ];
  }

  /**
   * One fact: a label, a value and whether it needs attention.
   *
   * @param string $key
   *   A machine name, so tests do not depend on the translated label.
   * @param mixed $label
   *   The label, translated.
   * @param mixed $value
   *   The value, translated or a number.
   * @param string $status
   *   One of ok, attention or nodata.
   * @param array<string, mixed> $extra
   *   Extra keys: kind (text, number, entry), primary, url, value_html.
   *
   * @return array<string, mixed>
   *   The fact.
   */
  private function fact(string $key, mixed $label, mixed $value, string $status = 'ok', array $extra = []): array {
    return [
      'key' => $key,
      'label' => $label,
      'value' => (string) $value,
      'status' => $status,
    ] + $extra;
  }

  /**
   * A link to a route, or to its path when the route is not installed.
   *
   * The dashboard must render in a kernel test too, where optional config
   * (the sales view, say) is not there.
   *
   * @param mixed $title
   *   The link text.
   * @param string $route
   *   The route name.
   * @param array<string, mixed> $parameters
   *   The route parameters.
   * @param string $path
   *   The path to use when the route does not exist.
   * @param bool $primary
   *   TRUE for an action that stands out (the stop switch, a new post).
   *
   * @return array{title: mixed, url: \Drupal\Core\Url, primary: bool}
   *   The link.
   */
  private function link(mixed $title, string $route, array $parameters = [], string $path = '', bool $primary = FALSE): array {
    return ['title' => $title, 'url' => $this->url($route, $parameters, $path), 'primary' => $primary];
  }

  /**
   * A link to an action, or NULL when the person may not do it.
   *
   * The dashboard is readable with "view ombu dashboard"; the forms behind
   * its actions have their own permissions. A link that would answer 403 is
   * not shown.
   *
   * @param mixed $title
   *   The link text.
   * @param string $route
   *   The route name.
   * @param array<string, mixed> $parameters
   *   The route parameters.
   * @param string $path
   *   The path to use when the route does not exist.
   *
   * @return array{title: mixed, url: \Drupal\Core\Url, primary: bool}|null
   *   The link, or NULL.
   */
  private function action(mixed $title, string $route, array $parameters = [], string $path = ''): ?array {
    $url = $this->url($route, $parameters, $path);
    try {
      if (!$url->access()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return ['title' => $title, 'url' => $url, 'primary' => TRUE];
  }

  /**
   * A route as a URL, or its path when the route is not installed.
   *
   * @param string $route
   *   The route name.
   * @param array<string, mixed> $parameters
   *   The route parameters.
   * @param string $path
   *   The path to use when the route does not exist.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   */
  private function url(string $route, array $parameters = [], string $path = ''): Url {
    try {
      return Url::fromRoute($route, $parameters);
    }
    catch (\Throwable $e) {
      return Url::fromUserInput($path);
    }
  }

  /**
   * Text as safe markup, with 0x hashes and addresses shortened.
   *
   * The full value stays in the code element's title; the display shows
   * 0x1111…1111. Plain text without hashes is returned as a string, so Twig
   * escapes it as usual.
   */
  private function markup(string $text): MarkupInterface|string {
    if (!preg_match('/0x[0-9a-fA-F]{20,}/', $text)) {
      return $text;
    }
    $escaped = Html::escape($text);
    $escaped = (string) preg_replace_callback('/0x[0-9a-fA-F]{20,}/', function (array $match): string {
      $full = $match[0];
      $short = substr($full, 0, 6) . '…' . substr($full, -4);
      return '<code title="' . Html::escape($full) . '">' . Html::escape($short) . '</code>';
    }, $escaped);
    return Markup::create($escaped);
  }

  /**
   * Wallet addresses as code, a 0x one shortened with its full value in title.
   *
   * @param string[] $addresses
   *   The addresses.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   Safe markup, or an empty string when there are none.
   */
  private function addresses(array $addresses): MarkupInterface|string {
    if (!$addresses) {
      return '';
    }
    $parts = [];
    foreach ($addresses as $address) {
      if (preg_match('/^0x[0-9a-fA-F]{20,}$/', $address)) {
        $short = substr($address, 0, 6) . '…' . substr($address, -4);
        $parts[] = '<code title="' . Html::escape($address) . '">' . Html::escape($short) . '</code>';
      }
      else {
        $parts[] = '<code>' . Html::escape($address) . '</code>';
      }
    }
    return Markup::create(implode(' ', $parts));
  }

  /**
   * A timestamp as the dashboard shows it.
   */
  private function date(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i');
  }

  /**
   * One dblog message, translated and without tags.
   *
   * The row is what dblog itself would show: the message is a format string
   * and variables its arguments. It is filtered and stripped, and Twig
   * escapes it again, so a logged HTML payload stays text.
   */
  private function message(object $row): string {
    $variables = @unserialize((string) $row->variables, ['allowed_classes' => FALSE]);
    if (is_array($variables)) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = (string) $this->t(Xss::filterAdmin((string) $row->message), $variables);
    }
    else {
      $message = (string) $row->message;
    }
    // Plain text: Twig escapes it once more, so entities from the
    // placeholders (&lt;) are decoded here, not shown as "&lt;".
    $text = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Long enough that a 66-character hash and its context survive; the CSS
    // wraps the rest, and a backtrace does not take the whole card.
    return mb_strimwidth($text, 0, 400, '…', 'UTF-8');
  }

}
