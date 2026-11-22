<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\A2ui\Functions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The basic catalog's functions that only compute.
 */
#[Group('ombu')]
final class A2uiFunctionsTest extends UnitTestCase {

  /**
   * Tests the numbers and currencies.
   *
   * @param string|null $expected
   *   What the call returns.
   * @param string $name
   *   The function.
   * @param array<string, mixed> $args
   *   The resolved arguments.
   */
  #[DataProvider('numbers')]
  public function testNumbers(?string $expected, string $name, array $args): void {
    $this->assertSame($expected, Functions::call($name, $args, NULL));
  }

  /**
   * Number and currency cases.
   *
   * @return array<string, array<mixed>>
   *   Named cases.
   */
  public static function numbers(): array {
    return [
      'integer has no decimals' => ['1,234', 'formatNumber', ['value' => 1234]],
      'fraction has two' => ['1,234.50', 'formatNumber', ['value' => 1234.5]],
      'decimals given' => ['3.142', 'formatNumber', ['value' => 3.14159, 'decimals' => 3]],
      'no grouping' => ['1234', 'formatNumber', ['value' => 1234, 'grouping' => FALSE]],
      'numeric string' => ['7', 'formatNumber', ['value' => '7']],
      'not a number' => [NULL, 'formatNumber', ['value' => 'abc']],
      'decimals are capped' => ['1.0000000000', 'formatNumber', ['value' => 1, 'decimals' => 99]],
      'currency' => ['1,234.50 USDC', 'formatCurrency', ['value' => 1234.5, 'currency' => 'USDC']],
      'currency decimals' => [
        '0.020 USDC',
        'formatCurrency',
        ['value' => 0.02, 'currency' => 'USDC', 'decimals' => 3],
      ],
      'currency code must be capitals' => [
        NULL,
        'formatCurrency',
        ['value' => 1, 'currency' => 'usd; drop'],
      ],
      'currency needs a number' => [NULL, 'formatCurrency', ['value' => 'x', 'currency' => 'USD']],
    ];
  }

  /**
   * Tests that pluralize picks zero, one or other.
   */
  public function testPluralize(): void {
    $forms = ['zero' => 'none', 'one' => 'one item', 'other' => 'many items'];
    $this->assertSame('none', Functions::call('pluralize', ['value' => 0] + $forms, NULL));
    $this->assertSame('one item', Functions::call('pluralize', ['value' => 1] + $forms, NULL));
    $this->assertSame('many items', Functions::call('pluralize', ['value' => 2] + $forms, NULL));
    // Without a zero form, zero is plural in English.
    $withoutZero = ['value' => 0, 'one' => 'one item', 'other' => 'many items'];
    $this->assertSame('many items', Functions::call('pluralize', $withoutZero, NULL));
    // The other form is required.
    $this->assertNull(Functions::call('pluralize', ['value' => 1, 'one' => 'one item'], NULL));
  }

  /**
   * Tests that the index counts from zero, with an offset, only in a template.
   */
  public function testIndex(): void {
    $this->assertSame(0, Functions::call('@index', [], 0));
    $this->assertSame(3, Functions::call('@index', ['offset' => 1], 2));
    $this->assertNull(Functions::call('@index', [], NULL));
  }

  /**
   * Tests that an unknown function returns nothing.
   */
  public function testUnknownFunction(): void {
    $this->assertNull(Functions::call('openUrl', ['url' => 'https://example.com'], NULL));
  }

  /**
   * Tests the interpolation of paths, the escape and the expressions skipped.
   */
  public function testInterpolate(): void {
    $skipped = [];
    $resolve = static fn (string $path): mixed => ['/a' => 'A', 'b' => 2, '/t' => TRUE, '/arr' => [1]][$path] ?? NULL;
    $this->assertSame('A-2-true--', Functions::interpolate('${/a}-${b}-${/t}-${/arr}-${/missing}', $resolve, $skipped));
    $this->assertSame([], $skipped);
    $this->assertSame('literal ${/a} A', Functions::interpolate('literal \${/a} ${/a}', $resolve));
    $this->assertSame('x', Functions::interpolate('${now()}x', $resolve, $skipped));
    $this->assertSame(['expression'], $skipped);
  }

}
