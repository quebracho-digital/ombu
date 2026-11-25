<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\ombu\Hook\A2uiHooks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A post that shows a drawn surface gets the surface's stylesheet.
 */
#[Group('ombu')]
final class A2uiPostHookTest extends UnitTestCase {

  /**
   * Tests when the library is attached.
   *
   * @param string $type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $mode
   *   The view mode.
   * @param string $body
   *   The body.
   * @param bool $attached
   *   Whether the library comes.
   */
  #[DataProvider('cases')]
  public function testLibraryOnlyForFullPostsWithSurface(string $type, string $bundle, string $mode, string $body, bool $attached): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->willReturnCallback(fn($name) => $name === 'value' ? $body : NULL);
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('hasField')->willReturn(TRUE);
    $entity->method('get')->willReturn($field);
    $build = [];
    (new A2uiHooks())->attachToPosts($build, $entity, $this->createMock(EntityViewDisplayInterface::class), $mode);
    $this->assertSame($attached ? ['ombu/a2ui'] : [], $build['#attached']['library'] ?? []);
  }

  /**
   * The cases.
   *
   * @return array<string, array{string, string, string, string, bool}>
   *   Named cases.
   */
  public static function cases(): array {
    $drawn = '<div class="a2ui" data-a2ui-surface="sellercheck"></div>';
    return [
      'full post with a surface' => ['node', 'blog', 'full', $drawn, TRUE],
      'full post without one' => ['node', 'blog', 'full', '<p>Hi</p>', FALSE],
      'teaser of a post with one' => ['node', 'blog', 'teaser', $drawn, FALSE],
      'another bundle' => ['node', 'page', 'full', $drawn, FALSE],
      'another entity type' => ['commerce_product', 'blog', 'full', $drawn, FALSE],
    ];
  }

}
