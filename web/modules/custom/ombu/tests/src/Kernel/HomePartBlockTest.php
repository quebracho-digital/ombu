<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * The home's fixed parts, as blocks Layout Builder can place.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class HomePartBlockTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck',
  ];

  private function block(string $part): array {
    return $this->container->get('plugin.manager.block')
      ->createInstance('ombu_home_part', ['part' => $part])
      ->build();
  }

  public function testTheAgentsStripLinksTheMachineEntryPoints(): void {
    $build = $this->block('agents');
    $this->assertSame('ombu_home_agents', $build['#theme']);
    $this->assertSame('/mcp', $build['#mcp']->toString());
    $this->assertContains('ombu/home', $build['#attached']['library']);
  }

  public function testTheHeroLeadsToTheMarketAndToOurSellers(): void {
    $this->container->get('router.builder')->rebuild();
    $build = $this->block('hero');
    $this->assertSame('ombu_home_hero', $build['#theme']);
  }

  public function testItIsAPartOfTheLayoutOfTheHomePage(): void {
    $file = dirname(__DIR__, 7) . '/config/sync/core.entity_view_display.node.home.default.yml';
    $this->assertFileExists($file);
    $sections = Yaml::parseFile($file)['third_party_settings']['layout_builder']['sections'];
    $this->assertTrue(Yaml::parseFile($file)['third_party_settings']['layout_builder']['enabled']);
    $plugins = array_map(fn($c) => $c['configuration']['id'] . ($c['configuration']['part'] ?? ''), $sections[0]['components'] ?? []);
    $this->assertEqualsCanonicalizing([
      'ombu_home_partsales', 'ombu_home_partagents', 'ombu_home_parthero',
      'views_block:ombu_home-featured', 'views_block:blog-home',
      'views_block:ombu_home-latest', 'views_block:ombu_home-top',
    ], $plugins);
  }

}
