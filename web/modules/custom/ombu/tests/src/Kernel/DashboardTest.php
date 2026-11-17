<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\comment\Entity\Comment;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\contact\Entity\Message;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ombu\Dashboard\Vitals;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The dashboard's vitals and who may see them.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class DashboardTest extends CommerceKernelTestBase {

  use CommentTestTrait;

  private const WALLET = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views',
    'search_api', 'search_api_db', 'facets', 'better_exposed_filters', 'facets_exposed_filters',
    'language', 'mcp_server', 'node', 'datetime', 'path', 'path_alias',
    'contact', 'contact_storage', 'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A flag file of this test's own: the site's could be engaged.
    $this->setSetting(
      'x402_pay_stop_file',
      sys_get_temp_dir() . '/ombu-dashboard-' . bin2hex(random_bytes(4)) . '/paused',
    );
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('contact_message');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('x402_pay', ['x402_pay_log']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['node', 'filter', 'comment', 'contact', 'views', 'contact_storage']);
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    // A node type has no comment field by itself: add the default one.
    $this->addDefaultCommentField('node', 'blog');
  }

  /**
   * The vitals read the rows the site already has.
   */
  public function testVitalsWithData(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $this->sale($now - 3600, 'settled');
    $this->sale($now - 7200, 'settled');
    $this->sale($now - 3 * 86400, 'settled');
    $this->sale($now - 1800, 'not_settled');
    $this->sale($now - 2 * 86400, 'failed');

    $node = Node::create(['type' => 'blog', 'title' => 'A post', 'status' => 1]);
    $node->save();
    Comment::create([
      'comment_type' => 'comment',
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'comment',
      'subject' => 'Waiting',
      'uid' => 0,
      'status' => 0,
    ])->save();
    Message::create([
      'contact_form' => 'personal',
      'name' => 'Ana',
      'mail' => 'ana@example.com',
      'subject' => 'Hello',
      'message' => 'A question.',
    ])->save();
    $wid = $this->container->get('database')->insert('watchdog')->fields([
      'uid' => 0,
      'type' => 'php',
      'message' => 'Something failed: @error',
      'variables' => serialize(['@error' => 'boom']),
      'severity' => RfcLogLevel::ERROR,
      'location' => '',
      'referer' => '',
      'hostname' => '',
      'timestamp' => $now,
    ])->execute();

    $state = $this->container->get('state');
    $state->set('system.cron_last', $now - 60);
    $state->set('ombu.bazaar_last', $now - 3600);
    $state->set('ombu.catalog.wallet_changed.bazaar', [
      10 => ['url' => 'https://example.com/x', 'old' => [], 'new' => [], 'time' => $now],
    ]);

    $facts = $this->facts($this->container->get('ombu.dashboard')->blocks());

    $this->assertSame('2', $facts['sales.settled_24h']['value']);
    $this->assertSame('3', $facts['sales.settled_7d']['value']);
    $this->assertSame('1', $facts['sales.refused_24h']['value']);
    $this->assertSame('2', $facts['sales.refused_7d']['value']);
    $this->assertSame('attention', $facts['sales.refused_24h']['status']);
    $this->assertSame('1', $facts['moderation.comments_pending']['value']);
    $this->assertSame('1', $facts['moderation.contact_7d']['value']);
    $this->assertSame('1', $facts['errors.errors_24h']['value']);
    $this->assertStringContainsString('Something failed', $facts["errors.error_$wid"]['value']);
    $this->assertSame('Published', $facts["blog.post_{$node->id()}"]['value']);
    $this->assertSame('1', $facts['wallet_changes.wallet_bazaar']['value']);
    $this->assertNotSame('Never', $facts['cron.cron_last']['value']);
    $this->assertNotSame('Never', $facts['imports.import_bazaar']['value']);
    $this->assertSame('Running', $facts['stop.site']['value']);
  }

  /**
   * Empty tables and no config do not break the page.
   */
  public function testVitalsWithEmptyTables(): void {
    $blocks = $this->container->get('ombu.dashboard')->blocks();
    $ids = array_column($blocks, 'id');
    $this->assertSame([
      'stop',
      'sales',
      'search',
      'cron',
      'imports',
      'wallet_changes',
      'errors',
      'moderation',
      'blog',
    ], $ids);

    $facts = $this->facts($blocks);
    $this->assertSame('0', $facts['sales.settled_24h']['value']);
    $this->assertSame('0', $facts['sales.refused_7d']['value']);
    $this->assertSame('0', $facts['moderation.comments_pending']['value']);
    $this->assertSame('0', $facts['moderation.contact_7d']['value']);
    $this->assertSame('0', $facts['errors.errors_24h']['value']);
    $this->assertSame('0', $facts['wallet_changes.wallet_bazaar']['value']);
    $this->assertSame('Never', $facts['cron.cron_last']['value']);
    $this->assertSame('Never', $facts['imports.import_bazaar']['value']);
  }

  /**
   * Every vital is a block, and the dashboard is a Views area.
   */
  public function testPluginsAreDiscovered(): void {
    $manager = $this->container->get('plugin.manager.block');
    $blocks = $manager->getDefinitions();
    foreach (array_keys(Vitals::PARTS) as $id) {
      $this->assertArrayHasKey("ombu_dashboard:$id", $blocks);
      $block = $manager->createInstance("ombu_dashboard:$id", []);
      $this->assertSame('ombu_dashboard', $block->build()['#theme']);
    }
    $areas = $this->container->get('plugin.manager.views.area')->getDefinitions();
    $this->assertArrayHasKey('ombu_dashboard', $areas);
  }

  /**
   * A stopped site says so, with the reason and who stopped it.
   */
  public function testStopSwitchIsShown(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('ombu.stopped', [
      'time' => $this->container->get('datetime.time')->getCurrentTime(),
      'why' => 'Maintenance',
      'uid' => 1,
    ]);
    $block = $this->container->get('ombu.dashboard')->block('stop');
    $facts = $this->facts([$block]);
    $this->assertSame('Stopped (read-only)', $facts['stop.site']['value']);
    $this->assertSame('attention', $facts['stop.site']['status']);
    $this->assertSame('attention', $block['status']);
    $this->assertStringContainsString('Maintenance', (string) $block['note']);
  }

  /**
   * The page, its blocks and its views area need "view ombu dashboard".
   */
  public function testAccess(): void {
    $anonymous = new AnonymousUserSession();
    // The first user is uid 1, which bypasses every permission check: take
    // it out of the way so "without" really has no permission.
    $this->createUser();
    $without = $this->createUser();
    $with = $this->createUser(['view ombu dashboard']);
    $access = $this->container->get('access_manager');

    $this->assertFalse($access->checkNamedRoute('ombu.dashboard', [], $anonymous, TRUE)->isAllowed());
    $this->assertFalse($access->checkNamedRoute('ombu.dashboard', [], $without, TRUE)->isAllowed());
    $this->assertTrue($access->checkNamedRoute('ombu.dashboard', [], $with, TRUE)->isAllowed());

    $block = $this->container->get('plugin.manager.block')->createInstance('ombu_dashboard:sales', []);
    $this->assertFalse($block->access($anonymous));
    $this->assertFalse($block->access($without));
    $this->assertTrue($block->access($with));
    $this->assertSame('ombu_dashboard', $block->build()['#theme']);

    $area = $this->container->get('plugin.manager.views.area')->createInstance('ombu_dashboard', []);
    $this->assertSame([], $area->render());
    $this->setCurrentUser($with);
    $this->assertSame('ombu_dashboard', $area->render()['#theme']);
  }

  /**
   * One row in x402_pay's log.
   */
  private function sale(int $created, string $status): void {
    $this->container->get('database')->insert('x402_pay_log')->fields([
      'created' => $created,
      'resource' => 'https://example.com/x402/resource',
      'network' => 'eip155:8453',
      'amount' => '5000',
      'pay_to' => self::WALLET,
      'payer' => self::WALLET,
      'status' => $status,
      'handler_status' => 200,
      'transaction' => '0x' . str_repeat('a', 64),
    ])->execute();
  }

  /**
   * The facts of every block, keyed "block.key".
   *
   * @return array<string, array<string, string>>
   *   The facts.
   */
  private function facts(array $blocks): array {
    $facts = [];
    foreach ($blocks as $block) {
      foreach ($block['facts'] as $fact) {
        $facts[$block['id'] . '.' . $fact['key']] = $fact;
      }
    }
    return $facts;
  }

}
