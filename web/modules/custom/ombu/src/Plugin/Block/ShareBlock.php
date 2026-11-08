<?php

declare(strict_types=1);

namespace Drupal\ombu\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ombu\Share;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Share links for what the page shows: a blog post, a listing, or a /market
 * subset (its filters travel in the address). Nothing on any other page.
 */
#[Block(
  id: 'ombu_share',
  admin_label: new TranslatableMarkup('Share'),
  category: new TranslatableMarkup('Ombú'),
)]
final class ShareBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {
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
    $target = $this->target();
    if (!$target) {
      return [];
    }
    [$url, $text] = $target;
    $labels = [
      'bluesky' => $this->t('Bluesky'),
      'mastodon' => $this->t('Mastodon'),
      'x' => $this->t('X'),
      'linkedin' => $this->t('LinkedIn'),
      'whatsapp' => $this->t('WhatsApp'),
      'telegram' => $this->t('Telegram'),
      'email' => $this->t('Email'),
    ];
    $links = [];
    foreach (Share::links($url, $text) as $name => $href) {
      $links[$name] = ['label' => $labels[$name], 'href' => $href, 'external' => $name !== 'email'];
    }
    return [
      '#theme' => 'ombu_share',
      '#links' => $links,
      '#url' => $url,
      '#attached' => ['library' => ['ombu/share']],
    ];
  }

  /**
   * The address and text to share, or NULL where nothing is shared.
   *
   * @return array{0: string, 1: string}|null
   */
  private function target(): ?array {
    $route = $this->routeMatch->getRouteName();
    $entity = match ($route) {
      'entity.node.canonical' => $this->routeMatch->getParameter('node'),
      'entity.commerce_product.canonical' => $this->routeMatch->getParameter('commerce_product'),
      default => NULL,
    };
    if ($entity) {
      $shared = ($route === 'entity.node.canonical' && $entity->bundle() === 'blog') || ($route === 'entity.commerce_product.canonical' && $entity->bundle() === 'listing');
      return $shared ? [$entity->toUrl('canonical', ['absolute' => TRUE])->toString(), (string) $entity->label()] : NULL;
    }
    if ($route === 'view.market.page_1') {
      $filters = Share::marketQuery($this->requestStack->getCurrentRequest()?->query->all() ?? []);
      $url = Url::fromRoute($route, [], ['query' => $filters, 'absolute' => TRUE])->toString();
      $search = $filters['search'] ?? '';
      return [
        $url,
        (string) ($search !== '' ? $this->t('Ombú market: @search', ['@search' => $search]) : $this->t('Ombú market')),
      ];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // The /market subset is in the query; a page's address, in its path.
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'url.path',
      'url.query_args',
      'languages:language_interface',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $entity = $this->routeMatch->getParameter('node') ?? $this->routeMatch->getParameter('commerce_product');
    return Cache::mergeTags(parent::getCacheTags(), is_object($entity) ? $entity->getCacheTags() : []);
  }

}
