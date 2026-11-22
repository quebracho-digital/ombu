<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\Renderer;
use Drupal\ombu\A2ui\Surface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An A2UI surface drawn as HTML, and what a hostile one cannot do.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class A2uiRendererTest extends KernelTestBase {

  /**
   * Renders a payload to HTML.
   */
  private function html(string $payload, bool $images = FALSE, ?Renderer &$renderer = NULL): string {
    $renderer = new Renderer($images);
    $build = $renderer->build(Surface::fromJson($payload));
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Builds a payload: createSurface, then these components.
   *
   * @param array<int, array<string, mixed>> $components
   *   The components.
   * @param array<string, mixed> $data
   *   The data model.
   */
  private function payload(array $components, array $data = []): string {
    $create = ['surfaceId' => 's1', 'dataModel' => $data];
    $update = ['surfaceId' => 's1', 'components' => $components];
    return json_encode(['version' => '1.0', 'createSurface' => $create])
      . "\n" . json_encode(['version' => '1.0', 'updateComponents' => $update]);
  }

  /**
   * Tests that the official contact form draws its fields, bound to the data.
   */
  public function testTheContactFormIsDrawn(): void {
    $lines = file(__DIR__ . '/../../fixtures/a2ui/contact_form_example.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $html = $this->html(implode("\n", array_slice($lines, 0, -1)), FALSE, $renderer);
    $this->assertStringContainsString('<h2', $html);
    $this->assertStringContainsString('Contact Us', $html);
    $this->assertStringContainsString('value="John"', $html);
    $this->assertStringContainsString('data-a2ui-path="/contact/email"', $html);
    $this->assertStringContainsString('type="checkbox"', $html);
    $this->assertStringContainsString('<button', $html);
    // Nothing submits by itself: no form, no script.
    $this->assertStringNotContainsString('<form', $html);
    $this->assertStringNotContainsString('<script', $html);
  }

  /**
   * Tests that seller text is text: markup in it is escaped everywhere.
   */
  public function testSellerTextIsEscaped(): void {
    $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'Column', 'children' => ['t', 'f', 'a', 'b', 'c']],
      ['id' => 't', 'component' => 'Text', 'text' => $evil],
      ['id' => 'f', 'component' => 'TextField', 'label' => $evil, 'value' => '"><script>alert(3)</script>'],
      [
        'id' => 'a',
        'component' => 'TextField',
        'label' => 'x',
        'variant' => 'longText',
        'value' => '</textarea><script>alert(4)</script>',
      ],
      ['id' => 'b', 'component' => 'CheckBox', 'label' => $evil, 'value' => TRUE],
      [
        'id' => 'c',
        'component' => 'ChoicePicker',
        'label' => $evil,
        'options' => [['label' => $evil, 'value' => '"><script>alert(5)</script>']],
        'value' => [],
      ],
    ]));
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<img src=x', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
  }

  /**
   * Tests that a number field takes fractions.
   */
  public function testNumberFieldsTakeFractions(): void {
    $html = $this->html($this->payload([
      self::c('root', 'TextField', ['label' => 'Budget', 'variant' => 'number', 'value' => ['@path' => '/budget']]),
    ], ['budget' => 0.05]));
    $this->assertStringContainsString('type="number"', $html);
    $this->assertStringContainsString('step="any"', $html);
    $this->assertStringContainsString('value="0.05"', $html);
  }

  /**
   * Tests that a binding path cannot break out of its attribute.
   */
  public function testBindingPathIsEscaped(): void {
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'TextField', 'label' => 'x', 'value' => ['@path' => '/a" onfocus="alert(1)']],
    ]));
    $this->assertStringNotContainsString('onfocus="alert(1)"', $html);
    $this->assertStringContainsString('data-a2ui-path=', $html);
  }

  /**
   * Tests that images are not loaded unless allowed, and only over http(s).
   */
  public function testImages(): void {
    $image = static fn (string $url): array => [
      ['id' => 'root', 'component' => 'Image', 'url' => $url, 'description' => 'A cat'],
    ];
    $off = $this->html($this->payload($image('https://example.com/cat.png')));
    $this->assertStringNotContainsString('<img', $off);
    $this->assertStringContainsString('A cat', $off);

    $on = $this->html($this->payload($image('https://example.com/cat.png')), TRUE);
    $this->assertStringContainsString('<img', $on);
    $this->assertStringContainsString('referrerpolicy="no-referrer"', $on);

    $hostile = [
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'https://example.com/a"onerror="alert(1)',
      '//example.com/cat.png',
      'file:///etc/passwd',
    ];
    foreach ($hostile as $url) {
      $html = $this->html($this->payload($image($url)), TRUE);
      $this->assertStringNotContainsString('<img', $html, $url);
    }
  }

  /**
   * Tests that a button only names its action, and a bad name disables it.
   */
  public function testButtons(): void {
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'Column', 'children' => ['ok', 'bad', 'fn']],
      [
        'id' => 'ok',
        'component' => 'Button',
        'child' => 'label',
        'variant' => 'primary',
        'action' => ['event' => ['name' => 'submit_form']],
      ],
      ['id' => 'bad', 'component' => 'Button', 'child' => 'label', 'action' => ['event' => ['name' => '"><script>']]],
      [
        'id' => 'fn',
        'component' => 'Button',
        'child' => 'label',
        'action' => ['functionCall' => ['call' => 'openUrl', 'args' => ['url' => 'https://example.com']]],
      ],
      ['id' => 'label', 'component' => 'Text', 'text' => 'Go'],
    ]));
    $this->assertStringContainsString('data-a2ui-action="submit_form"', $html);
    $this->assertSame(1, substr_count($html, 'data-a2ui-action'));
    $this->assertSame(2, substr_count($html, 'disabled="disabled"'));
    $this->assertStringNotContainsString('<script', $html);
    // A button never carries a link.
    $this->assertStringNotContainsString('href=', $html);
    $this->assertStringNotContainsString('openUrl', $html);
  }

  /**
   * Tests that what is not in the allowlist is skipped, and said so.
   */
  public function testUnknownAndUnsupportedComponentsAreSkipped(): void {
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'Column', 'children' => ['s', 'v', 'x', 't']],
      ['id' => 's', 'component' => 'Slider', 'value' => 1, 'max' => 3],
      ['id' => 'v', 'component' => 'Video', 'url' => 'https://example.com/v.mp4'],
      ['id' => 'x', 'component' => 'Evil<script>', 'html' => '<script>alert(1)</script>'],
      ['id' => 't', 'component' => 'Text', 'text' => 'still here'],
    ]), FALSE, $renderer);
    $this->assertStringContainsString('still here', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<video', $html);
    $this->assertSame(['Slider', 'Video', 'Evilscript'], $renderer->unsupported());
  }

  /**
   * A component, short.
   *
   * @param string $id
   *   Its id.
   * @param string $type
   *   Its type.
   * @param array<string, mixed> $props
   *   Its properties.
   *
   * @return array<string, mixed>
   *   The component.
   */
  private static function c(string $id, string $type, array $props = []): array {
    return ['id' => $id, 'component' => $type] + $props;
  }

  /**
   * A function call, short.
   *
   * @param string $name
   *   The function.
   * @param array<string, mixed> $args
   *   Its arguments.
   *
   * @return array<string, mixed>
   *   The call.
   */
  private static function fnCall(string $name, array $args = []): array {
    return ['@call' => $name, 'args' => $args];
  }

  /**
   * Tests that functions that act (open, call out) are never run.
   */
  public function testFunctionsThatActAreNotRun(): void {
    $html = $this->html($this->payload([
      self::c('root', 'Text', ['text' => self::fnCall('openUrl', ['url' => 'https://example.com'])]),
    ]), FALSE, $renderer);
    $this->assertSame(['call:openUrl'], $renderer->unsupported());
    $this->assertStringNotContainsString('example.com', $html);
  }

  /**
   * Tests formatString, formatCurrency, pluralize and a template.
   */
  public function testTemplatesAndFunctions(): void {
    $data = [
      'verdict' => 'Hire',
      'budget' => 1234.5,
      'reasons' => [
        ['text' => 'Rated 4.8 by paying wallets'],
        ['text' => '<b>Answers in time</b>'],
      ],
    ];
    $price = ['value' => ['@path' => '/budget'], 'currency' => 'USDC'];
    $count = ['value' => 2, 'one' => 'one reason', 'other' => 'many reasons'];
    $html = $this->html($this->payload([
      self::c('root', 'Column', ['children' => ['title', 'price', 'count', 'list']]),
      self::c('title', 'Text', ['text' => self::fnCall('formatString', ['value' => 'Verdict: ${/verdict} \\${kept}'])]),
      self::c('price', 'Text', ['text' => self::fnCall('formatCurrency', $price)]),
      self::c('count', 'Text', ['text' => self::fnCall('pluralize', $count)]),
      self::c('list', 'List', ['children' => ['componentId' => 'item', 'path' => '/reasons']]),
      self::c('item', 'Text', ['text' => self::fnCall('formatString', ['value' => 'Why: ${text}'])]),
    ], $data));
    $this->assertStringContainsString('Verdict: Hire ${kept}', $html);
    $this->assertStringContainsString('1,234.50 USDC', $html);
    $this->assertStringContainsString('many reasons', $html);
    $this->assertStringContainsString('a2ui-list', $html);
    $this->assertStringContainsString('Why: Rated 4.8 by paying wallets', $html);
    $this->assertStringContainsString('Why: &lt;b&gt;Answers in time&lt;/b&gt;', $html);
  }

  /**
   * Tests that each template item reads its own fields, escaped, with @index.
   */
  public function testTemplateItemsReadTheirOwnFields(): void {
    $html = $this->html($this->payload([
      self::c('root', 'List', ['children' => ['componentId' => 'row', 'path' => '/reasons']]),
      self::c('row', 'Row', ['children' => ['n', 't']]),
      self::c('n', 'Text', ['text' => self::fnCall('@index', ['offset' => 1])]),
      self::c('t', 'Text', ['text' => ['@path' => 'text']]),
    ], ['reasons' => [['text' => 'first'], ['text' => '<script>alert(1)</script>']]]));
    $this->assertStringContainsString('>1<', $html);
    $this->assertStringContainsString('>2<', $html);
    $this->assertStringContainsString('first', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
    $this->assertStringNotContainsString('<script', $html);
  }

  /**
   * Tests that a template over a huge list stops at its limit.
   */
  public function testTemplateItemsAreCapped(): void {
    $items = array_fill(0, 500, ['text' => 'x']);
    $html = $this->html($this->payload([
      self::c('root', 'List', ['children' => ['componentId' => 't', 'path' => '/items']]),
      self::c('t', 'Text', ['text' => ['@path' => 'text']]),
    ], ['items' => $items]));
    $this->assertSame(100, substr_count($html, 'a2ui-text'));
  }

  /**
   * Tests that a relative path outside a template reads as missing.
   */
  public function testRelativePathOutsideTemplateIsEmpty(): void {
    $html = $this->html($this->payload([
      self::c('root', 'Text', ['text' => ['@path' => 'secret']]),
    ], ['secret' => 'nope']));
    // The text is empty; the model still travels in its attribute.
    $this->assertStringContainsString('<p class="a2ui-text"></p>', $html);
  }

  /**
   * Tests that a cycle between components stops.
   */
  public function testCyclesStop(): void {
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'Column', 'children' => ['a']],
      ['id' => 'a', 'component' => 'Card', 'child' => 'root'],
    ]), FALSE, $renderer);
    $this->assertStringContainsString('a2ui-card', $html);
    $this->assertSame(['cycle:root'], $renderer->unsupported());
  }

  /**
   * Tests that a shared child repeated into a bomb is refused, not drawn.
   */
  public function testExpansionBombIsRefused(): void {
    $components = [];
    for ($i = 0; $i < 20; $i++) {
      $next = 'n' . ($i + 1);
      $components[] = ['id' => $i === 0 ? 'root' : 'n' . $i, 'component' => 'Column', 'children' => [$next, $next]];
    }
    $components[] = ['id' => 'n20', 'component' => 'Text', 'text' => 'leaf'];
    $this->expectException(A2uiException::class);
    $this->html($this->payload($components));
  }

  /**
   * Tests that buttons can be left out, and the data model starts the page.
   */
  public function testButtonsCanBeLeftOutAndTheModelTravels(): void {
    $payload = $this->payload([
      ['id' => 'root', 'component' => 'Column', 'children' => ['f', 'b']],
      ['id' => 'f', 'component' => 'TextField', 'label' => 'Name', 'value' => ['@path' => '/name']],
      ['id' => 'b', 'component' => 'Button', 'child' => 'l', 'action' => ['event' => ['name' => 'go']]],
      ['id' => 'l', 'component' => 'Text', 'text' => 'Go'],
    ], ['name' => 'Ann']);
    $with = (string) $this->container->get('renderer')->renderInIsolation((new Renderer())->build(Surface::fromJson($payload)));
    $without = (string) $this->container->get('renderer')->renderInIsolation((new Renderer(FALSE, FALSE))->build(Surface::fromJson($payload)));
    $this->assertStringContainsString('<button', $with);
    $this->assertStringNotContainsString('<button', $without);
    $this->assertStringContainsString('data-a2ui-path="/name"', $without);
    $this->assertStringContainsString('data-a2ui-model="{&quot;name&quot;:&quot;Ann&quot;}"', $without);
  }

  /**
   * Tests that a deleted surface draws nothing.
   */
  public function testDeletedSurfaceDrawsNothing(): void {
    $html = $this->html($this->payload([['id' => 'root', 'component' => 'Text', 'text' => 'gone']])
      . "\n" . '{"version":"1.0","deleteSurface":{"surfaceId":"s1"}}');
    $this->assertStringNotContainsString('gone', $html);
  }

}
