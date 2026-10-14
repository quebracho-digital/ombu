<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The login page: the wallet first, the password for staff; the account
 * page points to support and to My listings.
 */
final class UserHooks {

  use StringTranslationTrait;

  /**
   * The account pages in Ombú's colours (css/login.css).
   */
  #[Hook('form_alter')]
  public function accountForms(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (in_array($form_id, ['user_login_form', 'user_pass', 'user_register_form'], TRUE)) {
      $form['#attached']['library'][] = 'ombu/login';
    }
  }

  /**
   * The wallets table: removing one goes through support, for now.
   *
   * After wallet_auth, which builds the table.
   */
  #[Hook('form_user_form_alter', order: Order::Last)]
  public function accountForm(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['wallet_auth']['help'])) {
      return;
    }
    $form['wallet_auth']['help']['#markup'] .= '<p>' . $this->t('To remove a wallet, write to <a href=":support">support</a>. Your listings: <a href=":listings">My listings</a>.', [
      ':support' => Url::fromRoute('entity.contact_form.canonical', ['contact_form' => 'support'])->toString(),
      ':listings' => Url::fromRoute('view.my_listings.page_1')->toString(),
    ]) . '</p>';
  }

  /**
   * Wallet sign-in on top; it also creates the account (wallet_auth).
   *
   * Last: wallet_auth adds its button after us otherwise.
   */
  #[Hook('form_user_login_form_alter', order: Order::Last)]
  public function loginForm(array &$form, FormStateInterface $form_state): void {
    $form['#attributes']['class'][] = 'ombu-login';
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
