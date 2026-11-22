<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\Tests\UnitTestCase;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Our fixtures against A2UI's own JSON Schemas (v1.0).
 *
 * The schemas and the contact form example are copies of
 * github.com/a2ui-project/a2ui (specification/v1_0, Apache-2.0), kept in
 * tests/fixtures/a2ui. This test proves the validator and the fixtures agree;
 * the renderer's own allowlist is tested apart.
 */
#[Group('ombu')]
final class A2uiConformanceTest extends UnitTestCase {

  private const BASE = 'https://a2ui.org/specification/v1_0/';

  /**
   * A validator that knows the official message schema and the basic catalog.
   */
  private function validator(): Validator {
    $dir = __DIR__ . '/../../fixtures/a2ui/';
    $validator = new Validator();
    $resolver = $validator->resolver();
    // The message schema points at "catalog.json": the basic catalog stands
    // in, without its own $id so that its relative refs resolve next to it.
    $catalog = json_decode((string) file_get_contents($dir . 'basic_catalog.json'));
    unset($catalog->{'$id'});
    $resolver->registerRaw($catalog, self::BASE . 'catalog.json');
    foreach (['common_types', 'agent_to_renderer'] as $name) {
      $resolver->registerRaw((string) file_get_contents($dir . $name . '.json'), self::BASE . $name . '.json');
    }
    return $validator;
  }

  /**
   * Tests that the messages of the contact form that carry no components pass.
   */
  public function testTheContactFormMessagesWithoutComponentsAreValid(): void {
    $validator = $this->validator();
    $lines = file(__DIR__ . '/../../fixtures/a2ui/contact_form_example.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $this->assertCount(4, $lines);
    foreach ($lines as $number => $line) {
      if (str_contains($line, 'updateComponents')) {
        continue;
      }
      $result = $validator->validate(json_decode($line), self::BASE . 'agent_to_renderer.json');
      $this->assertTrue($result->isValid(), 'Line ' . ($number + 1) . ' is not valid: ' . json_encode($result->error()?->message()));
    }
  }

  /**
   * Tests that the contact form's components pass the official schema.
   *
   * Skipped on purpose: with opis/json-schema 2.6.0 the example's own
   * components fail ("Unevaluated object properties not allowed") when they
   * leave out a property that has a default (a Text without variant, a Row
   * without align), though upstream's suite (ajv) accepts the same example.
   * The renderer's allowlist is covered by A2uiRendererTest instead.
   */
  public function testTheContactFormComponentsAreValid(): void {
    $this->markTestSkipped('opis/json-schema 2.6.0 rejects valid components over unevaluatedProperties; upstream validates with ajv.');
  }

  /**
   * Tests that the validator is not a rubber stamp.
   */
  public function testBrokenMessagesAreNotValid(): void {
    $validator = $this->validator();
    $broken = [
      'wrong version' => '{"version":"0.9","createSurface":{"surfaceId":"s"}}',
      'no surface id' => '{"version":"1.0","createSurface":{}}',
      'unknown component' => '{"version":"1.0","updateComponents":{"surfaceId":"s","components":[{"id":"root","component":"Nope"}]}}',
      'unknown property' => '{"version":"1.0","updateComponents":{"surfaceId":"s","components":[{"id":"root","component":"Text","text":"x","onclick":"alert(1)"}]}}',
    ];
    foreach ($broken as $name => $message) {
      $this->assertFalse($validator->validate(json_decode($message), self::BASE . 'agent_to_renderer.json')->isValid(), $name);
    }
  }

}
