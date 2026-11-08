<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\views\sort;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsSort;
use Drupal\views\Plugin\views\sort\SortPluginBase;

/**
 * Listings by their rating: the average of their published reviews that
 * carry the wallet that paid (Rating::of()'s rule), then by how many.
 *
 * With "rated_only" (the default) a listing without such a review is left
 * out; otherwise it sorts last. Correlated subqueries, no join, so the
 * view's rows stay one per listing.
 */
#[ViewsSort('ombu_rating')]
final class ListingRating extends SortPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    return parent::defineOptions() + ['rated_only' => ['default' => TRUE]];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['rated_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only listings with a review'),
      '#default_value' => $this->options['rated_only'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $reviews = "FROM {comment_field_data} c
      INNER JOIN {comment__field_rating} r ON r.entity_id = c.cid
      INNER JOIN {comment__field_wallet} w ON w.entity_id = c.cid
      WHERE c.entity_type = 'commerce_product' AND c.status = 1 AND c.entity_id = $this->tableAlias.product_id";
    $this->query->addOrderBy(NULL, "COALESCE((SELECT AVG(r.field_rating_value) $reviews), 0)", $this->options['order'], 'ombu_rating_average');
    $this->query->addOrderBy(NULL, "(SELECT COUNT(*) $reviews)", $this->options['order'], 'ombu_rating_count');
    if ($this->options['rated_only']) {
      $this->query->addWhereExpression(0, "EXISTS (SELECT 1 $reviews)");
    }
  }

}
