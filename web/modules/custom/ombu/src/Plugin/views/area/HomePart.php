<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\area;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ombu\Sales;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * A fixed part of the home view: its hero, its latest sales or its "For
 * agents" strip.
 *
 * Hero and agents are templates (ombu-home-hero, ombu-home-agents) whose
 * strings are translated like any other, so the view's config holds no copy
 * to translate. Sales are the market's own block (Sales::build(): the
 * ombu_sales view with its network, and the totals).
 */
#[ViewsArea('ombu_home_part')]
final class HomePart extends AreaPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    return parent::defineOptions() + ['part' => ['default' => 'hero']];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['part'] = [
      '#type' => 'radios',
      '#title' => $this->t('Part'),
      '#options' => ['hero' => $this->t('Hero'), 'sales' => $this->t('Latest sales'), 'agents' => $this->t('For agents')],
      '#default_value' => $this->options['part'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }
    if ($this->options['part'] === 'sales') {
      return ['#type' => 'container', '#attributes' => ['class' => ['ombu-home-section', 'ombu-home-sales']], 'sales' => Sales::build()];
    }
    if ($this->options['part'] === 'agents') {
      return [
        '#theme' => 'ombu_home_agents',
        '#mcp' => Url::fromUserInput('/mcp'),
        '#llms' => Url::fromRoute('llms_txt.llms_txt'),
        '#catalogue' => Url::fromRoute('ombu.catalogue'),
      ];
    }
    return [
      '#theme' => 'ombu_home_hero',
      // The same filter as the market's own "Try our sellers".
      '#sellers' => Url::fromRoute('view.market.page_1', [], ['query' => ['seller' => ['ours' => 'ours']]]),
      '#market' => Url::fromRoute('view.market.page_1'),
    ];
  }

}
