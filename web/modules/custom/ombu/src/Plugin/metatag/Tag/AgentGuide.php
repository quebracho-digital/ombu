<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\metatag\Tag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metatag\Attribute\MetatagTag;
use Drupal\metatag\MetatagType;

/**
 * The site's guide for agents (llms.txt).
 */
#[MetatagTag(
  id: 'ombu_guide',
  label: new TranslatableMarkup('Guide for agents'),
  name: 'describedby',
  group: 'ombu_agents',
  type: MetatagType::Uri,
  description: new TranslatableMarkup('Where the site explains itself to an agent: what it sells, how to search it and how to pay.'),
  weight: 2,
  absolute_url: TRUE,
)]
class AgentGuide extends AgentLinkBase {

  /**
   * {@inheritdoc}
   */
  protected string $mediaType = 'text/markdown';

}
