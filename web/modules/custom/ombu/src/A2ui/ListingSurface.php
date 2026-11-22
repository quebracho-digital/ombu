<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

/**
 * The A2UI a listing carries, drawn the way a listing page draws it.
 *
 * One place for the policy, so the field's validation and the page agree on
 * what "valid" means: a payload is valid if it can be drawn.
 */
final class ListingSurface {

  /**
   * Draws a listing's A2UI as a render array.
   *
   * Images stay off and the seller's own buttons are not drawn: the page has
   * one action, Ombú's pay button, and the data model is what it sends.
   *
   * @return array{0: array<string, mixed>, 1: string[]}
   *   The render array and what the renderer skipped.
   *
   * @throws \Drupal\ombu\A2ui\A2uiException
   *   If the payload cannot be read or is too big to draw.
   */
  public static function build(string $json): array {
    $renderer = new Renderer(FALSE, FALSE);
    $build = $renderer->build(Surface::fromJson($json));
    return [$build, $renderer->unsupported()];
  }

}
