<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ombu\Sales;

/**
 * A fixed part of the home.
 *
 * Its hero, its latest sales or its "For agents" strip. Hero and agents are
 * templates (ombu-home-hero, ombu-home-agents) whose strings are translated
 * like any other, so no layout holds copy to translate. Sales are the market's own block (Sales::build(): the ombu_sales
 * view with its network, and the totals). The home's other sections are
 * views blocks (ombu_home, blog) that Layout Builder places beside this one.
 */
#[Block(
  id: 'ombu_home_part',
  admin_label: new TranslatableMarkup('Home part'),
  category: new TranslatableMarkup('Ombú'),
)]
final class HomePartBlock extends BlockBase {

  /**
   * The parts, by the key kept in the layout.
   */
  private function parts(): array {
    return [
      'hero' => $this->t('Hero'),
      'sales' => $this->t('Latest sales'),
      'agents' => $this->t('For agents'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['part' => 'hero'];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['part'] = [
      '#type' => 'radios',
      '#title' => $this->t('Part'),
      '#options' => $this->parts(),
      '#default_value' => $this->configuration['part'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['part'] = $form_state->getValue('part');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = match ($this->configuration['part']) {
      'sales' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ombu-home-section', 'ombu-home-sales']],
        'sales' => Sales::build(),
      ],
      'agents' => [
        '#theme' => 'ombu_home_agents',
        '#mcp' => Url::fromUserInput('/mcp'),
        '#llms' => Url::fromRoute('llms_txt.llms_txt'),
        '#catalogue' => Url::fromRoute('ombu.catalogue'),
      ],
      default => [
        '#theme' => 'ombu_home_hero',
        // The same filter as the market's own "Try our sellers".
        '#sellers' => Url::fromRoute('view.market.page_1', [], ['query' => ['seller' => ['ours' => 'ours']]]),
        '#market' => Url::fromRoute('view.market.page_1'),
      ],
    };
    $build['#attached']['library'][] = 'ombu/home';
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [...parent::getCacheContexts(), 'languages:language_interface'];
  }

}
