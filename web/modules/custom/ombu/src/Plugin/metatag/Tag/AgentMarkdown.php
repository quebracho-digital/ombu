<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\metatag\Tag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metatag\Attribute\MetatagTag;
use Drupal\metatag\MetatagType;

/**
 * The page as Markdown.
 */
#[MetatagTag(
  id: 'ombu_markdown',
  label: new TranslatableMarkup('Markdown version'),
  name: 'alternate',
  group: 'ombu_agents',
  type: MetatagType::Uri,
  description: new TranslatableMarkup('The same page as Markdown: less markup to read for an agent.'),
  weight: 1,
  absolute_url: TRUE,
)]
class AgentMarkdown extends AgentLinkBase {

  /**
   * {@inheritdoc}
   */
  protected string $mediaType = 'text/markdown';

}
