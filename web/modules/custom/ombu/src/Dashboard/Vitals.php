<?php

declare(strict_types=1);

namespace Drupal\ombu\Dashboard;

use Drupal\Component\Datetime\TimeInterface;
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
use Drupal\ombu\Stop;
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
    'wallet_changes' => 'walletChanges',
    'errors' => 'errors',
    'moderation' => 'moderation',
    'blog' => 'blog',
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
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Every block, in the order the dashboard shows them.
   *
   * @return array<int, array<string, mixed>>
   *   The blocks: id, title, status, facts, links and note.
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
    return $this->card('stop', $this->t('Stop switch'), $facts, [
      $this->link($this->t('Stop or resume Ombú'), 'ombu.stop', [], '/admin/config/services/ombu-stop'),
    ], $reason);
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
      $this->fact('settled_24h', $this->t('Settled, last 24 hours'), $settledDay),
      $this->fact('settled_7d', $this->t('Settled, last 7 days'), $settledWeek),
      $this->fact('refused_24h', $this->t('Refused, last 24 hours'), $refusedDay, $refusedDay > 0 ? 'attention' : 'ok'),
      $this->fact('refused_7d', $this->t('Refused, last 7 days'), $refusedWeek),
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
    $storage = $this->entityTypeManager->getStorage('search_api_index');
    foreach (['listings', 'listings_meilisearch'] as $id) {
      $index = $storage->load($id);
      if (!$index) {
        $facts[] = $this->fact(
          "index_$id",
          $this->t('Index @id', ['@id' => $id]),
          $this->t('Not configured'),
          'nodata',
        );
        continue;
      }
      $label = (string) $index->label();
      try {
        $tracker = $index->getTrackerInstanceIfAvailable();
        if (!$tracker) {
          $facts[] = $this->fact(
            "indexed_$id",
            $this->t('@label: indexed', ['@label' => $label]),
            $this->t('no tracker'),
            'nodata',
          );
        }
        else {
          $total = (int) $tracker->getTotalItemsCount();
          $indexed = (int) $tracker->getIndexedItemsCount();
          $facts[] = $this->fact(
            "indexed_$id",
            $this->t('@label: indexed', ['@label' => $label]),
            $this->t('@indexed / @total', [
              '@indexed' => $indexed,
              '@total' => $total,
            ]),
            $indexed < $total ? 'attention' : 'ok',
          );
        }
      }
      catch (\Throwable $e) {
        $facts[] = $this->fact(
          "indexed_$id",
          $this->t('@label: indexed', ['@label' => $label]),
          $this->t('no data'),
          'nodata',
        );
      }
      try {
        $server = $index->getServerInstanceIfAvailable();
        $available = $server && $server->isAvailable();
        $facts[] = $this->fact(
          "server_$id",
          $this->t('@label: server', ['@label' => $label]),
          $available ? $this->t('Responds') : $this->t('Does not respond'),
          $available ? 'ok' : 'attention',
        );
      }
      catch (\Throwable $e) {
        $facts[] = $this->fact(
          "server_$id",
          $this->t('@label: server', ['@label' => $label]),
          $this->t('no data'),
          'nodata',
        );
      }
    }
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
    $facts = [
      $this->fact(
        'cron_last',
        $this->t('Last cron'),
        $last ? $this->date($last) : $this->t('Never'),
        !$last || $now - $last > self::DAY ? 'attention' : 'ok',
      ),
    ];
    foreach ($this->queueWorkers->getDefinitions() as $id => $definition) {
      // Other modules' queues are their own problem.
      if (($definition['provider'] ?? '') !== 'ombu') {
        continue;
      }
      $pending = (int) $this->queue->get($id)->numberOfItems();
      $facts[] = $this->fact(
        "queue_$id",
        $this->t('Queue @id', ['@id' => $id]),
        $pending,
        $pending > self::QUEUE_BACKLOG ? 'attention' : 'ok',
      );
    }
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
    $facts = [];
    $labels = ['bazaar' => 'Bazaar', 'payai' => 'PayAI', 'circle' => 'Circle'];
    foreach ($labels as $source => $label) {
      $key = $source === 'circle' ? 'ombu.circle_last' : "ombu.{$source}_last";
      $last = (int) $this->state->get($key, 0);
      $facts[] = $this->fact(
        "import_$source",
        $this->t('@source', ['@source' => $label]),
        $last ? $this->date($last) : $this->t('Never'),
        !$last || $now - $last > self::IMPORT_STALE ? 'attention' : 'ok',
      );
    }
    return $this->card('imports', $this->t('Catalogue imports'), $facts, [
      $this->link($this->t('Listings'), 'entity.commerce_product.collection', [], '/admin/commerce/products'),
    ], $this->t('The last complete run. A partial run (--max) does not update it.'));
  }

  /**
   * Listings retired because their seller answers with another wallet.
   */
  private function walletChanges(): array {
    $facts = [];
    $labels = ['bazaar' => 'Bazaar', 'payai' => 'PayAI'];
    foreach ($labels as $source => $label) {
      $changes = $this->state->get("ombu.catalog.wallet_changed.$source", []);
      $count = is_array($changes) ? count($changes) : 0;
      $facts[] = $this->fact(
        "wallet_$source",
        $this->t('@source', ['@source' => $label]),
        $count,
        $count > 0 ? 'attention' : 'ok',
      );
    }
    return $this->card('wallet_changes', $this->t('Changed wallets'), $facts, [
      $this->link($this->t('Listings'), 'entity.commerce_product.collection', [], '/admin/commerce/products'),
    ], $this->t('Review each one with drush ombu:wallet-changes bazaar (or payai).'));
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
        $this->fact('errors_24h', $this->t('Errors, last 24 hours'), $count, $count > 0 ? 'attention' : 'ok'),
      ];
      $rows = $this->database->select('watchdog', 'w')
        ->fields('w', ['wid', 'type', 'message', 'variables', 'timestamp'])
        ->condition('severity', RfcLogLevel::ERROR, '<=')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 5)
        ->execute()
        ->fetchAll();
      foreach ($rows as $row) {
        $facts[] = $this->fact(
          "error_{$row->wid}",
          $this->date((int) $row->timestamp) . ' · ' . $row->type,
          $this->message($row),
        );
      }
    }
    catch (\Throwable $e) {
      $facts = [
        $this->fact('errors_24h', $this->t('Errors, last 24 hours'), $this->t('no data'), 'nodata'),
      ];
    }
    return $this->card('errors', $this->t('Errors'), $facts, [
      $this->link($this->t('Recent log messages'), 'dblog.overview', [], '/admin/reports/dblog'),
    ]);
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
      );
    }
    catch (\Throwable $e) {
      $facts[] = $this->fact('comments_pending', $this->t('Comments awaiting approval'), $this->t('no data'), 'nodata');
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
          );
        }
      }
    }
    catch (\Throwable $e) {
      $facts[] = $this->fact('contact_7d', $this->t('Contact messages, last 7 days'), $this->t('no data'), 'nodata');
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
    $facts = [];
    $links = [];
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog')
        ->sort('created', 'DESC')
        ->range(0, 5)
        ->execute();
      foreach ($ids ? $storage->loadMultiple($ids) : [] as $node) {
        $facts[] = $this->fact(
          "post_{$node->id()}",
          $node->label(),
          $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        );
        $links[] = [
          'title' => $this->t('Edit: @title', ['@title' => $node->label()]),
          'url' => $node->toUrl('edit-form'),
        ];
      }
    }
    catch (\Throwable $e) {
      $facts[] = $this->fact('blog_posts', $this->t('Latest posts'), $this->t('no data'), 'nodata');
    }
    $links[] = $this->link($this->t('New post'), 'entity.node.add_form', ['node_type' => 'blog'], '/node/add/blog');
    return $this->card('blog', $this->t('Blog'), $facts, $links);
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
   * @param array<int, array{title: mixed, url: \Drupal\Core\Url}> $links
   *   Where the block's action lives.
   * @param mixed $note
   *   An optional line under the facts.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function card(string $id, mixed $title, array $facts, array $links = [], mixed $note = NULL): array {
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
   *
   * @return array{key: string, label: mixed, value: string, status: string}
   *   The fact.
   */
  private function fact(string $key, mixed $label, mixed $value, string $status = 'ok'): array {
    return [
      'key' => $key,
      'label' => $label,
      'value' => (string) $value,
      'status' => $status,
    ];
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
   *
   * @return array{title: mixed, url: \Drupal\Core\Url}
   *   The link.
   */
  private function link(mixed $title, string $route, array $parameters = [], string $path = ''): array {
    try {
      $url = Url::fromRoute($route, $parameters);
    }
    catch (\Throwable $e) {
      $url = Url::fromUserInput($path);
    }
    return ['title' => $title, 'url' => $url];
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
    return mb_strimwidth($text, 0, 160, '…', 'UTF-8');
  }

}
