<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ombu\Markdown;
use Drupal\x402_pay\PaymentHeaders;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Agents that read a page as markdown keep reading the site as markdown.
 *
 * Every markdown page opens saying how (a visible line, the same for
 * anyone), and its links to this site's pages go to their .md when the
 * page reads free as markdown (Markdown::url()). Whether a link may be
 * followed is the router's to say, access checks included, for whoever
 * reads: a page they may not see keeps its link as it was.
 */
final class MarkdownNavigationSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly RequestMatcherInterface $router,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After ListingMarkdownSubscriber adds the similar services (0).
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * The line on top, and the links to .md.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    // A plain response only (not a file), and not what was paid for: the
    // buyer gets the bytes they bought. llms.txt opens with its title.
    if (!$event->isMainRequest() || $response->getStatusCode() !== 200 || get_class($response) !== Response::class
      || $response->headers->has(PaymentHeaders::RESPONSE) || $request->headers->has(PaymentHeaders::SIGNATURE)
      || in_array($request->attributes->get('_route'), ['llms_txt.llms_txt', 'x402_pay.entity'], TRUE)
      || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/markdown')) {
      return;
    }
    $site = $request->getSchemeAndHttpHost();
    $content = (string) $response->getContent();
    $seen = [];
    // Inline links only, "](url": seller text cannot hold "]" (linkText()).
    // ponytail: the first 200 distinct links, each matched once; a page
    // with more keeps the rest as they were.
    $content = preg_replace_callback('/\]\(([^)\s]+)/', function ($m) use ($site, &$seen) {
      if (!isset($seen[$m[1]])) {
        $seen[$m[1]] = count($seen) < 200 ? $this->markdownUrl($m[1], $site) : $m[1];
      }
      return '](' . $seen[$m[1]];
    }, $content) ?? $content;
    $response->setContent('> ' . $this->t('Every page on this site also reads as markdown: add .md to its path, before any ?query. Links here to pages that read free already do.') . "\n\n" . $content);
  }

  /**
   * A link to this site's page as markdown, or the link as it was.
   */
  private function markdownUrl(string $url, string $site): string {
    $local = str_starts_with($url, "$site/") ? substr($url, strlen($site)) : $url;
    if (!preg_match('#^(/(?!/)[^?\#]*)(\?[^\#]*)?(.*)$#s', $local, $m) || str_ends_with($m[1], '.md')) {
      return $url;
    }
    $sub = Request::create($m[1]);
    // Whatever goes wrong leaves the link as it was, never the page broken.
    try {
      // The access-aware router: denied, not found, or not a page, it throws.
      $sub->attributes->add($this->router->matchRequest($sub));
      $route_match = RouteMatch::createFromRequest($sub);
      if (!Markdown::url($route_match, new CacheableMetadata())) {
        return $url;
      }
    }
    catch (\Throwable) {
      return $url;
    }
    // The front page is the market: "/" or "/es" reads as /market.md, in
    // the link's own language.
    if ($route_match->getRouteName() === 'view.market.page_1') {
      $path = rtrim($m[1], '/');
      $path = str_ends_with($path, '/market') ? $path : "$path/market";
    }
    elseif (str_ends_with($m[1], '/')) {
      return $url;
    }
    else {
      $path = $m[1];
    }
    return substr($url, 0, strlen($url) - strlen($local)) . $path . '.md' . ($m[2] ?? '') . $m[3];
  }

}
