<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\ombu\Sellers;
use Drupal\x402_pay\Event\DeliveryEvent;
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
  private const SLOW = ['terms', 'factcheck'];

  public function __construct(private readonly Sellers $sellers) {}

  public static function getSubscribedEvents(): array {
    return [
      PriceEvent::class => 'onPrice',
      DeliveryEvent::class => 'onDelivery',
    ];
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
    if ($seller = $this->sellers->seller($event->entity)) {
      $event->setSeller($seller);
    }
    elseif ($address = $this->sellers->address($event->entity)) {
      // A cold wallet: no key here, the Circle API key settles for it.
      $event->setPayTo($address);
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
