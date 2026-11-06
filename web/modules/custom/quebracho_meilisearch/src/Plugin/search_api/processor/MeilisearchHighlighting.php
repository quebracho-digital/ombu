<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch\Plugin\search_api\processor;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;

/**
 * Builds result excerpts from Meilisearch's highlighting and cropping.
 *
 * The excerpt is available in Views as the "Excerpt" field, and the
 * highlighted values in the "highlighted_fields" extra data of each result.
 */
#[SearchApiProcessor(
  id: 'quebracho_meilisearch_highlighting',
  label: new TranslatableMarkup('Meilisearch highlighting'),
  description: new TranslatableMarkup('Creates excerpts with the matched words highlighted, computed by Meilisearch.'),
  stages: [
    'preprocess_query' => 0,
    'postprocess_query' => 0,
  ],
)]
class MeilisearchHighlighting extends ProcessorPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * Markers Meilisearch puts around matches, replaced after escaping.
   *
   * Private-use characters, so they cannot come from indexed content.
   */
  protected const PRE_MARKER = "\u{E000}";
  protected const POST_MARKER = "\u{E001}";

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index): bool {
    return $index->hasValidServer() && $index->getServerInstance()->getBackendId() === 'meilisearch';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'fields' => [],
      'pre_tag' => '<strong>',
      'post_tag' => '</strong>',
      'crop_length' => 30,
      'crop_marker' => '…',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields'),
      '#options' => $this->getFulltextFieldLabels(),
      '#default_value' => $this->configuration['fields'],
      '#description' => $this->t('The fulltext fields to build the excerpt from. None selected means all of them.'),
    ];
    $form['pre_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Highlight opening tag'),
      '#default_value' => $this->configuration['pre_tag'],
      '#description' => $this->t('Views only keeps basic HTML in excerpts: use &lt;strong&gt;, &lt;em&gt;, &lt;cite&gt; or &lt;code&gt;.'),
    ];
    $form['post_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Highlight closing tag'),
      '#default_value' => $this->configuration['post_tag'],
    ];
    $form['crop_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Excerpt length'),
      '#field_suffix' => $this->t('words'),
      '#min' => 1,
      '#default_value' => $this->configuration['crop_length'],
    ];
    $form['crop_marker'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Crop marker'),
      '#default_value' => $this->configuration['crop_marker'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $this->setConfiguration([
      'fields' => array_values(array_filter((array) $values['fields'])),
      'pre_tag' => (string) $values['pre_tag'],
      'post_tag' => (string) $values['post_tag'],
      'crop_length' => max(1, (int) $values['crop_length']),
      'crop_marker' => (string) $values['crop_marker'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query): void {
    $fields = $this->getHighlightedFields();
    if (!$fields) {
      return;
    }
    $query->setOption('quebracho_meilisearch_highlighting', [
      'fields' => $fields,
      'pre_tag' => self::PRE_MARKER,
      'post_tag' => self::POST_MARKER,
      'crop_length' => (int) $this->configuration['crop_length'],
      'crop_marker' => (string) $this->configuration['crop_marker'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results): void {
    $fields = $this->getHighlightedFields();
    foreach ($results->getResultItems() as $item) {
      $formatted = $item->getExtraData('quebracho_meilisearch_formatted');
      if (!is_array($formatted)) {
        continue;
      }
      $highlighted = [];
      foreach ($fields as $field) {
        foreach ((array) ($formatted[$field] ?? []) as $value) {
          if (is_string($value) && str_contains($value, self::PRE_MARKER)) {
            $highlighted[$field][] = $this->render($value);
          }
        }
      }
      if ($highlighted) {
        $item->setExtraData('highlighted_fields', $highlighted);
        $item->setExcerpt($this->joinSnippets(array_merge(...array_values($highlighted))));
      }
    }
  }

  /**
   * Joins snippets with the crop marker, without doubling it.
   *
   * @param string[] $snippets
   *   Highlighted snippets, possibly starting or ending with the marker.
   */
  protected function joinSnippets(array $snippets): string {
    $marker = (string) $this->configuration['crop_marker'];
    if ($marker === '') {
      return implode(' ', $snippets);
    }
    $starts = str_starts_with(reset($snippets), $marker);
    $ends = str_ends_with(end($snippets), $marker);
    $pattern = '/^\s*' . preg_quote($marker, '/') . '|' . preg_quote($marker, '/') . '\s*$/u';
    $trimmed = array_map(fn(string $snippet) => trim(preg_replace($pattern, '', $snippet)), $snippets);
    return ($starts ? $marker : '') . implode(" $marker ", $trimmed) . ($ends ? $marker : '');
  }

  /**
   * Escapes a formatted value, then turns the markers into the tags.
   */
  protected function render(string $value): string {
    return str_replace(
      [self::PRE_MARKER, self::POST_MARKER],
      [Xss::filterAdmin($this->configuration['pre_tag']), Xss::filterAdmin($this->configuration['post_tag'])],
      Html::escape($value),
    );
  }

  /**
   * Returns the fields to highlight: the configured ones, or all fulltext.
   *
   * @return string[]
   *   Field IDs.
   */
  protected function getHighlightedFields(): array {
    $available = array_keys($this->getFulltextFieldLabels());
    $configured = array_values(array_filter((array) $this->configuration['fields']));
    return $configured ? array_values(array_intersect($configured, $available)) : $available;
  }

  /**
   * Returns the labels of the index's fulltext fields, keyed by field ID.
   */
  protected function getFulltextFieldLabels(): array {
    $labels = [];
    foreach ($this->index->getFields() as $id => $field) {
      if ($field->getType() === 'text') {
        $labels[$id] = $field->getLabel();
      }
    }
    return $labels;
  }

}
