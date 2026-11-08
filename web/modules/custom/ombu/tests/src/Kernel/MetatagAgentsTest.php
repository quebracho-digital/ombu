<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ombu\Hook\MetatagHooks;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The links for agents that Metatag writes, and the tokens that feed them.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class MetatagAgentsTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck',
    'token', 'metatag',
  ];

  /**
   * Each link says what it points to, and is absolute.
   */
  public function testLinkSaysItsMediaType(): void {
    $manager = $this->container->get('plugin.manager.metatag.tag');
    $expected = [
      'ombu_guide' => ['describedby', 'text/markdown'],
      'ombu_api' => ['service-desc', 'application/json'],
      'ombu_markdown' => ['alternate', 'text/markdown'],
    ];
    foreach ($expected as $id => [$rel, $type]) {
      $tag = $manager->createInstance($id);
      $tag->setValue('/llms.txt');
      $element = $tag->output();
      $this->assertSame('link', $element['#tag'], $id);
      $this->assertSame($rel, $element['#attributes']['rel'], $id);
      $this->assertSame($type, $element['#attributes']['type'], $id);
      $this->assertStringStartsWith('http', $element['#attributes']['href'], "$id is absolute");
    }
  }

  /**
   * Tests that a link without a value is not written.
   */
  public function testLinkWithoutValueIsNotWritten(): void {
    $tag = $this->container->get('plugin.manager.metatag.tag')->createInstance('ombu_markdown');
    $tag->setValue('');
    $this->assertSame([], $tag->output());
  }

  /**
   * A page that is neither a post, a listing nor the market says nothing extra.
   */
  public function testAnotherPageHasNoMarkdownTwinOfItsOwn(): void {
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getRouteName')->willReturn('system.404');
    $hooks = new MetatagHooks($route, $this->container->get('request_stack'), $this->container->get('extension.path.resolver'));
    $tokens = $hooks->tokens('current-page', [
      'ombu-markdown' => '[current-page:ombu-markdown]',
      'ombu-type' => '[current-page:ombu-type]',
    ], [], [], new BubbleableMetadata());
    $this->assertSame('website', $tokens['[current-page:ombu-type]']);
    $this->assertSame('', $tokens['[current-page:ombu-markdown]']);
  }

}
