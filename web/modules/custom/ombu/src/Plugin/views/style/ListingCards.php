<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ombu\Cards;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * A view's listings as Ombú's cards (Cards), under the view's title.
 *
 * Works on listings from Search API or from the product table.
 */
#[ViewsStyle(
  id: 'ombu_cards',
  title: new TranslatableMarkup('Ombú listing cards'),
  help: new TranslatableMarkup('Listings as cards: provider, category, price, rating and chains.'),
  theme: 'views_view_unformatted',
  display_types: ['normal'],
)]
final class ListingCards extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    return parent::defineOptions() + ['show_provider' => ['default' => TRUE], 'slider' => ['default' => FALSE]];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['show_provider'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the provider'),
      '#description' => $this->t('Off when every listing has the same one, as on a seller’s page.'),
      '#default_value' => $this->options['show_provider'],
    ];
    $form['slider'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('As a slider'),
      '#description' => $this->t('Two cards in view, the rest scroll sideways.'),
      '#default_value' => $this->options['slider'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $ids = [];
    foreach ($this->view->result as $row) {
      $entity = $row->_entity ?? $row->_object?->getValue();
      if ($entity) {
        $ids[] = (int) $entity->id();
      }
    }
    return Cards::build($ids, (string) $this->view->getTitle(), (bool) $this->options['show_provider'], (bool) $this->options['slider']);
  }

}
