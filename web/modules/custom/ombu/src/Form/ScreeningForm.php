<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ombu\Screening\ScreeningLists;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The deny list at /admin/ombu/screening, with add and remove.
 *
 * The panel only links here; this form is where a wallet is blocked. Nothing
 * is written until the address is confirmed, and every change goes through
 * ScreeningLists, the same service drush ombu:screen uses.
 */
final class ScreeningForm extends FormBase {

  public function __construct(
    private readonly ScreeningLists $lists,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ombu.screening_lists'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ombu_screening';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $address = (string) $form_state->get('confirm_address');
    if ($address !== '') {
      $form = $this->confirm($address);
      return $form;
    }

    $lists = $this->lists->lists();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('A wallet on the deny list cannot buy on Ombú: the check runs before any payment is verified or charged. Add one by its EVM (0x…) or Solana address.') . '</p>',
    ];
    $form['deny'] = [
      '#type' => 'table',
      '#caption' => $this->t('Blocked wallets'),
      '#header' => [$this->t('Wallet'), $this->t('Actions')],
      '#empty' => $this->t('No wallet is blocked.'),
    ];
    foreach ($lists['deny'] ?? [] as $blocked) {
      $form['deny'][] = [
        'address' => ['#plain_text' => $blocked],
        'remove' => [
          '#type' => 'link',
          '#title' => $this->t('Remove'),
          '#url' => Url::fromRoute('ombu.screening.remove', ['address' => $blocked]),
        ],
      ];
    }
    $form['allow'] = [
      '#type' => 'details',
      '#title' => $this->t('Always allowed (@count)', ['@count' => count($lists['allow'] ?? [])]),
    ];
    $form['allow']['help'] = [
      '#markup' => '<p>' . $this->t('A wallet on the allow list skips our deny list and Chainalysis; OFAC is always asked. It is managed with drush ombu:screen allow.') . '</p>',
    ];
    if ($lists['allow'] ?? []) {
      $form['allow']['addresses'] = [
        '#theme' => 'item_list',
        '#items' => $lists['allow'],
      ];
    }
    $form['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wallet to block'),
      '#description' => $this->t('An EVM address (0x and 40 hex digits) or a Solana one (base58).'),
      '#maxlength' => 64,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['block'] = [
      '#type' => 'submit',
      '#value' => $this->t('Block this wallet'),
      '#button_type' => 'danger',
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to the dashboard'),
      '#url' => Url::fromRoute('ombu.dashboard'),
    ];
    return $form;
  }

  /**
   * The confirmation step: the same form, before anything is written.
   */
  private function confirm(string $address): array {
    $form['#title'] = $this->t('Block @address?', ['@address' => $address]);
    $form['#attributes']['class'][] = 'confirmation';
    $form['question'] = [
      '#markup' => '<p>' . $this->t('It will not be able to buy on Ombú. The deny list is checked before any payment is verified; a wallet on OFAC’s list is refused anyway.') . '</p>',
    ];
    $form['confirm'] = ['#type' => 'hidden', '#value' => 1];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['block'] = [
      '#type' => 'submit',
      '#value' => $this->t('Block the wallet'),
      '#button_type' => 'danger',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancel'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('confirm_address')) {
      return;
    }
    $raw = trim((string) $form_state->getValue('address'));
    if ($raw === '') {
      // The required flag already complained.
      return;
    }
    $address = $this->lists->normalize($raw);
    if ($address === NULL) {
      $form_state->setErrorByName('address', $this->t('That is not an EVM or Solana address.'));
      return;
    }
    if (in_array($address, $this->lists->lists()['deny'] ?? [], TRUE)) {
      $form_state->setErrorByName('address', $this->t('@address is already blocked.', ['@address' => $address]));
      return;
    }
    $form_state->set('normalized_address', $address);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $address = (string) $form_state->get('confirm_address');
    if ($address === '') {
      $address = (string) $form_state->get('normalized_address');
      if ($address === '') {
        return;
      }
      $form_state->set('confirm_address', $address);
      $form_state->setRebuild(TRUE);
      return;
    }
    $this->lists->deny($address);
    $this->messenger()->addStatus($this->t('@address is blocked.', ['@address' => $address]));
    $form_state->setRedirectUrl(Url::fromRoute('ombu.screening'));
  }

  /**
   * Goes back to the list without blocking anything.
   */
  public function cancel(array &$form, FormStateInterface $form_state): void {
    $form_state->set('confirm_address', NULL);
    $form_state->setRebuild(TRUE);
  }

}
