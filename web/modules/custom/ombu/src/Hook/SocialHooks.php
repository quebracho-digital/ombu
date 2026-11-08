<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\ombu\Share;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What a network shows when an Ombú address is shared: Open Graph and
 * Twitter tags for a blog post, a listing and a /market subset.
 */
final class SocialHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $route = $this->routeMatch->getRouteName();
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.path', 'url.query_args', 'languages:language_interface']);
    $tags = NULL;
    $image = NULL;
    $type = 'website';
    if ($route === 'entity.node.canonical' && ($entity = $this->routeMatch->getParameter('node')) && $entity->bundle() === 'blog') {
      $type = 'article';
      $body = $entity->hasField('body') ? $entity->get('body') : NULL;
      $description = Share::description((string) ($body?->summary ?: $body?->value));
      if ($entity->hasField('field_image') && ($file = $entity->get('field_image')->entity)) {
        // Already absolute: the networks fetch it from outside.
        $image = $file->createFileUrl(FALSE);
        $cacheability->addCacheableDependency($file);
      }
    }
    elseif ($route === 'entity.commerce_product.canonical' && ($entity = $this->routeMatch->getParameter('commerce_product')) && $entity->bundle() === 'listing') {
      $description = Share::description((string) ($entity->get('field_openapi')->isEmpty() ? $entity->get('body')->value : $entity->label()));
    }
    elseif ($route === 'view.market.page_1') {
      $entity = NULL;
      $description = (string) t('Services AI agents can find and pay for with x402.');
    }
    else {
      return;
    }
    if ($entity ?? NULL) {
      $cacheability->addCacheableDependency($entity);
      $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      $title = (string) $entity->label();
    }
    else {
      $filters = Share::marketQuery($this->requestStack->getCurrentRequest()?->query->all() ?? []);
      $url = Url::fromRoute($route, [], ['query' => $filters, 'absolute' => TRUE])->toString();
      $title = (string) t('Ombú market');
    }

    $meta = [
      'og:site_name' => 'Ombú',
      'og:type' => $type,
      'og:title' => $title,
      'og:url' => $url,
      'twitter:card' => $image ? 'summary_large_image' : 'summary',
    ];
    if ($description !== '') {
      $meta['og:description'] = $description;
    }
    if ($image) {
      $meta['og:image'] = $image;
    }
    foreach ($meta as $name => $content) {
      // Open Graph uses property=, Twitter name=.
      $key = str_starts_with($name, 'og:') ? 'property' : 'name';
      $attachments['#attached']['html_head'][] = [
        ['#tag' => 'meta', '#attributes' => [$key => $name, 'content' => $content]],
        'ombu_social_' . str_replace(':', '_', $name),
      ];
    }
    $cacheability->applyTo($attachments);
  }

}
