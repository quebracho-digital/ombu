<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Accounts open with a wallet only: no email registration form.
 *
 * Registration is open to visitors so that wallet_auth can create accounts;
 * that setting would also open /user/register.
 */
final class WalletOnlyRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $collection->get('user.register')?->setRequirements(['_access' => 'FALSE']);
  }

}
