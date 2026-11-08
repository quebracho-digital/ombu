<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\metatag\Group;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metatag\Attribute\MetatagGroup;
use Drupal\metatag\Plugin\metatag\Group\GroupBase;

/**
 * Links that tell an agent where the machine-readable twin of a page is.
 */
#[MetatagGroup(
  id: 'ombu_agents',
  label: new TranslatableMarkup('Agents'),
  description: new TranslatableMarkup('Links to the Markdown, the guide and the API description, for software that reads the page.'),
  weight: 20,
)]
class Agents extends GroupBase {
  // Inherits everything from the base group.
}
