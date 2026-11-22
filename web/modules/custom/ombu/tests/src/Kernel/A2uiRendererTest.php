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
   * Tests that function calls in a binding are not run.
   */
  public function testFunctionCallsAreNotRun(): void {
    $html = $this->html($this->payload([
      ['id' => 'root', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => 'x']]],
    ]), FALSE, $renderer);
    $this->assertSame(['call'], $renderer->unsupported());
    $this->assertStringNotContainsString('formatString', $html);
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
