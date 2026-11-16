<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\ombu\Stop;
use Drupal\x402_pay\Stop as PaymentsStop;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * While the site is stopped (Stop): read-only, no wallets, no 402.
 *
 * On the way in, every request that would change something (any method but
 * GET, HEAD and OPTIONS) and every request that carries a payment is
 * answered 503 with Retry-After. Kept open: staff with the permission, the
 * password sign-in and its reset (to come and resume) and MCP, whose transport is POST
 * and whose paid tool McpPayments refuses.
 *
 * On the way out, one filter for every page and every format (HTML, JSON,
 * markdown, MCP results): what looks like a wallet, an EVM address or a
 * Solana one, is replaced by "[hidden]", so nobody is told where to pay; and
 * a 402 that still got through becomes the same 503. Staff see the site as it
 * is. A transaction hash is not a wallet and stays.
 */
final class StopSubscriber implements EventSubscriberInterface {

  public const PERMISSION = 'stop and resume ombu';

  public const HIDDEN = '[hidden]';

  private const RETRY = '300';

  /**
   * Routes that stay writable: sign in and out, and the MCP transport.
   */
  private const OPEN = [
    'user.login',
    'user.login.http',
    'user.pass',
    'user.reset.login',
    'user.logout',
    'user.logout.confirm',
    'user.logout.http',
    'mcp_server.handle',
    'ombu.mcp_message',
  ];

  /**
   * An EVM address: not the first 20 bytes of a longer hex (a transaction).
   */
  private const EVM = '/(?<![0-9a-zA-Z])0x[0-9a-fA-F]{40}(?![0-9a-fA-F])/';

  /**
   * A Solana address: 32 to 44 base58 characters standing alone.
   *
   * With a digit, a capital and a small letter, and not glued to "-" or "_":
   * that keeps long words and hashed file names (css_….css) out. Nor the
   * value of a form field or of a token in a link: a token can look like
   * one, and a link or a form with a changed token no longer works.
   */
  private const SOLANA = '/(?<![0-9A-Za-z_\-\/])(?<!token=)(?<!value=")(?=[1-9A-HJ-NP-Za-km-z]*[1-9])(?=[1-9A-HJ-NP-Za-km-z]*[A-HJ-NP-Z])(?=[1-9A-HJ-NP-Za-km-z]*[a-km-z])[1-9A-HJ-NP-Za-km-z]{32,44}(?![0-9A-Za-z_\-])/';

  public function __construct(
    private readonly Stop $stop,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After the router (32) and authentication (300): the route and the
      // user are known; before anything is run.
      KernelEvents::REQUEST => ['onRequest', 30],
      // Last: after everything that writes the response.
      KernelEvents::RESPONSE => ['onResponse', -1000],
    ];
  }

  /**
   * Refuses writes and payments while stopped.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->stop->engaged()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->headers->has('PAYMENT-SIGNATURE') || $request->headers->has('X-PAYMENT')) {
      $event->setResponse($this->paused());
      return;
    }
    if ($this->staff()) {
      return;
    }
    // BigPipe sends the page in pieces this class never sees whole: while
    // stopped, visitors get it in one.
    $route = $request->attributes->get('_route_object');
    if ($route instanceof Route) {
      $route->setOption('_no_big_pipe', TRUE);
    }
    if ($request->isMethodSafe() || in_array($request->attributes->get('_route'), self::OPEN, TRUE)) {
      return;
    }
    $json = str_contains((string) $request->headers->get('Accept'), 'json') || $request->getContentTypeFormat() === 'json';
    $event->setResponse($json
      ? new JsonResponse(['error' => 'read_only'], 503, ['Retry-After' => self::RETRY])
      : new Response("Ombú is paused and read-only for now: nothing can be changed. Try again later.\n", 503, [
        'Retry-After' => self::RETRY,
        'Content-Type' => 'text/plain; charset=UTF-8',
      ]));
  }

  /**
   * Hides wallets and turns a 402 into a temporary error while stopped.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->stop->engaged() || $this->staff()) {
      return;
    }
    $response = $event->getResponse();
    // The answer to a sale that was under way when the site stopped carries
    // its receipt: the buyer paid, or must know it did not. Left as it is.
    if ($response->headers->has('PAYMENT-RESPONSE') || $response->headers->has('X-PAYMENT-RESPONSE')) {
      return;
    }
    if ($response->getStatusCode() === 402) {
      $event->setResponse($this->paused());
      return;
    }
    // Neither a proxy nor a browser keeps what is only true while stopped.
    $response->headers->set('Cache-Control', 'private, no-store');
    // What announces a price in a header has no place either.
    $response->headers->remove('PAYMENT-REQUIRED');
    $type = (string) $response->headers->get('Content-Type');
    // The session's token is a token, whatever it looks like.
    if (($type !== '' && !preg_match('#^text/|json|xml|markdown#i', $type)) || $event->getRequest()->attributes->get('_route') === 'system.csrftoken') {
      return;
    }
    // Sent in pieces (an MCP answer as events): each piece is filtered as it
    // leaves.
    if ($response instanceof StreamedResponse) {
      if ($send = $response->getCallback()) {
        $response->setCallback(static function () use ($send): void {
          ob_start(static fn(string $piece): string => self::hide($piece));
          try {
            $send();
          }
          finally {
            ob_end_flush();
          }
        });
      }
      return;
    }
    $content = $response->getContent();
    if (!is_string($content) || $content === '') {
      return;
    }
    // The same for a sale over MCP: its receipt travels in the body.
    if (str_contains($content, 'x402/payment-response')) {
      return;
    }
    $hidden = self::hide($content);
    if ($hidden !== $content) {
      $response->setContent($hidden);
      $response->headers->remove('Content-Length');
    }
  }

  /**
   * The text with every wallet replaced.
   */
  public static function hide(string $text): string {
    return preg_replace([self::EVM, self::SOLANA], self::HIDDEN, $text) ?? $text;
  }

  /**
   * The answer to a payment, or to what would have asked for one.
   */
  private function paused(): Response {
    $response = new JsonResponse(['error' => PaymentsStop::REASON], 503, ['Retry-After' => self::RETRY]);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Whether the visitor may stop and resume: they keep working.
   */
  private function staff(): bool {
    return $this->currentUser->hasPermission(self::PERMISSION);
  }

}
