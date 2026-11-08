<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\ombu\Plugin\Block\ShareBlock;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\ombu\Plugin\Block\ShareBlock
 * @group ombu
 */
class ShareBlockTest extends UnitTestCase {

  /**
   * Block.
   */
  private function block(string $route, ?string $param = NULL, ?string $bundle = NULL): ShareBlock {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($route);
    if ($param) {
      $url = $this->createMock(Url::class);
      $url->method('toString')->willReturn('https://ombu.example/thing');
      $entity = $this->createMock(EntityInterface::class);
      $entity->method('bundle')->willReturn($bundle);
      $entity->method('label')->willReturn('A thing');
      $entity->method('toUrl')->willReturn($url);
      $routeMatch->method('getParameter')->with($param)->willReturn($entity);
    }
    $block = new ShareBlock([], 'ombu_share', ['provider' => 'ombu'], $routeMatch, new RequestStack());
    $block->setStringTranslation($this->getStringTranslationStub());
    return $block;
  }

  /**
   * @covers ::build
   */
  public function testBlogPostAndListingAreShared(): void {
    foreach ([
      ['entity.node.canonical', 'node', 'blog'],
      ['entity.commerce_product.canonical', 'commerce_product', 'listing'],
    ] as [$route, $param, $bundle]) {
      $build = $this->block($route, $param, $bundle)->build();
      $this->assertSame('ombu_share', $build['#theme'], $route);
      $this->assertSame('https://ombu.example/thing', $build['#url']);
      $this->assertCount(7, $build['#links']);
      $this->assertFalse($build['#links']['email']['external']);
      $this->assertTrue($build['#links']['bluesky']['external']);
    }
  }

  /**
   * @covers ::build
   */
  public function testAnythingElseIsNotShared(): void {
    $this->assertSame([], $this->block('entity.node.canonical', 'node', 'page')->build());
    $this->assertSame([], $this->block('entity.commerce_product.canonical', 'commerce_product', 'other')->build());
    $this->assertSame([], $this->block('user.login')->build());
  }

}
