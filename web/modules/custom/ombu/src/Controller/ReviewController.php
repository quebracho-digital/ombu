<?php

declare(strict_types=1);

namespace Drupal\ombu\Controller;

use Drupal\comment\Entity\Comment;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /market/listing/{id}/reviews: an agent reviews what it bought.
 *
 * Signed in with its wallet (wallet_auth), it sends {rating, body, tx}; the
 * review is kept only if tx paid this listing's seller from that wallet,
 * the same check the form makes for people.
 */
final class ReviewController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(private readonly AccountProxyInterface $currentUser) {}

  public function __invoke(ProductInterface $commerce_product, Request $request): JsonResponse {
    $input = json_decode($request->getContent(), TRUE);
    if (!is_array($input)) {
      return new JsonResponse(['error' => 'Send JSON: {"rating": 1-5, "body": "…", "tx": "0x…"}.'], 400);
    }
    $review = Comment::create([
      'comment_type' => 'review',
      'entity_type' => 'commerce_product',
      'entity_id' => $commerce_product->id(),
      'field_name' => 'field_reviews',
      'uid' => $this->currentUser->id(),
      'comment_body' => ['value' => (string) ($input['body'] ?? ''), 'format' => 'plain_text'],
      'field_rating' => $input['rating'] ?? NULL,
      'field_tx_hash' => (string) ($input['tx'] ?? ''),
      'field_reviewer_kind' => 'agent',
    ]);
    $errors = [];
    foreach ($review->validate() as $violation) {
      $errors[] = trim($violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage()), ': ');
    }
    if ($errors) {
      return new JsonResponse(['errors' => $errors], 422);
    }
    $review->save();
    return new JsonResponse([
      'id' => (int) $review->id(),
      'url' => $review->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'wallet' => $review->get('field_wallet')->value,
      'rating' => \Drupal\ombu\Rating::of($commerce_product),
    ], 201);
  }

}
