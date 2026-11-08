<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ombu\Controller\CatalogueController;
use Drupal\ombu\Share;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What Metatag says about an Ombú page, as `[current-page:ombu-*]` tokens.
 *
 * Metatag writes the tags (Open Graph, Twitter, canonical, and the links for
 * agents); these tokens are the values that depend on the page: a blog post,
 * a listing, or a /market subset with its filters.
 */
final class MetatagHooks {

  use StringTranslationTrait;

  /**
   * The photo shared when a page has none of its own.
   */
  private const DEFAULT_IMAGE = 'images/blog/ombu-tree.jpg';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly ExtensionPathResolver $paths,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $image = [
      'name' => $this->t('Ombú image'),
      'description' => $this->t("The post's photo, or Ombú's own."),
      'type' => 'url',
    ];
    return [
      'tokens' => [
        'current-page' => [
          'ombu-url' => [
            'name' => $this->t('Ombú address'),
            'description' => $this->t('The page address; for the market, with only its filters.'),
          ],
          'ombu-type' => [
            'name' => $this->t('Ombú Open Graph type'),
            'description' => $this->t('article for a blog post, website for the rest.'),
          ],
          'ombu-description' => [
            'name' => $this->t('Ombú description'),
            'description' => $this->t('A plain-text preview of the post or the listing.'),
          ],
          'ombu-image' => $image,
          'ombu-markdown' => [
            'name' => $this->t('Ombú Markdown address'),
            'description' => $this->t('The market search as Markdown; a post and a listing have their own link.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    if ($type !== 'current-page') {
      return [];
    }
    $bubbleable_metadata->addCacheContexts(['url.path', 'url.query_args', 'languages:language_interface']);
    $route = $this->routeMatch->getRouteName();
    $entity = NULL;
    if ($route === 'entity.node.canonical' && ($node = $this->routeMatch->getParameter('node')) && $node->bundle() === 'blog') {
      $entity = $node;
    }
    elseif ($route === 'entity.commerce_product.canonical' && ($product = $this->routeMatch->getParameter('commerce_product')) && $product->bundle() === 'listing') {
      $entity = $product;
    }
    $market = $route === 'view.market.page_1';
    if ($entity) {
      $bubbleable_metadata->addCacheableDependency($entity);
    }

    $replacements = [];
    foreach ($tokens as $name => $original) {
      $replacements[$original] = match ($name) {
        'ombu-url' => $this->url($entity, $market),
        'ombu-type' => $entity?->bundle() === 'blog' ? 'article' : 'website',
        'ombu-description' => $this->description($entity, $market),
        'ombu-image' => $this->image($entity, $bubbleable_metadata),
        'ombu-markdown' => $this->markdown($market),
        default => NULL,
      };
      if ($replacements[$original] === NULL) {
        unset($replacements[$original]);
      }
    }
    return $replacements;
  }

  /**
   * The address a network should keep: no page, no tracking, no session.
   */
  private function url(?object $entity, bool $market): string {
    if ($entity) {
      return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    $query = $this->requestStack->getCurrentRequest()?->query->all() ?? [];
    return Url::fromRoute('<current>', [], [
      'absolute' => TRUE,
      'query' => $market ? Share::marketQuery($query) : [],
    ])->toString();
  }

  /**
   * A plain-text preview of a post or a listing.
   */
  private function description(?object $entity, bool $market): string {
    if ($entity?->bundle() === 'blog') {
      $body = $entity->get('body');
      return Share::description((string) ($body->summary ?: $body->value));
    }
    if ($entity) {
      return Share::description((string) ($entity->get('field_openapi')->isEmpty() ? $entity->get('body')->value : $entity->label()));
    }
    return (string) $this->t('Services AI agents can find and pay for with x402.');
  }

  /**
   * The post's photo, or Ombú's own, as an absolute address.
   */
  private function image(?object $entity, BubbleableMetadata $metadata): string {
    if ($entity?->bundle() === 'blog' && $entity->hasField('field_image') && ($file = $entity->get('field_image')->entity)) {
      $metadata->addCacheableDependency($file);
      // Already absolute: the networks fetch it from outside.
      return $file->createFileUrl(FALSE);
    }
    $path = $this->paths->getPath('module', 'ombu') . '/' . self::DEFAULT_IMAGE;
    return Url::fromUri('base:' . $path, ['absolute' => TRUE])->toString();
  }

  /**
   * The Markdown twin of a market search ('' for the rest).
   */
  private function markdown(bool $market): string {
    // A post and a listing already carry their own link (x402_pay).
    if ($market) {
      return CatalogueController::marketMd(Share::marketQuery($this->requestStack->getCurrentRequest()?->query->all() ?? []));
    }
    return '';
  }

}
