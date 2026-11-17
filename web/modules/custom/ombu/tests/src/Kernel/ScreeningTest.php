<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ombu\Form\ScreeningForm;
use Drupal\ombu\Form\ScreeningRemoveForm;
use Drupal\ombu\Screening\ScreeningLists;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * The deny list: the service the command and the panel share, and its forms.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class ScreeningTest extends CommerceKernelTestBase {

  private const EVM = '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8';

  private const SOLANA = '6PJNCqXycHy7qsPPsz1wj9REkZxnJQFutEgfKqyZpuGU';

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
   * The service checks the address and logs who changed the list.
   */
  public function testTheListsServiceValidatesAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('notice');
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getAccountName')->willReturn('ana');
    $account->method('id')->willReturn(7);

    // A directory of this test's own: the site's lists are not touched.
    $dir = sys_get_temp_dir() . '/ombu-screening-' . bin2hex(random_bytes(4));
    $this->setSetting('ombu_screening_dir', $dir);
    $this->container->get('kernel')->rebuildContainer();
    $lists = new ScreeningLists($this->container->get('ombu.screening_subscriber'), $logger, $account);
    $this->assertSame(strtolower(self::EVM), $lists->normalize(self::EVM));
    $this->assertSame(self::SOLANA, $lists->normalize(self::SOLANA));
    $this->assertNull($lists->normalize('not an address'));
    $this->assertNull($lists->normalize('0x1234'));

    $lists->deny(self::EVM);
    $this->assertContains(strtolower(self::EVM), $lists->lists()['deny']);
    $lists->remove(self::EVM);
    $this->assertNotContains(strtolower(self::EVM), $lists->lists()['deny']);
    array_map('unlink', glob("$dir/*") ?: []);
    @unlink("$dir/.htaccess");
    @rmdir($dir);
  }

  /**
   * Both forms need "manage ombu screening", and nothing else opens them.
   */
  public function testTheFormsNeedTheirPermission(): void {
    $anonymous = new AnonymousUserSession();
    // The first user is uid 1, which bypasses every permission check.
    $this->createUser();
    $without = $this->createUser();
    $with = $this->createUser(['manage ombu screening']);
    $access = $this->container->get('access_manager');

    foreach ([
      'ombu.screening' => [],
      'ombu.screening.remove' => ['address' => self::EVM],
    ] as $route => $parameters) {
      $this->assertFalse($access->checkNamedRoute($route, $parameters, $anonymous, TRUE)->isAllowed());
      $this->assertFalse($access->checkNamedRoute($route, $parameters, $without, TRUE)->isAllowed());
      $this->assertTrue($access->checkNamedRoute($route, $parameters, $with, TRUE)->isAllowed());
    }
  }

  /**
   * Blocking asks first, then calls the service with the normalized address.
   */
  public function testBlockingAsksBeforeItWrites(): void {
    $lists = $this->createMock(ScreeningLists::class);
    $lists->method('normalize')->willReturnCallback(static fn(string $address): string => strtolower($address));
    $lists->method('lists')->willReturn(['allow' => [], 'deny' => []]);
    $lists->expects($this->once())->method('deny')->with(strtolower(self::EVM))->willReturn(strtolower(self::EVM));

    $form = new ScreeningForm($lists);
    $state = (new FormState())->setValues(['address' => self::EVM]);
    $built = $form->buildForm([], $state);
    $form->validateForm($built, $state);
    $form->submitForm($built, $state);

    $this->assertSame(strtolower(self::EVM), $state->get('confirm_address'));
    $this->assertTrue($state->isRebuilding());
    $this->assertNull($state->getRedirect(), 'Nothing is written before the confirmation.');

    $confirm = (new FormState())->set('confirm_address', strtolower(self::EVM));
    $confirmation = $form->buildForm([], $confirm);
    $this->assertStringContainsString(strtolower(self::EVM), (string) $confirmation['#title']);
    $this->assertSame('hidden', $confirmation['confirm']['#type']);

    $form->submitForm($confirmation, $confirm);
    $this->assertSame('ombu.screening', $confirm->getRedirect()->getRouteName());
  }

  /**
   * Removing asks first and calls the service only on the confirmation.
   */
  public function testRemovingAsksBeforeItWrites(): void {
    $lists = $this->createMock(ScreeningLists::class);
    $lists->method('normalize')->willReturn(self::EVM);
    $lists->method('lists')->willReturn(['allow' => [], 'deny' => [self::EVM]]);
    $lists->expects($this->once())->method('remove')->with(self::EVM)->willReturn(self::EVM);

    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getParameter')->with('address')->willReturn(self::EVM);

    $form = new ScreeningRemoveForm($lists, $route);
    $state = new FormState();
    $built = $form->buildForm([], $state);
    $this->assertStringContainsString(self::EVM, (string) $built['#title']);
    $this->assertSame('hidden', $built['confirm']['#type']);

    $form->submitForm($built, $state);
    $this->assertSame('ombu.screening', $state->getRedirect()->getRouteName());
  }

}
