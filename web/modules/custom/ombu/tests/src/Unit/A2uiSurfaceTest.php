<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\Surface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Building a surface from A2UI v1.0 messages.
 */
#[Group('ombu')]
final class A2uiSurfaceTest extends UnitTestCase {

  private const CREATE = '{"version":"1.0","createSurface":{"surfaceId":"s1"}}';

  /**
   * Tests that the official contact form (JSON Lines) builds a surface.
   */
  public function testTheContactFormExampleBuilds(): void {
    $lines = file(__DIR__ . '/../../fixtures/a2ui/contact_form_example.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Without its last line, deleteSurface.
    $surface = Surface::fromJson(implode("\n", array_slice($lines, 0, -1)));
    $this->assertSame('contact_form_1', $surface->id());
    $this->assertSame('Card', $surface->component('root')['component']);
    $this->assertSame('John', $surface->dataModel()['contact']['firstName']);
    $this->assertFalse($surface->isDeleted());
    // With it.
    $this->assertTrue(Surface::fromJson(implode("\n", $lines))->isDeleted());
  }

  /**
   * Tests that a JSON array of messages and JSON Lines are the same payload.
   */
  public function testArrayAndJsonLinesAreTheSame(): void {
    $update = '{"version":"1.0","updateComponents":{"surfaceId":"s1","components":[{"id":"root","component":"Text","text":"Hi"}]}}';
    $lines = Surface::fromJson(self::CREATE . "\n" . $update);
    $array = Surface::fromJson('[' . self::CREATE . ',' . $update . ']');
    $this->assertSame($lines->component('root'), $array->component('root'));
  }

  /**
   * Tests that a later component with the same id replaces the earlier one.
   */
  public function testComponentsAreReplacedById(): void {
    $surface = Surface::fromJson(self::CREATE . "\n"
      . '{"version":"1.0","updateComponents":{"surfaceId":"s1","components":[{"id":"root","component":"Text","text":"one"}]}}' . "\n"
      . '{"version":"1.0","updateComponents":{"surfaceId":"s1","components":[{"id":"root","component":"Text","text":"two"}]}}');
    $this->assertSame('two', $surface->component('root')['text']);
  }

  /**
   * Tests that updateDataModel writes at a path, and a NULL value deletes.
   */
  public function testDataModelUpdates(): void {
    $surface = Surface::fromJson(self::CREATE . "\n"
      . '{"version":"1.0","updateDataModel":{"surfaceId":"s1","path":"/user","value":{"name":"Ann","age":3}}}' . "\n"
      . '{"version":"1.0","updateDataModel":{"surfaceId":"s1","path":"/user/age","value":null}}');
    $this->assertSame(['user' => ['name' => 'Ann']], $surface->dataModel());
  }

  /**
   * Tests that messages that ask the renderer to run something are ignored.
   */
  public function testRendererFunctionCallsAreIgnored(): void {
    $surface = Surface::fromJson(self::CREATE . "\n"
      . '{"version":"1.0","callRendererFunction":{"functionCallId":"c1","callFunction":{"@call":"openUrl","catalogId":"x","args":{"url":"https://example.com"}}}}');
    $this->assertSame(['callRendererFunction'], $surface->ignored());
  }

  /**
   * Tests payloads that must be refused.
   *
   * @param string $payload
   *   The payload.
   */
  #[DataProvider('refused')]
  public function testRefused(string $payload): void {
    $this->expectException(A2uiException::class);
    Surface::fromJson($payload);
  }

  /**
   * Payloads the protocol or this renderer's limits refuse.
   *
   * @return array<string, array<string>>
   *   Named payloads.
   */
  public static function refused(): array {
    $update = static fn (string $surface, string $components): string => '{"version":"1.0","updateComponents":{"surfaceId":"' . $surface . '","components":' . $components . '}}';
    return [
      'not JSON' => ['<html>'],
      'empty' => [''],
      'another version' => ['{"version":"0.9","createSurface":{"surfaceId":"s1"}}'],
      'two message keys' => ['{"version":"1.0","createSurface":{"surfaceId":"s1"},"deleteSurface":{"surfaceId":"s1"}}'],
      'no message key' => ['{"version":"1.0"}'],
      'update before create' => [$update('s1', '[{"id":"root","component":"Text"}]')],
      'update of another surface' => [self::CREATE . "\n" . $update('s2', '[{"id":"root","component":"Text"}]')],
      'a second createSurface' => [self::CREATE . "\n" . self::CREATE],
      'another catalog' => ['{"version":"1.0","createSurface":{"surfaceId":"s1","catalogId":"evil.example:catalog"}}'],
      'no surface id' => ['{"version":"1.0","createSurface":{}}'],
      'component without id' => [self::CREATE . "\n" . $update('s1', '[{"component":"Text"}]')],
      'component id that is not an identifier' => [self::CREATE . "\n" . $update('s1', '[{"id":"a b","component":"Text"}]')],
      'component without type' => [self::CREATE . "\n" . $update('s1', '[{"id":"root"}]')],
      'empty component list' => [self::CREATE . "\n" . $update('s1', '[]')],
      'data model without value' => [self::CREATE . "\n" . '{"version":"1.0","updateDataModel":{"surfaceId":"s1","path":"/a"}}'],
      'a message that is not an object' => [self::CREATE . "\n" . '"text"'],
      'only a message that is not createSurface' => ['{"version":"1.0","deleteSurface":{"surfaceId":"s1"}}'],
    ];
  }

  /**
   * Tests the size limits.
   */
  public function testLimits(): void {
    $this->expectException(A2uiException::class);
    Surface::fromJson(str_repeat(' ', Surface::MAX_BYTES + 1));
  }

  /**
   * Tests that too many components are refused.
   */
  public function testTooManyComponents(): void {
    $components = [];
    for ($i = 0; $i <= Surface::MAX_COMPONENTS; $i++) {
      $components[] = ['id' => 'c' . $i, 'component' => 'Text', 'text' => 'x'];
    }
    $this->expectException(A2uiException::class);
    $update = ['surfaceId' => 's1', 'components' => $components];
    Surface::fromJson(self::CREATE . "\n" . json_encode(['version' => '1.0', 'updateComponents' => $update]));
  }

}
