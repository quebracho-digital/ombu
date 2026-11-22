<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

/**
 * One A2UI surface: the components and data model its messages build up.
 *
 * Reads the agent-to-renderer messages of A2UI v1.0 (a2ui.org/specification/
 * v1.0-a2ui) that describe a surface: `createSurface`, `updateComponents`,
 * `updateDataModel` and `deleteSurface`. `callRendererFunction` and
 * `agentFunctionResponse` ask the renderer to run something, so they are
 * counted and ignored, never run. The payload comes from a seller: it is
 * checked for shape and size here, and for what it may draw in the Renderer.
 */
final class Surface {

  /**
   * The only catalog this renderer knows: A2UI's basic one.
   */
  public const BASIC_CATALOG = 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json';

  /**
   * Biggest payload taken, in bytes.
   */
  public const MAX_BYTES = 262144;

  /**
   * Most components in a surface.
   */
  public const MAX_COMPONENTS = 500;

  private const KNOWN = ['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface'];

  private const IGNORED = ['callRendererFunction', 'agentFunctionResponse'];

  /**
   * The surface id, once createSurface has come.
   */
  private ?string $id = NULL;

  /**
   * The catalog the surface asked for.
   */
  private string $catalogId = self::BASIC_CATALOG;

  /**
   * The components, by id.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $components = [];

  /**
   * The data model.
   *
   * @var array<mixed>
   */
  private array $dataModel = [];

  /**
   * Whether deleteSurface came: nothing is drawn then.
   */
  private bool $deleted = FALSE;

  /**
   * Messages ignored on purpose, by key, for logs.
   *
   * @var string[]
   */
  private array $ignored = [];

  /**
   * Reads a payload: a JSON array of messages, one message, or JSON Lines.
   *
   * @throws \Drupal\ombu\A2ui\A2uiException
   *   If it is too big, not JSON, or not a valid sequence of messages.
   */
  public static function fromJson(string $payload): self {
    if (strlen($payload) > self::MAX_BYTES) {
      throw new A2uiException('The A2UI payload is too big.');
    }
    $messages = self::decode($payload);
    $surface = new self();
    foreach ($messages as $message) {
      if (!is_array($message)) {
        throw new A2uiException('An A2UI message must be a JSON object.');
      }
      $surface->apply($message);
    }
    if ($surface->id === NULL) {
      throw new A2uiException('An A2UI payload must start with createSurface.');
    }
    return $surface;
  }

  /**
   * Splits a payload into messages.
   *
   * @return array<int, mixed>
   *   The decoded messages.
   */
  private static function decode(string $payload): array {
    $payload = trim($payload);
    $whole = json_decode($payload, TRUE);
    if (is_array($whole)) {
      return array_is_list($whole) ? $whole : [$whole];
    }
    // JSON Lines: one message per line.
    $messages = [];
    foreach (preg_split('/\R/', $payload) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }
      $message = json_decode($line, TRUE);
      if (!is_array($message)) {
        throw new A2uiException('The A2UI payload is not JSON.');
      }
      $messages[] = $message;
    }
    if ($messages === []) {
      throw new A2uiException('The A2UI payload is empty.');
    }
    return $messages;
  }

  /**
   * Applies one agent-to-renderer message.
   *
   * @param array<string, mixed> $message
   *   The decoded message.
   *
   * @throws \Drupal\ombu\A2ui\A2uiException
   *   If it is malformed or breaks the protocol's rules.
   */
  public function apply(array $message): void {
    if (($message['version'] ?? NULL) !== '1.0') {
      throw new A2uiException('Only A2UI version 1.0 is supported.');
    }
    $keys = array_values(array_intersect(array_keys($message), [...self::KNOWN, ...self::IGNORED]));
    if (count($keys) !== 1) {
      throw new A2uiException('An A2UI message carries exactly one message key.');
    }
    $key = $keys[0];
    if (in_array($key, self::IGNORED, TRUE)) {
      $this->ignored[] = $key;
      return;
    }
    $body = $message[$key];
    if (!is_array($body)) {
      throw new A2uiException(sprintf('"%s" must be an object.', $key));
    }
    if ($key === 'createSurface') {
      $this->create($body);
      return;
    }
    // Everything else needs the surface it names.
    if ($this->id === NULL || ($body['surfaceId'] ?? NULL) !== $this->id) {
      throw new A2uiException(sprintf('"%s" names a surface that was not created.', $key));
    }
    match ($key) {
      'updateComponents' => $this->updateComponents($body),
      'updateDataModel' => $this->updateDataModel($body),
      'deleteSurface' => $this->deleted = TRUE,
    };
  }

  /**
   * Handles createSurface.
   *
   * @param array<string, mixed> $body
   *   The message body.
   */
  private function create(array $body): void {
    if ($this->id !== NULL) {
      // One surface per payload, and an id is never reused.
      throw new A2uiException('A payload creates one surface.');
    }
    $id = $body['surfaceId'] ?? NULL;
    if (!is_string($id) || $id === '' || strlen($id) > 128) {
      throw new A2uiException('createSurface needs a surfaceId.');
    }
    $catalog = $body['catalogId'] ?? self::BASIC_CATALOG;
    if ($catalog !== self::BASIC_CATALOG) {
      throw new A2uiException('Only the A2UI basic catalog is supported.');
    }
    $this->id = $id;
    $this->catalogId = $catalog;
    if (isset($body['components'])) {
      $this->updateComponents(['components' => $body['components']]);
    }
    if (isset($body['dataModel'])) {
      if (!is_array($body['dataModel'])) {
        throw new A2uiException('dataModel must be an object.');
      }
      $this->dataModel = $body['dataModel'];
    }
  }

  /**
   * Handles updateComponents: components are replaced by id.
   *
   * @param array<string, mixed> $body
   *   The message body.
   */
  private function updateComponents(array $body): void {
    $list = $body['components'] ?? NULL;
    if (!is_array($list) || $list === [] || !array_is_list($list)) {
      throw new A2uiException('components must be a non-empty list.');
    }
    foreach ($list as $component) {
      $id = is_array($component) ? ($component['id'] ?? NULL) : NULL;
      if (!is_string($id) || !preg_match('/^[\p{L}_][\p{L}\p{N}_-]{0,127}$/u', $id)) {
        throw new A2uiException('A component needs a valid id.');
      }
      if (!is_string($component['component'] ?? NULL)) {
        throw new A2uiException(sprintf('Component "%s" needs a component type.', $id));
      }
      $this->components[$id] = $component;
      if (count($this->components) > self::MAX_COMPONENTS) {
        throw new A2uiException('Too many components.');
      }
    }
  }

  /**
   * Handles updateDataModel.
   *
   * @param array<string, mixed> $body
   *   The message body.
   */
  private function updateDataModel(array $body): void {
    if (!array_key_exists('value', $body)) {
      throw new A2uiException('updateDataModel needs a value.');
    }
    $path = $body['path'] ?? '/';
    if (!is_string($path)) {
      throw new A2uiException('updateDataModel path must be a string.');
    }
    Pointer::set($this->dataModel, $path, $body['value']);
  }

  /**
   * The surface id.
   */
  public function id(): string {
    return (string) $this->id;
  }

  /**
   * The catalog id.
   */
  public function catalogId(): string {
    return $this->catalogId;
  }

  /**
   * Whether deleteSurface came.
   */
  public function isDeleted(): bool {
    return $this->deleted;
  }

  /**
   * A component by id.
   *
   * @return array<string, mixed>|null
   *   The component, or NULL if the surface has none with that id.
   */
  public function component(string $id): ?array {
    return $this->components[$id] ?? NULL;
  }

  /**
   * The data model.
   *
   * @return array<mixed>
   *   The model.
   */
  public function dataModel(): array {
    return $this->dataModel;
  }

  /**
   * The messages that were read and ignored on purpose.
   *
   * @return string[]
   *   Their keys.
   */
  public function ignored(): array {
    return $this->ignored;
  }

}
