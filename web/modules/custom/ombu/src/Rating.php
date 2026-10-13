<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;

/**
 * A listing's rating, from its verified reviews.
 */
final class Rating {

  /**
   * Average rating and review count of a listing, or NULL without reviews.
   *
   * Only verified reviews count: those with the wallet that paid, which only
   * the purchase check sets (ReviewHooks::presave).
   */
  public static function of(ProductInterface $product): ?array {
    // ponytail: one aggregate query per listing shown; store the average on
    // the product if pages with many listings get slow.
    $row = \Drupal::database()->query(
      "SELECT AVG(r.field_rating_value) avg, COUNT(*) n FROM {comment_field_data} c
       JOIN {comment__field_rating} r ON r.entity_id = c.cid
       JOIN {comment__field_wallet} w ON w.entity_id = c.cid
       WHERE c.entity_type = 'commerce_product' AND c.entity_id = :id AND c.status = 1",
      [':id' => $product->id()],
    )->fetchAssoc();
    return $row['n'] ? ['average' => round((float) $row['avg'], 1), 'count' => (int) $row['n']] : NULL;
  }

}
