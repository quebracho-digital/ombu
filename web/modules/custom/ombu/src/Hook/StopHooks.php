<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ombu\Stop;

/**
 * What the pages say and leave out while the site is stopped (Stop).
 *
 * The wallets themselves are taken out of every response by StopSubscriber;
 * here goes what invites to use one: the "Try it" form of a listing and the
 * wallet sign-in. Each piece carries the stop's cache tag, so it changes the
 * moment the switch does.
 */
final class StopHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Stop $stop,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_page_top(): the notice on every page.
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    $page_top['ombu_stop'] = ['#cache' => ['tags' => [Stop::TAG]]];
    if ($this->stop->engaged()) {
      $page_top['ombu_stop'] += [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['ombu-stopped'],
          'role' => 'status',
          'style' => 'background:#7a1f1f;color:#fff;padding:.6rem 1rem;text-align:center',
        ],
        '#value' => $this->t('Ombú is paused: you can read and search, but nothing can be bought, sold or changed for now.'),
      ];
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_view_alter(): no "Try it" while stopped.
   */
  #[Hook('commerce_product_view_alter', order: Order::Last)]
  public function listing(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    $build['#cache']['tags'][] = Stop::TAG;
    if ($this->stop->engaged()) {
      unset($build['buy']);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter(): the password only, for staff.
   */
  #[Hook('form_user_login_form_alter', order: Order::Last)]
  public function login(array &$form, FormStateInterface $form_state): void {
    $form['#cache']['tags'][] = Stop::TAG;
    if ($this->stop->engaged()) {
      unset($form['wallet_auth'], $form['wallet_auth_solana'], $form['wallet_hint'], $form['lead'], $form['or']);
    }
  }

  /**
   * Implements hook_runtime_requirements(): said in the status report.
   */
  #[Hook('runtime_requirements')]
  public function requirements(): array {
    $note = $this->stop->note();
    return [
      'ombu_stop' => [
        'title' => $this->t('Ombú stop switch'),
        'value' => $this->stop->engaged()
          ? $this->t('Stopped (read-only) since @time: @why', [
            '@time' => $note ? $this->dateFormatter->format($note['time'], 'short') : '?',
            '@why' => $note && $note['why'] !== '' ? $note['why'] : $this->t('no reason given'),
          ])
          : $this->t('Running'),
        'description' => $this->stop->engaged() ? $this->t('Payments are paused and the site is read-only. <a href=":url">Resume</a>, or <code>drush ombu:stop off</code>.', [':url' => Url::fromRoute('ombu.stop')->toString()]) : '',
        // REQUIREMENT_ERROR and REQUIREMENT_OK, without the deprecated
        // constants.
        'severity' => $this->stop->engaged() ? 2 : 0,
      ],
    ];
  }

}
