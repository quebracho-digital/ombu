<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\search_api\processor;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\ombu\ReviewVerifier;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Indexes the wallets a listing pays today, to find listings by wallet.
 *
 * Every payTo of its offers (an imported listing may pay one per chain), or
 * the address of its seller key for ours: what ReviewVerifier::payees()
 * says, EVM addresses in lower case. Not the wallets it paid before.
 */
#[SearchApiProcessor(
  id: 'ombu_wallets',
  label: new TranslatableMarkup('Wallets a listing pays'),
  description: new TranslatableMarkup('Adds the wallets a listing pays today, so that the market can be filtered by wallet.'),
  stages: ['add_properties' => 0],
)]
final class Wallets extends ProcessorPluginBase {

  /**
   * Says who a listing pays.
   */
  private ReviewVerifier $verifier;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->verifier = $container->get('ombu.review_verifier');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource) {
      return [];
    }
    return [
      'ombu_wallets' => new ProcessorProperty([
        'label' => $this->t('Wallets it pays'),
        'description' => $this->t('The wallets a listing pays today.'),
        'type' => 'string',
        'is_list' => TRUE,
        'processor_id' => $this->getPluginId(),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $listing = $item->getOriginalObject()?->getValue();
    if (!$listing instanceof ProductInterface || $listing->bundle() !== 'listing') {
      return;
    }
    $wallets = [];
    foreach ($this->verifier->payees($listing) as $pairs) {
      foreach ($pairs as [, $wallet]) {
        $wallets[$wallet] = $wallet;
      }
    }
    foreach ($this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL, 'ombu_wallets') as $field) {
      foreach ($wallets as $wallet) {
        $field->addValue($wallet);
      }
    }
  }

}
