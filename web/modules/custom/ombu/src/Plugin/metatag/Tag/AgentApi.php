<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\metatag\Tag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metatag\Attribute\MetatagTag;
use Drupal\metatag\MetatagType;

/**
 * The OpenAPI description of the site's own services.
 */
#[MetatagTag(
  id: 'ombu_api',
  label: new TranslatableMarkup('API description'),
  name: 'service-desc',
  group: 'ombu_agents',
  type: MetatagType::Uri,
  description: new TranslatableMarkup('The OpenAPI description of what the site sells, for a client that builds calls from it.'),
  weight: 3,
  absolute_url: TRUE,
)]
class AgentApi extends AgentLinkBase {

  /**
   * {@inheritdoc}
   */
  protected string $mediaType = 'application/json';

}
