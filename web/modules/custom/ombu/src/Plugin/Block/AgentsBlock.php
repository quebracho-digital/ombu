<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ombu\Markdown;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * For agents, in the footer: this page as markdown, and the whole site.
 *
 * Visible text, the same for people and agents: what an agent reads from
 * a page is its text, often a summary of it, not its head or headers.
 */
#[Block(
  id: 'ombu_agents',
  admin_label: new TranslatableMarkup('For agents'),
  category: new TranslatableMarkup('Ombú'),
)]
final class AgentsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RouteMatchInterface $routeMatch, protected RequestStack $requestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'), $container->get('request_stack'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cacheability = (new CacheableMetadata())->addCacheContexts(['route', 'languages:' . LanguageInterface::TYPE_URL]);
    // The market's .md carries its search; no other page varies by query.
    $query = [];
    if ($this->routeMatch->getRouteName() === 'view.market.page_1') {
      $cacheability->addCacheContexts(['url.query_args']);
      $query = $this->requestStack->getCurrentRequest()->query->all();
    }
    $md = Markdown::url($this->routeMatch, $cacheability, $query);
    $llms = Url::fromRoute('llms_txt.llms_txt')->toString();
    // Every placeholder must be a string, even one the text does not use.
    $build['#markup'] = $md
      ? $this->t('For agents: <a href=":md" rel="alternate" type="text/markdown">this page in markdown</a> · <a href=":llms">the whole site, from /llms.txt</a>', [':md' => $md, ':llms' => $llms])
      : $this->t('For agents: <a href=":llms">the whole site, from /llms.txt</a>', [':llms' => $llms]);
    $cacheability->applyTo($build);
    return $build;
  }

}
