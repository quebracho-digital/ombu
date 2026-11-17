<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\ombu\ResourceVerifier;
use Drupal\ombu\WalletChanges;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /admin/ombu/wallet-changes/{id}/accept: the new wallet of one listing.
 *
 * The confirmation shows the wallet on file and the one the seller answers
 * with now. Accepting asks the endpoint again with the same verifier the
 * import used (WalletChanges::accept); only a pass replaces the wallet.
 */
final class WalletChangeForm extends ConfirmFormBase {

  public function __construct(
    private readonly WalletChanges $changes,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ombu.wallet_changes'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ombu_wallet_change';
  }

  /**
   * The listing id in the route.
   */
  private function id(): int {
    return (int) $this->routeMatch->getParameter('commerce_product');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->changes->change($this->id()) === NULL) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('This listing is no longer waiting for a wallet change. It may have been accepted already, or another import may have taken it off the list.') . '</p>',
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to the dashboard'),
        '#url' => $this->getCancelUrl(),
      ];
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $change = $this->changes->change($this->id());
    return $this->t('Accept the new wallet for @listing?', ['@listing' => $change['url'] ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $change = $this->changes->change($this->id());
    if ($change === NULL) {
      return '';
    }
    return $this->t('Wallet on file: @old. The seller now answers: @new. Accepting asks the endpoint again and keeps the listing, its id and its reviews; only a pass replaces the wallet.', [
      '@old' => $change['old'] ? implode(', ', $change['old']) : $this->t('none'),
      '@new' => $change['new'] ? implode(', ', $change['new']) : $this->t('none'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Accept the new wallet');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ombu.dashboard', [], ['fragment' => 'ombu-wallet_changes']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = $this->id();
    $change = $this->changes->change($id);
    if ($change === NULL) {
      $this->messenger()->addWarning($this->t('This listing is no longer waiting for a wallet change.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      $report = $this->changes->accept($id);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('The wallet was not checked: @error', ['@error' => $e->getMessage()]));
      $this->logger('ombu')->warning('Wallet change for listing @id (@url) could not be checked by @user (uid @uid): @error', [
        '@id' => $id,
        '@url' => $change['url'],
        '@user' => $this->currentUser()->getAccountName(),
        '@uid' => (int) $this->currentUser()->id(),
        '@error' => $e->getMessage(),
      ]);
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // The verifier's report, check by check, as the command prints it.
    foreach ((array) ($report['checks'] ?? []) as $at => $check) {
      if (!is_array($check) || count($check) < 2) {
        continue;
      }
      [$verdict, $detail] = $check;
      $this->messenger()->addStatus($this->t('@verdict @check: @detail', [
        '@verdict' => (string) $verdict,
        '@check' => (string) $at,
        '@detail' => (string) $detail,
      ]));
    }

    $accepted = ($report['verdict'] ?? '') === ResourceVerifier::PASS;
    $this->messenger()->addStatus($accepted
      ? $this->t('Accepted: the listing comes back on the next import.')
      : $this->t('Not accepted: the wallet on file stays.'));
    $log = [
      '@id' => $id,
      '@url' => $change['url'],
      '@user' => $this->currentUser()->getAccountName(),
      '@uid' => (int) $this->currentUser()->id(),
      '@verdict' => (string) ($report['verdict'] ?? 'unknown'),
    ];
    if ($accepted) {
      $this->logger('ombu')->notice('Wallet change for listing @id (@url) accepted by @user (uid @uid): @verdict.', $log);
    }
    else {
      $this->logger('ombu')->warning('Wallet change for listing @id (@url) refused by @user (uid @uid): @verdict.', $log);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
