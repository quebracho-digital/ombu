<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\Pointer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The data model's JSON Pointers (RFC 6901).
 */
#[Group('ombu')]
final class A2uiPointerTest extends UnitTestCase {

  /**
   * Tests that a pointer reads nested values, and the escapes of RFC 6901.
   */
  public function testGetReadsNestedValuesAndEscapes(): void {
    $data = ['contact' => ['email' => 'a@b.c'], 'a/b' => 1, 'm~n' => 2, 'list' => ['x', 'y']];
    $this->assertSame('a@b.c', Pointer::get($data, '/contact/email'));
    $this->assertSame(1, Pointer::get($data, '/a~1b'));
    $this->assertSame(2, Pointer::get($data, '/m~0n'));
    $this->assertSame('y', Pointer::get($data, '/list/1'));
    $this->assertSame($data, Pointer::get($data, '/'));
  }

  /**
   * Tests that a missing path, a relative one and a scalar in the way are NULL.
   */
  public function testGetIsNullWhenItIsNotThere(): void {
    $data = ['a' => 'text'];
    $this->assertNull(Pointer::get($data, '/b'));
    $this->assertNull(Pointer::get($data, '/a/b'));
    $this->assertNull(Pointer::get($data, 'a'));
    $this->assertNull(Pointer::get($data, '/' . implode('/', array_fill(0, 40, 'a'))));
  }

  /**
   * Tests that set creates the path, replaces, and NULL removes the key.
   */
  public function testSetCreatesReplacesAndRemoves(): void {
    $data = [];
    Pointer::set($data, '/contact/email', 'a@b.c');
    $this->assertSame(['contact' => ['email' => 'a@b.c']], $data);
    Pointer::set($data, '/contact/email', 'd@e.f');
    $this->assertSame('d@e.f', $data['contact']['email']);
    Pointer::set($data, '/contact/email', NULL);
    $this->assertSame(['contact' => []], $data);
    // Removing what is not there changes nothing.
    Pointer::set($data, '/nope/deeper', NULL);
    $this->assertSame(['contact' => []], $data);
  }

  /**
   * Tests that the root is replaced by an object, never by a scalar.
   */
  public function testTheRootIsAnObject(): void {
    $data = ['a' => 1];
    Pointer::set($data, '/', ['b' => 2]);
    $this->assertSame(['b' => 2], $data);
    $this->expectException(A2uiException::class);
    Pointer::set($data, '/', 'text');
  }

  /**
   * Tests that a relative pointer is refused when writing.
   */
  public function testSetRefusesRelativePointers(): void {
    $data = [];
    $this->expectException(A2uiException::class);
    Pointer::set($data, 'a/b', 1);
  }

}
