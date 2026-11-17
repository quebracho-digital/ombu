<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\State\StateInterface;
use Drupal\ombu\Form\WalletChangeForm;
use Drupal\ombu\WalletChanges;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A changed wallet: the service the command and the panel share, and its form.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class WalletChangeTest extends CommerceKernelTestBase {

  private const OLD = '0x1111111111111111111111111111111111111111';

  private const NEW = '0x2222222222222222222222222222222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'views', 'search_api', 'search_api_db', 'facets', 'better_exposed_filters',
    'facets_exposed_filters', 'language', 'mcp_server', 'node', 'datetime',
    'path', 'path_alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installConfig(['commerce_product', 'ombu']);
  }

  /**
   * Waiting merges both sources; accepting asks the importer of the source.
   */
  public function testTheServiceReadsAndAccepts(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnMap([
      ['ombu.catalog.wallet_changed.bazaar', [], [
        10 => ['url' => 'https://seller.example/a', 'old' => [self::OLD], 'new' => [self::NEW], 'time' => 100],
      ]],
      ['ombu.catalog.wallet_changed.payai', [], [
        11 => ['url' => 'https://seller.example/b', 'old' => [], 'new' => [self::NEW], 'time' => 200],
      ]],
    ]);

    $product = Product::create(['type' => 'listing', 'field_source' => 'bazaar']);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(10)->willReturn($product);
    $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypes->method('getStorage')->with('commerce_product')->willReturn($storage);

    $importer = new class() {

      /**
       * The listings accepted, in order.
       *
       * @var array<int, object>
       */
      public array $accepted = [];

      /**
       * Takes the new wallet, as the real importer does.
       */
      public function acceptWallet($product): array {
        $this->accepted[] = $product;
        return ['verdict' => 'pass', 'checks' => ['probe' => ['pass', '1 requirement(s)']]];
      }

    };
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('ombu.bazaar_importer')->willReturn($importer);

    $changes = new WalletChanges($state, $entityTypes, $container);
    $waiting = $changes->waiting();
    $this->assertCount(2, $waiting);
    $this->assertSame('bazaar', $waiting[10]['source']);
    $this->assertSame([self::OLD], $waiting[10]['old']);
    $this->assertSame('payai', $waiting[11]['source']);
    $this->assertSame($waiting[10], $changes->change(10));
    $this->assertNull($changes->change(12));

    $report = $changes->accept(10);
    $this->assertSame('pass', $report['verdict']);
    $this->assertSame($product, $importer->accepted[0]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Listing 10 is not from payai.');
    $changes->accept(10, 'payai');
  }

  /**
   * Accepting needs its own permission; managing the lists does not open it.
   */
  public function testTheFormNeedsItsPermission(): void {
    $anonymous = new AnonymousUserSession();
    // The first user is uid 1, which bypasses every permission check.
    $this->createUser();
    $without = $this->createUser();
    $screening = $this->createUser(['manage ombu screening']);
    $with = $this->createUser(['accept ombu wallet changes']);
    $access = $this->container->get('access_manager');
    $parameters = ['commerce_product' => 1];

    $this->assertFalse($access->checkNamedRoute('ombu.wallet_change_accept', $parameters, $anonymous, TRUE)->isAllowed());
    $this->assertFalse($access->checkNamedRoute('ombu.wallet_change_accept', $parameters, $without, TRUE)->isAllowed());
    $this->assertFalse($access->checkNamedRoute('ombu.wallet_change_accept', $parameters, $screening, TRUE)->isAllowed());
    $this->assertTrue($access->checkNamedRoute('ombu.wallet_change_accept', $parameters, $with, TRUE)->isAllowed());
  }

  /**
   * The confirmation shows both wallets, and the submit calls the service.
   */
  public function testAcceptingShowsBothWalletsAndCallsTheService(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $change = [
      'id' => 7,
      'source' => 'bazaar',
      'url' => 'https://seller.example/x',
      'old' => [self::OLD],
      'new' => [self::NEW],
      'time' => 100,
    ];
    $changes = $this->createMock(WalletChanges::class);
    $changes->method('change')->willReturn($change);
    $changes->expects($this->once())->method('accept')->with(7)->willReturn([
      'verdict' => 'pass',
      'checks' => ['probe' => ['pass', '1 requirement(s)']],
    ]);

    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getParameter')->with('commerce_product')->willReturn('7');
    $form = new WalletChangeForm($changes, $route);
    \Drupal::messenger()->deleteAll();
    $state = new FormState();
    $built = $form->buildForm([], $state);

    $this->assertStringContainsString('https://seller.example/x', (string) $built['#title']);
    $this->assertStringContainsString(self::OLD, (string) $built['description']['#markup']);
    $this->assertStringContainsString(self::NEW, (string) $built['description']['#markup']);
    $this->assertSame('hidden', $built['confirm']['#type']);

    $form->submitForm($built, $state);
    $this->assertSame('ombu.dashboard', $state->getRedirect()->getRouteName());
    $this->assertSame('ombu-wallet_changes', $state->getRedirect()->getOption('fragment'));
    $messages = array_map('strval', \Drupal::messenger()->messagesByType('status'));
    $this->assertNotEmpty($messages);
    $this->assertStringContainsString('1 requirement(s)', implode(' ', $messages));
    $this->assertStringContainsString('Accepted', implode(' ', $messages));
  }

}
