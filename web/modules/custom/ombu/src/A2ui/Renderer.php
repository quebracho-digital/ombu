<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

use Drupal\Component\Utility\Html;

/**
 * Draws an A2UI surface as a Drupal render array, with plain elements.
 *
 * The seller writes the surface, so this is the filter: only the components
 * below are drawn (a subset of A2UI's basic catalog), whatever else the
 * payload says is skipped and listed in unsupported(). Every string from the
 * payload reaches the page as text (#plain_text or an attribute, both
 * escaped), never as markup; classes come from allowlists; URLs go through
 * their own check. Nothing here runs a seller's code or submits anything: a
 * button only says which action it stands for. What an action does (above
 * all, paying) is Ombú's business, never the payload's.
 */
final class Renderer {

  private const JUSTIFY = ['start', 'center', 'end', 'spaceBetween', 'spaceAround', 'spaceEvenly', 'stretch'];

  private const ALIGN = ['start', 'center', 'end', 'stretch'];

  private const BUTTON = ['default', 'primary', 'borderless'];

  /**
   * Most nodes drawn: a shared child referenced again and again is a bomb.
   */
  private const MAX_NODES = 1000;

  private const MAX_DEPTH = 24;

  /**
   * Component types that were skipped, with why.
   *
   * @var string[]
   */
  private array $unsupported = [];

  /**
   * Nodes drawn so far in this build.
   */
  private int $nodes = 0;

  /**
   * The surface being drawn.
   */
  private Surface $surface;

  /**
   * Creates a renderer.
   *
   * @param bool $allowRemoteImages
   *   Whether an Image component loads its URL. Off by default: a remote
   *   image tells its host who is looking, and a seller could use that.
   * @param bool $drawButtons
   *   Whether Button components are drawn. A page that has its own action
   *   (Ombú's pay button) turns them off, so the seller's do not compete.
   */
  public function __construct(
    private readonly bool $allowRemoteImages = FALSE,
    private readonly bool $drawButtons = TRUE,
  ) {
  }

  /**
   * Builds the render array of a surface.
   *
   * @return array<string, mixed>
   *   A render array.
   *
   * @throws \Drupal\ombu\A2ui\A2uiException
   *   If the surface is too big to draw.
   */
  public function build(Surface $surface): array {
    $this->surface = $surface;
    $this->unsupported = [];
    $this->nodes = 0;
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      // The data model as it starts: a page's script overwrites the bound
      // paths with what the person typed and sends the whole.
      '#attributes' => [
        'class' => ['a2ui'],
        'data-a2ui-surface' => $surface->id(),
        'data-a2ui-model' => json_encode($surface->dataModel() ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ],
      '#attached' => ['library' => ['ombu/a2ui']],
    ];
    if (!$surface->isDeleted()) {
      $build['root'] = $this->node('root', []);
    }
    return $build;
  }

  /**
   * What was skipped while drawing, for logs.
   *
   * @return string[]
   *   Reasons such as "Slider" or "cycle:a".
   */
  public function unsupported(): array {
    return array_values(array_unique($this->unsupported));
  }

  /**
   * Draws one component by id.
   *
   * @param string $id
   *   The component id.
   * @param string[] $trail
   *   The ids above it, to cut cycles.
   *
   * @return array<string, mixed>
   *   A render array; empty when there is nothing to draw.
   */
  private function node(string $id, array $trail): array {
    if (in_array($id, $trail, TRUE)) {
      $this->unsupported[] = 'cycle:' . $id;
      return [];
    }
    if (count($trail) >= self::MAX_DEPTH) {
      $this->unsupported[] = 'depth:' . $id;
      return [];
    }
    if (++$this->nodes > self::MAX_NODES) {
      throw new A2uiException('The surface is too big to draw.');
    }
    $component = $this->surface->component($id);
    if ($component === NULL) {
      // A child that has not arrived: nothing yet (progressive rendering).
      return [];
    }
    $trail[] = $id;
    return match ($component['component']) {
      'Text' => $this->text($component),
      'Row' => $this->flex($component, 'row', $trail),
      'Column' => $this->flex($component, 'column', $trail),
      'Card' => $this->tag('div', ['a2ui-card'], ['child' => $this->child($component['child'] ?? NULL, $trail)]),
      'Divider' => $this->tag('hr', ['a2ui-divider']),
      'Image' => $this->image($component),
      'Icon' => $this->icon($component),
      'TextField' => $this->textField($component, $id),
      'CheckBox' => $this->checkBox($component, $id),
      'ChoicePicker' => $this->choicePicker($component, $id),
      'Button' => $this->drawButtons ? $this->button($component, $trail) : [],
      default => $this->skip((string) $component['component']),
    };
  }

  /**
   * Notes a component this renderer does not draw.
   *
   * @return array<string, mixed>
   *   Nothing to draw.
   */
  private function skip(string $type): array {
    $this->unsupported[] = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', $type) ?? '', 0, 40);
    return [];
  }

  /**
   * One child by id, or nothing.
   *
   * @param mixed $id
   *   The id the component names, if it is a string.
   * @param string[] $trail
   *   The ids above it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function child(mixed $id, array $trail): array {
    return is_string($id) ? $this->node($id, $trail) : [];
  }

  /**
   * A tag with classes and children (never a value: seller text is a child).
   *
   * @param string $tag
   *   The tag, one of this class's own.
   * @param string[] $classes
   *   Classes from this class's own lists.
   * @param array<string, mixed> $children
   *   Child render arrays.
   * @param array<string, mixed> $attributes
   *   More attributes, their values escaped by Drupal.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function tag(string $tag, array $classes, array $children = [], array $attributes = []): array {
    return [
      '#type' => 'html_tag',
      '#tag' => $tag,
      '#attributes' => ['class' => $classes] + $attributes,
    ] + $children;
  }

  /**
   * Resolves a dynamic value: a literal or an `{"@path": …}` binding.
   *
   * Function calls (`@call`) are not run: they read as missing.
   */
  private function value(mixed $raw): mixed {
    if (is_array($raw) && isset($raw['@path']) && is_string($raw['@path'])) {
      return Pointer::get($this->surface->dataModel(), $raw['@path']);
    }
    if (is_array($raw)) {
      if (isset($raw['@call'])) {
        $this->unsupported[] = 'call';
      }
      return NULL;
    }
    return $raw;
  }

  /**
   * A dynamic value as text; anything that is not a scalar is empty.
   */
  private function string(mixed $raw): string {
    $value = $this->value($raw);
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return is_scalar($value) ? mb_substr((string) $value, 0, 5000) : '';
  }

  /**
   * The path an input is bound to, for the page's script to find it.
   *
   * @return array<string, string>
   *   The attribute, or none.
   */
  private function binding(mixed $raw): array {
    if (is_array($raw) && isset($raw['@path']) && is_string($raw['@path']) && strlen($raw['@path']) <= 256) {
      return ['data-a2ui-path' => $raw['@path']];
    }
    return [];
  }

  /**
   * A Text component: caption or body, with a leading `#` as a heading.
   *
   * @param array<string, mixed> $component
   *   The component.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function text(array $component): array {
    $text = $this->string($component['text'] ?? NULL);
    $classes = ['a2ui-text'];
    $tag = 'p';
    if (($component['variant'] ?? NULL) === 'caption') {
      $classes[] = 'a2ui-text--caption';
      $tag = 'small';
    }
    // The one piece of markdown kept: a heading, as text.
    elseif (preg_match('/^(#{1,3})\s+(.+)$/su', $text, $match)) {
      $tag = 'h' . (strlen($match[1]) + 1);
      $text = $match[2];
    }
    return $this->tag($tag, $classes, ['text' => ['#plain_text' => $text]]);
  }

  /**
   * A Row or a Column.
   *
   * @param array<string, mixed> $component
   *   The component.
   * @param string $direction
   *   Row or column, in lower case.
   * @param string[] $trail
   *   The ids above it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function flex(array $component, string $direction, array $trail): array {
    $justify = in_array($component['justify'] ?? NULL, self::JUSTIFY, TRUE) ? $component['justify'] : 'start';
    $align = in_array($component['align'] ?? NULL, self::ALIGN, TRUE) ? $component['align'] : 'stretch';
    $children = [];
    $list = $component['children'] ?? [];
    if (is_array($list) && array_is_list($list)) {
      foreach ($list as $child) {
        $children[] = $this->child($child, $trail);
      }
    }
    elseif (is_array($list)) {
      // A template over a list in the data model: not in this first cut.
      $this->unsupported[] = 'template';
    }
    return $this->tag('div', ['a2ui-' . $direction, 'a2ui-justify-' . $justify, 'a2ui-align-' . $align], $children);
  }

  /**
   * An Image: only when remote images are allowed, and only http(s).
   *
   * @param array<string, mixed> $component
   *   The component.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function image(array $component): array {
    $description = $this->string($component['description'] ?? NULL);
    $url = $this->string($component['url'] ?? NULL);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!$this->allowRemoteImages || !is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], TRUE) || preg_match('/[\x00-\x20"<>\\\\]/', $url)) {
      // The description stands in for the picture.
      return $description === '' ? [] : $this->tag('span', ['a2ui-image-alt'], ['text' => ['#plain_text' => $description]]);
    }
    return $this->tag('img', ['a2ui-image'], [], [
      'src' => $url,
      'alt' => $description,
      'loading' => 'lazy',
      'referrerpolicy' => 'no-referrer',
    ]);
  }

  /**
   * An Icon: a named hook for the theme, never a path or an SVG.
   *
   * @param array<string, mixed> $component
   *   The component.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function icon(array $component): array {
    $name = $component['name'] ?? NULL;
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) {
      return [];
    }
    return $this->tag('span', ['a2ui-icon'], [], ['data-a2ui-icon' => $name, 'aria-hidden' => 'true']);
  }

  /**
   * A TextField: an inert input, labelled, with the bound value.
   *
   * @param array<string, mixed> $component
   *   The component.
   * @param string $id
   *   The component id.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function textField(array $component, string $id): array {
    $dom_id = $this->domId($id);
    $variant = $component['variant'] ?? 'shortText';
    $value = $this->string($component['value'] ?? NULL);
    $binding = $this->binding($component['value'] ?? NULL);
    $label = $this->tag('label', [], ['text' => ['#plain_text' => $this->string($component['label'] ?? NULL)]], ['for' => $dom_id]);
    if ($variant === 'longText') {
      $text = ['text' => ['#plain_text' => $value]];
      $field = $this->tag('textarea', ['form-control'], $text, ['id' => $dom_id, 'rows' => 4] + $binding);
    }
    else {
      $type = match ($variant) {
        'number' => 'number',
        'obscured' => 'password',
        default => 'text',
      };
      $field = $this->tag('input', ['form-control'], [], ['id' => $dom_id, 'type' => $type, 'value' => $value] + $binding);
    }
    return $this->tag('div', ['a2ui-field'], ['label' => $label, 'field' => $field]);
  }

  /**
   * A CheckBox.
   *
   * @param array<string, mixed> $component
   *   The component.
   * @param string $id
   *   The component id.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function checkBox(array $component, string $id): array {
    $dom_id = $this->domId($id);
    $attributes = ['id' => $dom_id, 'type' => 'checkbox'] + $this->binding($component['value'] ?? NULL);
    if ($this->value($component['value'] ?? NULL) === TRUE) {
      $attributes['checked'] = 'checked';
    }
    return $this->tag('div', ['a2ui-field', 'a2ui-checkbox'], [
      'field' => $this->tag('input', [], [], $attributes),
      'label' => $this->tag('label', [], ['text' => ['#plain_text' => $this->string($component['label'] ?? NULL)]], ['for' => $dom_id]),
    ]);
  }

  /**
   * A ChoicePicker: radios, or checkboxes when several can be chosen.
   *
   * @param array<string, mixed> $component
   *   The component.
   * @param string $id
   *   The component id.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function choicePicker(array $component, string $id): array {
    $multiple = ($component['variant'] ?? NULL) === 'multipleSelection';
    $selected = $this->value($component['value'] ?? NULL);
    $selected = is_array($selected) ? array_map('strval', array_filter($selected, 'is_scalar')) : [];
    $options = [];
    $list = is_array($component['options'] ?? NULL) ? array_slice($component['options'], 0, 50) : [];
    foreach ($list as $index => $option) {
      if (!is_array($option) || !is_string($option['value'] ?? NULL)) {
        continue;
      }
      $dom_id = $this->domId($id . '-' . $index);
      $attributes = [
        'id' => $dom_id,
        'type' => $multiple ? 'checkbox' : 'radio',
        'name' => $this->domId($id),
        'value' => $option['value'],
      ];
      if (in_array($option['value'], $selected, TRUE)) {
        $attributes['checked'] = 'checked';
      }
      $options[] = $this->tag('div', ['a2ui-option'], [
        'input' => $this->tag('input', [], [], $attributes),
        'label' => $this->tag('label', [], ['text' => ['#plain_text' => $this->string($option['label'] ?? NULL)]], ['for' => $dom_id]),
      ]);
    }
    return $this->tag('fieldset', ['a2ui-field', 'a2ui-choice'], [
      'legend' => $this->tag('legend', [], ['text' => ['#plain_text' => $this->string($component['label'] ?? NULL)]]),
    ] + $options, $this->binding($component['value'] ?? NULL));
  }

  /**
   * A Button: it names its action and does nothing else.
   *
   * @param array<string, mixed> $component
   *   The component.
   * @param string[] $trail
   *   The ids above it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function button(array $component, array $trail): array {
    $variant = in_array($component['variant'] ?? NULL, self::BUTTON, TRUE) ? $component['variant'] : 'default';
    $attributes = ['type' => 'button'];
    $name = $component['action']['event']['name'] ?? NULL;
    if (is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) {
      $attributes['data-a2ui-action'] = $name;
    }
    else {
      // A local function call or no action: there is nothing Ombú will do.
      $attributes['disabled'] = 'disabled';
    }
    return $this->tag('button', ['btn', 'a2ui-button', 'a2ui-button--' . $variant], ['child' => $this->child($component['child'] ?? NULL, $trail)], $attributes);
  }

  /**
   * A DOM id for a component, unique within the page.
   */
  private function domId(string $id): string {
    return Html::getId('a2ui-' . $this->surface->id() . '-' . $id);
  }

}
