<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\comment\CommentManager as CoreCommentManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Offers "Log in" alone where /user/register is closed.
 *
 * Core offers "register" whenever visitors may register, but Ombú opens
 * accounts with a wallet only, so that link would be a 403.
 */
final class CommentManager extends CoreCommentManager {

  /**
   * {@inheritdoc}
   */
  public function forbiddenMessage(EntityInterface $entity, $field_name) {
    $message = parent::forbiddenMessage($entity, $field_name);
    if ($message === '' || Url::fromRoute('user.register')->access()) {
      return $message;
    }
    $destination = $entity->toUrl('canonical', ['fragment' => 'comment-form'])->toString();
    return $this->t('<a href=":login">Log in with your wallet</a> to post a review', [
      ':login' => Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]])->toString(),
    ]);
  }

}
