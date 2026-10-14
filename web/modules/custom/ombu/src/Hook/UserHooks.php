<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The login page: the wallet first, the password for staff.
 */
final class UserHooks {

  use StringTranslationTrait;

  /**
   * Wallet sign-in on top; it also creates the account (wallet_auth).
   *
   * Last: wallet_auth adds its button after us otherwise.
   */
  #[Hook('form_user_login_form_alter', order: Order::Last)]
  public function loginForm(array &$form, FormStateInterface $form_state): void {
    $form['#attributes']['class'][] = 'ombu-login';
    $form['#attached']['library'][] = 'ombu/login';
    $form['lead'] = [
      '#markup' => '<p class="ombu-login__lead">' . $this->t('Sign in with your wallet. New here? The same button creates your account: no email, no password.') . '</p>',
      '#weight' => -100,
    ];
    if (isset($form['wallet_auth'])) {
      $form['wallet_auth']['#weight'] = -90;
      $form['wallet_auth']['#attributes']['class'][] = 'ombu-login__wallet';
      $form['wallet_hint'] = [
        '#markup' => '<p class="ombu-login__hint">' . $this->t('Your wallet asks you to sign a message. Nothing is paid or moved.') . '</p>',
        '#weight' => -89,
      ];
    }
    $form['or'] = [
      '#markup' => '<p class="ombu-login__or"><span>' . $this->t('or with a username and password') . '</span></p>',
      '#weight' => -80,
    ];
    $form['name']['#weight'] = -70;
    $form['pass']['#weight'] = -60;
    // After the theme's alter (Belgrade adds a password sign-up footer).
    $form['#after_build'][] = [self::class, 'afterBuild'];
  }

  /**
   * No password sign-up: the wallet button creates accounts.
   */
  public static function afterBuild(array $form): array {
    unset($form['actions']['footer']);
    $form['actions']['submit']['#attributes']['class'][] = 'ombu-login__password';
    return $form;
  }

}
