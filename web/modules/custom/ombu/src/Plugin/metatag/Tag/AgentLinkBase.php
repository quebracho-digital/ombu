<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\metatag\Tag;

use Drupal\metatag\Plugin\metatag\Tag\LinkRelBase;

/**
 * A `<link rel>` for agents that also says the media type it points to.
 */
abstract class AgentLinkBase extends LinkRelBase {

  /**
   * The media type of what the link points to.
   */
  protected string $mediaType = '';

  /**
   * {@inheritdoc}
   */
  public function output(): array {
    $element = parent::output();
    if ($element) {
      $element['#attributes']['type'] = $this->mediaType;
    }
    return $element;
  }

}
