<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ombu\Screening\ScreeningLists;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Takes one wallet off the deny list, after confirming it.
 *
 * The address is the route parameter, so a link from the list opens this
 * confirmation directly; the change itself is ScreeningLists::remove().
 */
final class ScreeningRemoveForm extends ConfirmFormBase {

  public function __construct(
    private readonly ScreeningLists $lists,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ombu.screening_lists'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ombu_screening_remove';
  }

  /**
   * The address in the route.
   */
  private function address(): string {
    return (string) $this->getRouteMatch()->getParameter('address');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove @address from the deny list?', ['@address' => $this->address()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('It will be able to buy on Ombú again, unless OFAC or another list refuses it.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Remove');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ombu.screening');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $address = $this->lists->normalize($this->address());
    if ($address === NULL) {
      $this->messenger()->addError($this->t('That is not an EVM or Solana address.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    $address = $this->lists->remove($address);
    $this->messenger()->addStatus($this->t('@address is no longer blocked.', ['@address' => $address]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
