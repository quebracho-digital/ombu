<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The footer's credits (ombu-credits.html.twig), in place of core's
 * "Powered by Drupal" block, which Belgrade shows with a logo.
 */
#[Block(
  id: 'ombu_credits',
  admin_label: new TranslatableMarkup('Credits'),
  category: new TranslatableMarkup('Ombú'),
)]
final class CreditsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#theme' => 'ombu_credits'];
  }

}
