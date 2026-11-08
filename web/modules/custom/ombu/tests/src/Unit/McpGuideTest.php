<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\Mcp\McpGuide;
use Drupal\Tests\UnitTestCase;

/**
 * What a plain GET of /mcp says.
 *
 * @coversDefaultClass \Drupal\ombu\Mcp\McpGuide
 * @group ombu
 */
class McpGuideTest extends UnitTestCase {

  /**
   * @covers ::data
   */
  public function testTheGuideNamesTheUrlTheToolsAndThePlainHttpWay(): void {
    $data = McpGuide::data('https://ombu.example');
    $this->assertSame('https://ombu.example/mcp', $data['mcp']['url']);
    $this->assertSame('none', $data['mcp']['auth']);
    $this->assertSame(
      ['ombu_search_services', 'ombu_get_service', 'ombu_call_service'],
      array_column($data['mcp']['tools'], 'name'),
    );
    $this->assertStringContainsString('claude mcp add --transport http ombu https://ombu.example/mcp', $data['connect']['claude_code']);
    $this->assertStringStartsWith('https://ombu.example/market.json', $data['without_mcp']['search_json']);
  }

  /**
   * @covers ::markdown
   */
  public function testTheMarkdownHasTheSameThings(): void {
    $md = McpGuide::markdown('https://ombu.example');
    foreach (['https://ombu.example/mcp', 'ombu_search_services', 'ombu_call_service', 'claude mcp add', '/market.json'] as $text) {
      $this->assertStringContainsString($text, $md);
    }
    $this->assertStringNotContainsString('/market/listing/', $md, 'that address does not exist');
  }

}
