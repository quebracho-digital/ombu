<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\Cache;
use Drupal\ombu\Sales;
use Drupal\ombu\Sellers;
use Drupal\x402_pay\Event\DeliveryEvent;
use Drupal\x402_pay\Event\PaymentEvent;
use Drupal\x402_pay\Event\PriceEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Our listings over x402: paid to their own seller, delivering its service.
 *
 * A retired listing (any, ours or imported) is not for sale.
 */
final class SellerSubscriber implements EventSubscriberInterface {

  /**
   * Services that wait on a model: the buyer's payment must outlive them.
   */
  private const SLOW = ['terms', 'factcheck', 'aitext', 'plagiarism'];

  public function __construct(private readonly Sellers $sellers) {}

  public static function getSubscribedEvents(): array {
    return [
      PriceEvent::class => 'onPrice',
      DeliveryEvent::class => 'onDelivery',
      PaymentEvent::class => 'onPayment',
      // ai_provider_universal's ModelPreCallEvent, by name: it is optional.
      'ai_provider_universal.model_pre_call' => 'onModelCall',
    ];
  }

  /**
   * A long terms analysis runs on the long-documents model (Sellers::terms).
   */
  public function onModelCall(object $event): void {
    if ($this->sellers->longModel) {
      $event->setModelId($this->sellers->longModel);
    }
  }

  /**
   * A settled sale shows at once in the latest sales (Sales).
   */
  public function onPayment(PaymentEvent $event): void {
    if ($event->status() === 'settled') {
      Cache::invalidateTags([Sales::TAG]);
    }
  }

  public function onPrice(PriceEvent $event): void {
    if ($event->entity->hasField('field_retired') && $event->entity->get('field_retired')->value) {
      // Retired: still shown and reviewed, never sold.
      $event->deny();
      return;
    }
    if (!$service = $this->service($event->entity)) {
      return;
    }
    // A cold wallet first (Sellers::payTo()): no key here, our facilitator
    // or the Circle API key settles for it.
    if ($address = $this->sellers->address($event->entity)) {
      $event->setPayTo($address);
      $event->setNetwork(Sellers::network($event->entity));
    }
    elseif ($seller = $this->sellers->seller($event->entity)) {
      $event->setSeller($seller);
    }
    else {
      // Never fall back to the field's key: that wallet is not this seller's.
      $event->deny();
      return;
    }
    $event->setMimeType('application/json');
    if (in_array($service, self::SLOW, TRUE)) {
      $event->setTimeout(600);
    }
  }

  public function onDelivery(DeliveryEvent $event): void {
    if ($service = $this->service($event->entity)) {
      $event->setResponse($this->sellers->deliver($service, $event->entity, $event->request));
    }
  }

  private function service($entity): ?string {
    return $entity instanceof ProductInterface && $entity->hasField('field_service') ? ($entity->get('field_service')->value ?: NULL) : NULL;
  }

}
