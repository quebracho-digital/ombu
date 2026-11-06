<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Search;

use Drupal\quebracho_meilisearch\Search\SearchKeys;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch\Search\SearchKeys
 */
class SearchKeysTest extends TestCase {

  /**
   * @covers ::toMeilisearch
   */
  public function testStringsAndEmptyKeysPassThrough(): void {
    $this->assertSame('', SearchKeys::toMeilisearch(NULL));
    $this->assertSame('foo "exact phrase" -bar', SearchKeys::toMeilisearch('  foo "exact phrase" -bar '));
  }

  /**
   * @covers ::toMeilisearch
   */
  public function testParsedKeysBecomeWords(): void {
    $this->assertSame('foo bar', SearchKeys::toMeilisearch(['#conjunction' => 'AND', 'foo', 'bar']));
  }

  /**
   * Multi-word keys come from quoted phrases and stay phrases.
   *
   * @covers ::toMeilisearch
   */
  public function testMultiWordKeysArePhrases(): void {
    $this->assertSame('"foo bar" baz', SearchKeys::toMeilisearch(['#conjunction' => 'AND', 'foo bar', 'baz']));
  }

  /**
   * @covers ::toMeilisearch
   */
  public function testNegatedKeysUseMeilisearchNegation(): void {
    // phpcs:disable Drupal.Arrays.Array.ArrayIndentation,Squiz.Arrays.ArrayDeclaration.NoKeySpecified
    $keys = [
      '#conjunction' => 'AND',
      'test',
      ['#conjunction' => 'OR', 'baz', 'foobar'],
      ['#conjunction' => 'OR', '#negation' => TRUE, 'bar', 'two words'],
    ];
    // phpcs:enable
    $this->assertSame('test baz foobar -bar -"two words"', SearchKeys::toMeilisearch($keys));
    $this->assertSame('-foo', SearchKeys::toMeilisearch(['#conjunction' => 'AND', '#negation' => TRUE, 'foo']));
  }

}
