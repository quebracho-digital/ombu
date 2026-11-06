<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Drupal\ombu\Markdown;
use Drupal\x402_pay\PathProcessor\MarkdownPathProcessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The blog's list as markdown: /blog.md, or Accept: text/markdown on /blog.
 *
 * The list is a view page, which x402_pay does not render as markdown (its
 * gate answers /blog.md with a 404). So the view's route is answered here, the
 * way ListingMarkdownSubscriber answers a listing: the published posts, in
 * the language asked for, newest first, each with its date, a short summary
 * and its link. The posts are free to read, so nothing is charged.
 *
 * ponytail: the first 50 posts; the list has no paging, and the blog is short.
 */
final class BlogMarkdownSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The route of the blog's list page (views.view.blog, display page_1).
   */
  private const ROUTE = 'view.blog.page_1';

  /**
   * Most posts the markdown list shows.
   */
  private const LIMIT = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32) and before the markdown gate answers pages.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Answers the blog's list in markdown when it was asked for.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || !$request->isMethodCacheable()
      || $request->attributes->get('_route') !== self::ROUTE
      || !(MarkdownPathProcessor::asked($request) || str_contains((string) $request->headers->get('Accept'), 'text/markdown'))) {
      return;
    }
    $event->setResponse($this->markdown($request->getSchemeAndHttpHost()));
  }

  /**
   * The list of posts, as markdown.
   */
  private function markdown(string $site): Response {
    $language = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL);
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, self::LIMIT)
      ->execute();

    $lines = ['# ' . $this->t('Blog'), ''];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $node = $this->entityRepository->getTranslationFromContext($node, $language->getId());
      $url = $node->toUrl('canonical', ['language' => $language])->setAbsolute()->toString();
      $lines[] = sprintf('## [%s](%s)', Markdown::linkText((string) $node->label()), $url);
      $lines[] = '';
      $lines[] = $this->dateLine($node);
      $summary = $this->summary($node);
      if ($summary !== '') {
        $lines[] = '';
        $lines[] = $summary;
      }
      $lines[] = '';
    }
    if (count($lines) === 2) {
      $lines[] = $this->t('No posts yet.');
    }

    return new Response(implode("\n", $lines) . "\n", 200, [
      'Content-Type' => 'text/markdown; charset=utf-8',
      'Vary' => 'Accept',
    ]);
  }

  /**
   * The post's date, in the language of the page.
   */
  private function dateLine(NodeInterface $node): string {
    return $this->dateFormatter->format((int) $node->getCreatedTime(), 'custom', 'j F Y');
  }

  /**
   * The post's summary: its body's summary field, or the start of its text.
   * Plain text either way, so no markup leaks into the markdown.
   */
  private function summary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $body = $node->get('body')->first();
    $text = (string) ($body->summary ?? '');
    if ($text === '') {
      $text = mb_substr((string) $body->value, 0, 600);
    }
    return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? ''), 0, 280);
  }

}
