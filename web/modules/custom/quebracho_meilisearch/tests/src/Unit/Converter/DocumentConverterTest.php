<?php

declare(strict_types=1);

namespace Drupal\Tests\quebracho_meilisearch\Unit\Converter;

use Drupal\quebracho_meilisearch\Converter\DocumentConverter;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\quebracho_meilisearch\Converter\DocumentConverter
 */
class DocumentConverterTest extends TestCase {

  /**
   * @covers ::convertToDocuments
   */
  public function testDocumentCarriesSearchApiSpecialFields(): void {
    $item = $this->buildItem('entity:node/123:en', []);
    $docs = (new DocumentConverter())->convertToDocuments([$item->getId() => $item]);

    $this->assertCount(1, $docs);
    $this->assertSame([
      'search_api_document_id' => 'entity_3Anode_2F123_3Aen',
      'search_api_id' => 'entity:node/123:en',
      'search_api_datasource' => 'entity:node',
      'search_api_language' => 'en',
    ], $docs[0]);
  }

  /**
   * A Drupal field called "id" must not replace the document's primary key.
   *
   * @covers ::convertToDocuments
   */
  public function testFieldNamedIdKeepsDocumentIdentity(): void {
    $item = $this->buildItem('entity:node/1:en', [
      'id' => ['type' => 'integer', 'values' => [1]],
    ]);
    $docs = (new DocumentConverter())->convertToDocuments([$item->getId() => $item]);

    $this->assertSame(1, $docs[0]['id']);
    $this->assertSame('entity_3Anode_2F1_3Aen', $docs[0]['search_api_document_id']);
  }

  /**
   * Fields starting with "_" would collide with Meilisearch reserved fields.
   *
   * @covers ::convertToDocuments
   */
  public function testSkipsFieldsReservedByMeilisearch(): void {
    $item = $this->buildItem('node/1', [
      '_vectors' => ['type' => 'string', 'values' => ['x']],
    ]);
    $docs = (new DocumentConverter())->convertToDocuments([$item->getId() => $item]);

    $this->assertArrayNotHasKey('_vectors', $docs[0]);
  }

  /**
   * @covers ::convertToDocuments
   */
  public function testOnlyTheFirstLocationFieldBecomesGeo(): void {
    $item = $this->buildItem('node/1', [
      'home' => ['type' => 'location', 'values' => ['48.85,2.29']],
      'work' => ['type' => 'location', 'values' => ['40.7,-74.0']],
    ]);
    $docs = (new DocumentConverter())->convertToDocuments([$item->getId() => $item]);

    $this->assertSame(['lat' => 48.85, 'lng' => 2.29], $docs[0]['_geo']);
  }

  /**
   * @covers ::convertToDocuments
   */
  public function testMapsFieldTypes(): void {
    $item = $this->buildItem('node/1', [
      'title' => ['type' => 'string', 'values' => ['Hello']],
      'count' => ['type' => 'integer', 'values' => [42]],
      'published' => ['type' => 'boolean', 'values' => [TRUE]],
      'tags' => ['type' => 'string', 'values' => ['a', 'b', 'c']],
    ]);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    $this->assertSame('Hello', $docs[0]['title']);
    $this->assertSame(42, $docs[0]['count']);
    $this->assertTrue($docs[0]['published']);
    $this->assertSame(['a', 'b', 'c'], $docs[0]['tags']);
  }

  /**
   * @covers ::convertToDocuments
   */
  public function testConvertsGeoFieldToMeiliGeo(): void {
    $item = $this->buildItem('node/1', [
      'location' => ['type' => 'location', 'values' => ['48.85,2.29']],
    ]);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    $this->assertArrayHasKey('_geo', $docs[0]);
    $this->assertSame(['lat' => 48.85, 'lng' => 2.29], $docs[0]['_geo']);
  }

  /**
   * Out-of-range coordinates would make Meilisearch reject the whole batch.
   *
   * @covers ::convertToDocuments
   */
  public function testInvalidCoordinatesAreDropped(): void {
    $converter = new DocumentConverter();
    foreach (['91,2', '48,181', 'abc,def', 'nowhere'] as $value) {
      $item = $this->buildItem('node/1', ['home' => ['type' => 'location', 'values' => [$value]]]);
      $docs = $converter->convertToDocuments([$item->getId() => $item]);
      $this->assertArrayNotHasKey('_geo', $docs[0], "'$value' is not a valid location.");
    }
  }

  /**
   * Tests that unparseable dates are dropped.
   *
   * They must not surface as FALSE/0 in the indexed document: Meilisearch
   * would reject or silently mis-index such values.
   *
   * @covers ::convertToDocuments
   */
  public function testDropsUnparseableDateValues(): void {
    $item = $this->buildItem('node/1', [
      'created' => ['type' => 'date', 'values' => ['not-a-date', '2026-04-15', 'also-garbage']],
      'bad_only' => ['type' => 'date', 'values' => ['nope']],
      'bad_int' => ['type' => 'integer', 'values' => ['abc', '7']],
    ]);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    // The good date survived, the bad ones were dropped.
    $this->assertSame(strtotime('2026-04-15'), $docs[0]['created']);
    // No parseable values — field must be absent, not present as NULL/FALSE/0.
    $this->assertArrayNotHasKey('bad_only', $docs[0]);
    // Mixed integer: only the numeric value kept.
    $this->assertSame(7, $docs[0]['bad_int']);
  }

  /**
   * Builds a Search API item mock with the given fields.
   */
  private function buildItem(string $itemId, array $fields): ItemInterface {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getId')->willReturn($itemId);
    [$datasource] = explode('/', $itemId, 2);
    $item->method('getDatasourceId')->willReturn($datasource);
    $item->method('getLanguage')->willReturn('en');

    $fieldObjects = [];
    foreach ($fields as $id => $spec) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getFieldIdentifier')->willReturn($id);
      $field->method('getType')->willReturn($spec['type']);
      $field->method('getValues')->willReturn($spec['values']);
      $fieldObjects[$id] = $field;
    }
    $item->method('getFields')->willReturn($fieldObjects);
    return $item;
  }

}
