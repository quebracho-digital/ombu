<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\Core\Field\FieldItemList;
use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\ListingSurface;
use Drupal\ombu\Plugin\Validation\Constraint\A2uiSurface;
use Drupal\ombu\Plugin\Validation\Constraint\A2uiSurfaceValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The listing's A2UI field: only what can be drawn is saved.
 */
#[Group('ombu')]
final class A2uiSurfaceConstraintTest extends UnitTestCase {

  private const VALID = '[{"version":"1.0","createSurface":{"surfaceId":"s1","dataModel":{"text":"hi"}}},'
    . '{"version":"1.0","updateComponents":{"surfaceId":"s1","components":['
    . '{"id":"root","component":"TextField","label":"Text","value":{"@path":"/text"}}]}}]';

  /**
   * Runs the validator on a value and says whether it raised a violation.
   */
  private function violates(?string $json): bool {
    $list = $this->createMock(FieldItemList::class);
    $list->method('__get')->with('value')->willReturn($json);
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->exactly(0))->method('buildViolation');
    $raised = FALSE;
    $context->method('addViolation')->willReturnCallback(function () use (&$raised): void {
      $raised = TRUE;
    });
    $validator = new A2uiSurfaceValidator();
    $validator->initialize($context);
    $validator->validate($list, new A2uiSurface());
    return $raised;
  }

  /**
   * Tests that a payload that can be drawn passes, and an empty field too.
   */
  public function testValidAndEmptyPass(): void {
    $this->assertFalse($this->violates(self::VALID));
    $this->assertFalse($this->violates(''));
    $this->assertFalse($this->violates(NULL));
  }

  /**
   * Tests that a payload that cannot be read or drawn is refused.
   */
  public function testBrokenPayloadsAreRefused(): void {
    $this->assertTrue($this->violates('not json'));
    $this->assertTrue($this->violates('{"version":"1.0","createSurface":{"surfaceId":"s1","catalogId":"evil"}}'));
  }

  /**
   * Tests that a payload too big to draw is refused when it is saved.
   */
  public function testExpansionBombIsRefusedOnSave(): void {
    $components = [];
    for ($i = 0; $i < 20; $i++) {
      $next = 'n' . ($i + 1);
      $components[] = ['id' => $i === 0 ? 'root' : 'n' . $i, 'component' => 'Column', 'children' => [$next, $next]];
    }
    $components[] = ['id' => 'n20', 'component' => 'Text', 'text' => 'leaf'];
    $payload = json_encode([
      ['version' => '1.0', 'createSurface' => ['surfaceId' => 's1']],
      ['version' => '1.0', 'updateComponents' => ['surfaceId' => 's1', 'components' => $components]],
    ]);
    $this->assertTrue($this->violates($payload));
  }

  /**
   * Tests that the page's policy leaves out the seller's buttons and images.
   */
  public function testListingSurfaceDrawsNoButtonsAndKeepsTheModel(): void {
    $payload = '[{"version":"1.0","createSurface":{"surfaceId":"s1","dataModel":{"a":1}}},'
      . '{"version":"1.0","updateComponents":{"surfaceId":"s1","components":['
      . '{"id":"root","component":"Column","children":["b"]},'
      . '{"id":"b","component":"Button","child":"t","action":{"event":{"name":"go"}}},'
      . '{"id":"t","component":"Text","text":"Go"}]}}]';
    [$build, $skipped] = ListingSurface::build($payload);
    $this->assertSame([], $skipped);
    $this->assertSame('{"a":1}', $build['#attributes']['data-a2ui-model']);
    $this->assertArrayHasKey('root', $build);
    $this->assertSame([], $build['root'][0], 'The button is not drawn.');
    $this->expectException(A2uiException::class);
    ListingSurface::build('nope');
  }

}
