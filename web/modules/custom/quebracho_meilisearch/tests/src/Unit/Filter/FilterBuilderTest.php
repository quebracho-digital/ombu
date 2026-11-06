<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Filter;

use Drupal\quebracho_meilisearch\Filter\BetweenOperatorParser;
use Drupal\quebracho_meilisearch\Filter\BooleanValueParser;
use Drupal\quebracho_meilisearch\Filter\FilterBuilder;
use Drupal\quebracho_meilisearch\Filter\InOperatorParser;
use Drupal\quebracho_meilisearch\Filter\MeilisearchFilterException;
use Drupal\quebracho_meilisearch\Filter\NotBetweenOperatorParser;
use Drupal\quebracho_meilisearch\Filter\NotInOperatorParser;
use Drupal\quebracho_meilisearch\Filter\NullValueParser;
use Drupal\quebracho_meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\ConditionGroup;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch\Filter\FilterBuilder
 */
class FilterBuilderTest extends TestCase {

  /**
   * Field types of the mocked index.
   */
  protected const FIELDS = [
    'title' => 'text',
    'count' => 'integer',
    'genre' => 'string',
    'rating' => 'decimal',
    'published' => 'boolean',
    'category' => 'string',
    'created' => 'date',
  ];

  /**
   * Tests single condition.
   */
  public function testSingleCondition(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');

    $this->assertSame('title = "Hello"', $this->build($group));
  }

  /**
   * Tests and group.
   */
  public function testAndGroup(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');
    $group->addCondition('count', 5, '>');

    $this->assertSame('(title = "Hello" AND count > 5)', $this->build($group));
  }

  /**
   * Tests or group.
   */
  public function testOrGroup(): void {
    $group = new ConditionGroup('OR');
    $group->addCondition('genre', 'action', '=');
    $group->addCondition('genre', 'comedy', '=');

    $this->assertSame('(genre = "action" OR genre = "comedy")', $this->build($group));
  }

  /**
   * Tests nested groups.
   */
  public function testNestedGroups(): void {
    $inner = new ConditionGroup('OR');
    $inner->addCondition('genre', 'action', '=');
    $inner->addCondition('genre', 'comedy', '=');
    $outer = new ConditionGroup('AND');
    $outer->addConditionGroup($inner);
    $outer->addCondition('rating', 4, '>=');

    $this->assertSame('((genre = "action" OR genre = "comedy") AND rating >= 4)', $this->build($outer));
  }

  /**
   * Tests empty group returns null.
   */
  public function testEmptyGroupReturnsNull(): void {
    $this->assertNull($this->build(new ConditionGroup('AND')));
  }

  /**
   * Groups tagged for an OR facet are left out when that tag is excluded.
   *
   * @covers ::build
   */
  public function testExcludedTagsAreSkipped(): void {
    $facet = new ConditionGroup('OR', ['facet:genre']);
    $facet->addCondition('genre', 'action');
    $outer = new ConditionGroup('AND');
    $outer->addConditionGroup($facet);
    $outer->addCondition('count', 1);

    $this->assertSame('count = 1', $this->build($outer, ['facet:genre']));
    $this->assertSame('(genre = "action" AND count = 1)', $this->build($outer));
  }

  /**
   * An unknown field must fail loudly instead of widening the results.
   *
   * @covers ::build
   */
  public function testUnknownFieldThrows(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('not_indexed', 'x');

    $this->expectException(MeilisearchFilterException::class);
    $this->expectExceptionMessageMatches('/not_indexed/');
    $this->build($group);
  }

  /**
   * A condition no parser supports must fail loudly.
   *
   * @covers ::build
   */
  public function testUnsupportedConditionThrows(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('genre', ['a', 'b'], '=');

    $this->expectException(MeilisearchFilterException::class);
    $this->build($group);
  }

  /**
   * @covers ::build
   */
  public function testSpecialFieldsAreFilterable(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('search_api_language', ['en', 'und'], 'IN');
    $group->addCondition('search_api_datasource', 'entity:node');
    $group->addCondition('search_api_id', 'entity:node/3:en', '>');

    $this->assertSame(
      '(search_api_language IN ["en", "und"] AND search_api_datasource = "entity:node" AND search_api_id > "entity:node/3:en")',
      $this->build($group),
    );
  }

  /**
   * Documents never store NULL: missing values are missing attributes.
   *
   * @covers ::build
   */
  public function testNullConditionsUseExistence(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('category', NULL);
    $this->assertSame('(category NOT EXISTS OR category IS NULL)', $this->build($group));

    $group = new ConditionGroup('AND');
    $group->addCondition('category', NULL, '<>');
    $this->assertSame('(category EXISTS AND NOT category IS NULL)', $this->build($group));
  }

  /**
   * Values are formatted according to the field type.
   *
   * @covers ::build
   */
  public function testValuesFollowFieldTypes(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('genre', '123');
    $group->addCondition('count', '7');
    $group->addCondition('published', '0');
    $group->addCondition('created', '2026-01-01', '>=');
    $this->assertSame(
      sprintf('(genre = "123" AND count = 7 AND published = false AND created >= %d)', strtotime('2026-01-01')),
      $this->build($group),
    );
  }

  /**
   * @covers ::build
   */
  public function testBetweenQuotesStrings(): void {
    $group = new ConditionGroup('AND');
    $group->addCondition('category', ['', 'foo'], 'BETWEEN');
    $group->addCondition('rating', ['0.9', '1.5'], 'NOT BETWEEN');

    $this->assertSame('(category "" TO "foo" AND NOT rating 0.9 TO 1.5)', $this->build($group));
  }

  /**
   * Builds a filter with every parser registered, like the container does.
   */
  private function build(ConditionGroup $group, array $excludeTags = []): ?string {
    $builder = new FilterBuilder();
    foreach ([
      new BooleanValueParser(),
      new NullValueParser(),
      new BetweenOperatorParser(),
      new NotBetweenOperatorParser(),
      new InOperatorParser(),
      new NotInOperatorParser(),
      new ScalarValueParser(),
    ] as $parser) {
      $builder->addConditionParser($parser);
    }
    return $builder->build($group, $this->createIndex(), $excludeTags);
  }

  /**
   * Returns an index mock with the fields of self::FIELDS.
   */
  private function createIndex(): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturnCallback(function (string $id) {
      if (!isset(self::FIELDS[$id])) {
        return NULL;
      }
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn(self::FIELDS[$id]);
      return $field;
    });
    return $index;
  }

}
