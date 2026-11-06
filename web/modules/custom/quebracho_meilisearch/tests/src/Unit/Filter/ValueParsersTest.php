<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Filter;

use Drupal\quebracho_meilisearch\Filter\BooleanValueParser;
use Drupal\quebracho_meilisearch\Filter\NullValueParser;
use Drupal\quebracho_meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

/**
 * Tests the value-based condition parsers.
 */
class ValueParsersTest extends TestCase {

  /**
   * Tests scalar equals.
   */
  public function testScalarEquals(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'Hello', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('title = "Hello"', $parser->parse($condition, $index));
  }

  /**
   * Tests scalar greater than.
   */
  public function testScalarGreaterThan(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('count', 5, '>');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('count > 5', $parser->parse($condition, $index));
  }

  /**
   * Tests scalar quotes strings escapes double.
   */
  public function testScalarQuotesStringsEscapesDouble(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'He said "hi"', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('title = "He said \\"hi\\""', $parser->parse($condition, $index));
  }

  /**
   * Tests boolean value.
   */
  public function testBooleanValue(): void {
    $parser = new BooleanValueParser();
    $condition = new Condition('published', TRUE, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('published = true', $parser->parse($condition, $index));

    $condition = new Condition('published', FALSE, '=');
    $this->assertSame('published = false', $parser->parse($condition, $index));
  }

  /**
   * Tests null is.
   */
  public function testNullIs(): void {
    $parser = new NullValueParser();
    $condition = new Condition('field', NULL, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('(field NOT EXISTS OR field IS NULL)', $parser->parse($condition, $index));

    $condition = new Condition('field', NULL, '<>');
    $this->assertSame('(field EXISTS AND NOT field IS NULL)', $parser->parse($condition, $index));
  }

}
