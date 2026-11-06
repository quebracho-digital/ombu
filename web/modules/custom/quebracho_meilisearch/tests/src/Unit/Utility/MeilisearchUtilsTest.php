<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Utility;

use Drupal\quebracho_meilisearch\Utility\MeilisearchUtils;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch\Utility\MeilisearchUtils
 */
class MeilisearchUtilsTest extends TestCase {

  /**
   * @covers ::encodeDocumentId
   */
  public function testEncodingIsReadableAndValid(): void {
    $this->assertSame('entity_3Anode_2F123_3Aen', MeilisearchUtils::encodeDocumentId('entity:node/123:en'));
    $this->assertSame('entity_3Ataxonomy__term_2F7_3Azh-hans', MeilisearchUtils::encodeDocumentId('entity:taxonomy_term/7:zh-hans'));
  }

  /**
   * IDs that differ only in characters Meilisearch forbids must stay distinct.
   *
   * @covers ::encodeDocumentId
   */
  public function testEncodingIsInjective(): void {
    $ids = [
      'entity:foo/a.b', 'entity:foo/a-b', 'entity:foo/a_b', 'entity:foo/a b',
      'entity:foo/a_2Eb', 'entity:foo/a__b', 'entity:foo/é',
    ];
    $encoded = array_map([MeilisearchUtils::class, 'encodeDocumentId'], $ids);
    $this->assertCount(count($ids), array_unique($encoded));
    foreach ($encoded as $value) {
      $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $value);
    }
  }

  /**
   * @covers ::encodeDocumentId
   */
  public function testLongIdsAreHashedWithinTheMeilisearchLimit(): void {
    $long = 'entity:foo/' . str_repeat(':', 300);
    $encoded = MeilisearchUtils::encodeDocumentId($long);
    $this->assertLessThanOrEqual(511, strlen($encoded));
    $this->assertMatchesRegularExpression('/^h_[0-9a-f]{64}$/', $encoded);
    $this->assertNotSame($encoded, MeilisearchUtils::encodeDocumentId($long . ':'));
  }

  /**
   * @covers ::isVector
   */
  public function testIsVector(): void {
    $this->assertTrue(MeilisearchUtils::isVector([1, 0.5, -2.0], 3));
    $this->assertFalse(MeilisearchUtils::isVector([1.0, 0.5], 3), 'Too short.');
    $this->assertFalse(MeilisearchUtils::isVector([1.0, 0.5, 0.0, 0.0], 3), 'Too long.');
    $this->assertFalse(MeilisearchUtils::isVector([INF, 0.5, 0.0], 3), 'Infinite.');
    $this->assertFalse(MeilisearchUtils::isVector([1 => 1.0, 2 => 0.5, 3 => 0.0], 3), 'Not a list.');
    $this->assertFalse(MeilisearchUtils::isVector(['1', 0.5, 0.0], 3), 'A numeric string.');
    $this->assertFalse(MeilisearchUtils::isVector([NAN, 0.5, 0.0], 3), 'Not finite.');
    $this->assertFalse(MeilisearchUtils::isVector([[1.0], 0.5, 0.0], 3), 'Nested.');
    $this->assertFalse(MeilisearchUtils::isVector(NULL, 3));
  }

}
