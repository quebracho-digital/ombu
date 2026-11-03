<?php

declare(strict_types=1);

namespace Drupal\ombu\Mcp;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\ombu\Sellers;
use Drupal\x402_pay\ClaimStore;
use Drupal\x402_pay\Delivery;
use Drupal\x402_pay\Event\PaymentEvent;
use Drupal\x402_pay\Facilitators;
use Drupal\x402_pay\PaymentLog;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Drupal\x402_pay\Reconciler;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\PaymentOutcome;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\X402McpMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Charges for the MCP tool ombu_call_service, over x402 in band.
 *
 * The protocol is the library's X402McpMiddleware (an unpaid tools/call gets
 * the PaymentRequired, the payment rides in params._meta, the receipt comes
 * back in the result). What is ours: the price and seller of the listing the
 * call names, the site's facilitator, claims, payment log and flood limit,
 * the same as an HTTP sale through x402_pay.
 *
 * The tool itself runs only for a call this class admitted (isPaid()), so
 * a transport without this middleware (Drush's STDIO) sells nothing.
 */
final class McpPayments implements MiddlewareInterface, EventDispatcherInterface {

  public const TOOL = 'ombu_call_service';

  /**
   * Refused payments one visitor (IP) may send per window, as x402_pay's.
   */
  private const REFUSALS = 30;

  private const WINDOW = 3600;

  /**
   * The listing id of the call admitted now, if any.
   */
  private ?int $admitted = NULL;

  /**
   * The listing the call in flight names, to log its outcome.
   */
  private ?ProductInterface $listing = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Delivery $delivery,
    private readonly Sellers $sellers,
    private readonly Facilitators $facilitators,
    private readonly ClaimStore $claims,
    private readonly PaymentLog $log,
    private readonly HttpFactory $psr17,
    private readonly EventDispatcherInterface $events,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * The listing a tools/call params names, if it is for sale.
   *
   * NULL for any other tool and for a listing that is not sold here: those
   * calls are not charged (the tool refuses what is not free).
   */
  public function listing(array $params): ?ProductInterface {
    if (($params['name'] ?? NULL) !== self::TOOL || !is_int($id = $params['arguments']['id'] ?? NULL)) {
      return NULL;
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($id);
    if (!$product instanceof ProductInterface || !$product->isPublished() || !$product->access('view') || !$product->hasField('field_service')
      || !$product->get('field_service')->value || !X402PriceItem::find($product, 'full')) {
      return NULL;
    }
    return $product;
  }

  /**
   * Whether the call in flight paid for this listing (or it is free).
   */
  public function isPaid(ProductInterface $product): bool {
    if ($this->admitted === (int) $product->id()) {
      return TRUE;
    }
    $item = X402PriceItem::find($product, 'full');
    $quote = $item ? $this->delivery->quote(Request::create('/mcp', 'POST'), $product, $item) : NULL;
    return $quote && !$quote->isDenied() && $quote->isFree();
  }

  /**
   * The PaymentRequirements of a listing, as its HTTP sale announces them.
   */
  public function requirements(ProductInterface $product): ?array {
    $item = X402PriceItem::find($product, 'full');
    $quote = $this->delivery->quote(Request::create('/mcp', 'POST'), $product, $item);
    if ($quote->isDenied() || $quote->isFree()) {
      return NULL;
    }
    try {
      $requirements = $this->delivery->requirements($quote, $item);
      if (str_starts_with($requirements['network'], 'solana:') && empty($requirements['extra']['feePayer'])) {
        $requirements['extra']['feePayer'] = $this->facilitators->feePayer($requirements['network']);
      }
    }
    catch (\RuntimeException | \InvalidArgumentException | FacilitatorException) {
      // No usable seller key: listed, not payable.
      return NULL;
    }
    return $requirements;
  }

  /**
   * {@inheritdoc}
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $body = $request->getBody();
    $message = json_decode((string) $body, TRUE);
    if ($body->isSeekable()) {
      $body->rewind();
    }
    $params = is_array($message) && !array_is_list($message) && ($message['method'] ?? NULL) === 'tools/call' && is_array($message['params'] ?? NULL) ? $message['params'] : [];
    $this->admitted = $this->listing = NULL;
    $product = $this->listing($params);
    $requirements = $product ? $this->requirements($product) : NULL;
    if (!$product || !$requirements) {
      return $handler->handle($request);
    }
    $paying = isset($params['_meta'][X402McpMiddleware::PAYMENT]);
    if ($paying && !$this->flood->isAllowed('ombu.mcp_refused_payment', self::REFUSALS, self::WINDOW)) {
      return $this->psr17->createResponse(429)->withHeader('Retry-After', (string) self::WINDOW);
    }
    $this->listing = $product;
    $seller = $this->sellers->seller($product);
    $facilitator = NULL;
    $verify = function (array $payment, array $req) use ($seller, &$facilitator): ?string {
      try {
        $facilitator = $this->facilitators->for($req['network'], $seller);
        $verified = $facilitator->verify($payment, $req);
      }
      catch (FacilitatorException) {
        return 'facilitator_unavailable';
      }
      return empty($verified['isValid']) ? ($verified['invalidReason'] ?? 'invalid_payment') : NULL;
    };
    // Past the site's deadline (a proxy drops the answer at about 100 s) the
    // buyer got nothing: do not charge, as PaymentGate does over HTTP.
    $deadline = Settings::get('x402_pay_deadline');
    $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(TRUE));
    $settle = function (array $payment, array $req) use ($seller, &$facilitator, $deadline, $started): array {
      if ($deadline && microtime(TRUE) - $started > $deadline) {
        return ['success' => FALSE, 'errorReason' => 'deadline', 'transaction' => '', 'network' => $req['network']];
      }
      try {
        $receipt = ($facilitator ?? $this->facilitators->for($req['network'], $seller))->settle($payment, $req);
      }
      catch (FacilitatorException) {
        // It may have sent the transaction before going silent: the claim
        // stays (the library's rule), so the same payment cannot run twice.
        return ['success' => FALSE, 'errorReason' => 'facilitator_unavailable', 'transaction' => '', 'network' => $req['network']];
      }
      // Only a real TRUE is a charge. A real FALSE is a refusal; anything
      // else ("false", 1, [0]) is an answer that does not add up: the claim
      // stays, as the library does.
      if (($receipt['success'] ?? NULL) !== TRUE) {
        $reason = ($receipt['success'] ?? NULL) === FALSE ? (string) ($receipt['errorReason'] ?? 'settle_failed') : 'invalid_settle_response';
        return ['success' => FALSE, 'errorReason' => $reason, 'transaction' => (string) ($receipt['transaction'] ?? ''), 'network' => $req['network']];
      }
      return ['success' => TRUE, 'transaction' => $receipt['transaction'], 'network' => $receipt['network'] ?? $req['network'], 'payer' => $receipt['payer'] ?? NULL];
    };
    $id = (int) $product->id();
    $middleware = new X402McpMiddleware(
      schemes: [new EvmExact(), new SolanaExact()],
      price: fn() => [$requirements],
      claim: function (string $key) use ($id): bool {
        // The claim comes after verify: from here the call is admitted.
        $claimed = ($this->claims)($key);
        $this->admitted = $claimed ? $id : NULL;
        return $claimed;
      },
      responses: $this->psr17,
      streams: $this->psr17,
      settle: $settle,
      events: $this,
      resource: fn() => [
        'url' => $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'description' => (string) $product->label(),
        'mimeType' => 'application/json',
      ],
      verify: $verify,
      release: fn(string $key) => $this->claims->release($key),
    );
    try {
      return $middleware->process($request, $handler);
    }
    finally {
      $this->admitted = $this->listing = NULL;
    }
  }

  /**
   * The library's events: ours go to the payment log, all to the site's.
   */
  public function dispatch(object $event): object {
    if ($event instanceof PaymentOutcome) {
      $this->record($event);
    }
    return $this->events->dispatch($event);
  }

  /**
   * Logs what became of a payment, as PaymentGate does for an HTTP sale.
   */
  private function record(PaymentOutcome $outcome): void {
    // A paid call that delivered nothing costs us the seller's work: it counts
    // against the visitor like a refused payment.
    if (in_array($outcome->status, ['rejected', 'failed', 'not_settled'], TRUE)) {
      $this->flood->register('ombu.mcp_refused_payment', self::WINDOW);
    }
    if (!$this->listing || $outcome->status === 'rejected') {
      return;
    }
    // The library's words to x402_pay's: its "failed" delivered nothing
    // (x402_pay: not_settled), its "not_settled" withheld the body (failed).
    $status = match ($outcome->status) {
      'settled', 'failed_after_settle' => 'settled',
      'failed' => 'not_settled',
      default => 'failed',
    };
    $entry = [
      'status' => $status,
      'resource' => $this->listing->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'network' => $outcome->req['network'],
      'amount' => $outcome->req['amount'],
      'pay_to' => $outcome->req['payTo'],
      'payer' => $outcome->receipt['payer'] ?? NULL,
      'transaction' => $outcome->receipt['transaction'] ?? NULL,
      'handler_status' => $outcome->handlerStatus,
      'error' => $outcome->status === 'failed_after_settle' ? Reconciler::REFUND : $outcome->reason,
      'entity_type' => 'commerce_product',
      'entity_id' => (string) $this->listing->id(),
    ];
    $this->log->record($entry);
    $this->events->dispatch(new PaymentEvent($entry, Request::create('/mcp', 'POST')));
  }

}
