<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ombu\SellerProbe;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /market/sell: a seller lists their own x402 endpoint.
 *
 * The endpoint's 402 says what it charges and to whom; the account must
 * hold a wallet it pays (wallet_auth), which proves the seller. The listing
 * waits unpublished until staff approve it; then its seller manages it like
 * any other (SellerHooks, My listings). Ombú never touches the money: buyers
 * pay the seller's endpoint directly.
 */
final class SellForm extends FormBase {

  public function __construct(
    private readonly ?SellerProbe $probe,
    private readonly CircleCatalogImporter $importer,
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      class_exists(PageFetcher::class) ? new SellerProbe($container->get('http_client')) : NULL,
      $container->get('ombu.circle_importer'),
      $container->get('database'),
    );
  }

  public function getFormId(): string {
    return 'ombu_sell';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = ['#markup' => '<p>' . $this->t('List a service you already sell over x402. Ombú reads its 402 for the price and the wallet it pays; that wallet must be one you signed in with. Buyers pay your endpoint directly, and the listing goes live once we approve it.') . '</p>'];
    $form['endpoint'] = ['#type' => 'url', '#title' => $this->t('Endpoint'), '#required' => TRUE, '#description' => $this->t('The URL that answers 402 Payment Required.')];
    $form['method'] = ['#type' => 'radios', '#title' => $this->t('Method'), '#options' => ['POST' => 'POST', 'GET' => 'GET'], '#default_value' => 'POST'];
    $form['name'] = ['#type' => 'textfield', '#title' => $this->t('What it does'), '#required' => TRUE, '#maxlength' => 200];
    $form['description'] = ['#type' => 'textarea', '#title' => $this->t('Description'), '#rows' => 3];
    $form['provider'] = ['#type' => 'textfield', '#title' => $this->t('Seller name'), '#required' => TRUE, '#maxlength' => 100];
    $form['website'] = ['#type' => 'url', '#title' => $this->t('Website')];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Submit for approval')]];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->probe) {
      $form_state->setErrorByName('endpoint', $this->t('Listing is not available right now.'));
      return;
    }
    try {
      [$resource, $accepts] = $this->probe->requirements((string) $form_state->getValue('endpoint'), (string) $form_state->getValue('method'));
    }
    catch (\RuntimeException $e) {
      $form_state->setErrorByName('endpoint', $e->getMessage());
      return;
    }
    // EVM addresses in any case; Solana's (base58) exactly, as stored.
    $wallets = $this->database->select('wallet_auth_wallet', 'w')->fields('w', ['address'])
      ->condition('uid', $this->currentUser()->id())->execute()->fetchCol();
    $key = fn(string $address) => stripos($address, '0x') === 0 ? strtolower($address) : $address;
    $paid = array_map(fn($a) => $key((string) ($a['payTo'] ?? '')), $accepts);
    if (!array_intersect($paid, array_map($key, $wallets))) {
      $form_state->setErrorByName('endpoint', $this->t('Its 402 pays @to: sign in with (or link) that wallet to list it.', ['@to' => implode(', ', array_unique($paid))]));
      return;
    }
    $form_state->set('probed', [$resource, $accepts]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$resource, $accepts] = $form_state->get('probed');
    $values = $form_state->getValues();
    $outcome = $this->importer->upsert([
      'resource' => $resource,
      'accepts' => $accepts,
      'metadata' => [
        'method' => $values['method'],
        'description' => $values['name'],
        'provider' => ['name' => $values['provider'], 'description' => $values['description'], 'website' => $values['website'] ?: NULL],
      ],
    ], 'third_party');
    if ($outcome === 'exists') {
      $this->messenger()->addWarning($this->t('That endpoint is already listed. If it pays your wallet, it is yours already: see My listings.'));
      $form_state->setRedirect('view.my_listings.page_1');
      return;
    }
    if ($outcome === 'skipped') {
      $this->messenger()->addError($this->t('Its 402 offers no exact payment Ombú can list.'));
      return;
    }
    $this->logger('ombu')->notice('Listing submitted for approval: @endpoint by @user.', ['@endpoint' => $resource, '@user' => $this->currentUser()->getAccountName()]);
    $this->messenger()->addStatus($this->t('Submitted: it goes live once approved. You will find it under My listings.'));
    $form_state->setRedirect('view.my_listings.page_1');
  }

}
