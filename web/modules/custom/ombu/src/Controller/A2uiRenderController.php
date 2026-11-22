<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\ombu\A2ui\A2uiException;
use Drupal\ombu\A2ui\ListingSurface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ombu/a2ui/render: an A2UI surface in, its HTML out.
 *
 * The listing page asks it to draw the card a paid answer carries (`a2ui`),
 * since the answer reaches the browser as JSON and the drawing is done here,
 * with the same allowlist as a listing's form. It keeps nothing and costs no
 * payment. The HTML is inert even if it were opened on its own: no script, no
 * style, no frame, no sniffing (the headers below).
 */
final class A2uiRenderController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(private readonly RendererInterface $renderer) {}

  /**
   * Draws the posted surface (a JSON array of A2UI messages).
   */
  public function __invoke(Request $request): Response {
    $headers = [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; sandbox",
    ];
    try {
      [$build] = ListingSurface::build($request->getContent());
    }
    catch (A2uiException $e) {
      return new Response('Cannot draw this: ' . $e->getMessage(), 400, ['Content-Type' => 'text/plain; charset=UTF-8'] + $headers);
    }
    return new Response((string) $this->renderer->renderInIsolation($build), 200, $headers);
  }

}
