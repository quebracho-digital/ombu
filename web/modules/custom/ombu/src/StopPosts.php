<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * The blog post of each stop and each resume of the site, per language.
 *
 * Category, title and body come from templates (config ombu.stop, one set
 * per language; a language without one uses English). A body takes {reason}
 * and {date}. The reason is typed by staff and published: it is escaped, so
 * it is text and never markup; the templates are trusted (config).
 *
 * Writing the post never stops the switch: a failure is logged and the stop
 * or the resume stands.
 */
class StopPosts {

  private const VOCABULARY = 'blog_category';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly ?object $pathauto = NULL,
  ) {}

  /**
   * Publishes the post of a stop ($stopping) or of a resume.
   *
   * @param bool $stopping
   *   TRUE for a stop, FALSE for a resume.
   * @param string $why
   *   The reason, in the site's default language.
   * @param array<string, string> $reasons
   *   The reason in other languages, by language code.
   *
   * @return int|null
   *   The node's id; NULL when posts are off, there is no blog, or it failed.
   */
  public function publish(bool $stopping, string $why = '', array $reasons = []): ?int {
    $config = $this->configFactory->get('ombu.stop');
    $templates = $config->get('templates') ?: [];
    if (!$config->get('post') || empty($templates['en'])) {
      return NULL;
    }
    try {
      if (!$this->entityTypeManager->hasDefinition('node') || !$this->entityTypeManager->getStorage('node_type')->load('blog')) {
        return NULL;
      }
      $now = $this->time->getCurrentTime();
      $default = $this->languageManager->getDefaultLanguage()->getId();
      // The default language first: it is the node, the others translate it.
      $langcodes = array_unique(array_merge([$default], array_keys($this->languageManager->getLanguages())));
      $node = NULL;
      foreach ($langcodes as $langcode) {
        $template = ($templates[$langcode] ?? []) + $templates['en'];
        $reason = trim($reasons[$langcode] ?? '') ?: (trim($why) ?: ($stopping ? $template['no_reason'] : ''));
        $body = strtr($template[$stopping ? 'stop_body' : 'resume_body'], [
          '{reason}' => Html::escape($reason),
          '{date}' => Html::escape($this->dateFormatter->format($now, 'custom', 'Y-m-d H:i T', NULL, $langcode)),
        ]);
        // A resume with no note: its empty paragraph goes.
        $body = str_replace('<p></p>', '', $body);
        $values = [
          'title' => $template[$stopping ? 'stop_title' : 'resume_title'],
          'body' => ['value' => $body, 'summary' => '', 'format' => $this->format()],
        ];
        if ($node === NULL) {
          $node = $this->entityTypeManager->getStorage('node')->create([
            'type' => 'blog',
            'langcode' => $langcode,
            'uid' => (int) $this->currentUser->id() ?: 1,
            'status' => 1,
            'created' => $now,
          ] + $values);
          if ($node->hasField('field_blog_category') && ($tid = $this->category($templates, $default))) {
            $node->set('field_blog_category', [$tid]);
          }
        }
        elseif (!$node->hasTranslation($langcode)) {
          $node->addTranslation($langcode, $values + ['uid' => $node->getOwnerId(), 'status' => 1, 'created' => $now]);
        }
      }
      $node->save();
      // Pathauto gives an address to the language saved only: the others
      // would stay at /es/node/N. A failure here leaves the post as it is.
      foreach (array_keys($node->getTranslationLanguages(FALSE)) as $langcode) {
        try {
          $this->pathauto?->updateEntityAlias($node->getTranslation($langcode), 'insert', ['language' => $langcode]);
        }
        catch (\Throwable $e) {
          $this->logger->warning('Ombú stop: no address for the @lang post: @error', ['@lang' => $langcode, '@error' => $e->getMessage()]);
        }
      }
      return (int) $node->id();
    }
    catch (\Throwable $e) {
      $this->logger->error('Ombú stop: the blog post could not be written: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * The category's term, made (and translated) the first time.
   */
  private function category(array $templates, string $default): ?int {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term') || !$this->entityTypeManager->getStorage('taxonomy_vocabulary')->load(self::VOCABULARY)) {
      return NULL;
    }
    $name = (string) (($templates[$default] ?? $templates['en'])['category'] ?? '');
    if ($name === '') {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $found = $terms->loadByProperties(['vid' => self::VOCABULARY, 'name' => $name]);
    $term = $found ? reset($found) : $terms->create(['vid' => self::VOCABULARY, 'name' => $name, 'langcode' => $default]);
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $translated = (string) ($templates[$langcode]['category'] ?? '');
      if ($translated !== '' && $langcode !== $term->language()->getId() && !$term->hasTranslation($langcode)) {
        $term->addTranslation($langcode, ['name' => $translated]);
      }
    }
    $term->save();
    return (int) $term->id();
  }

  /**
   * The text format of the body: the blog's, else the first there is.
   */
  private function format(): ?string {
    if (!$this->entityTypeManager->hasDefinition('filter_format')) {
      return NULL;
    }
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    foreach (['full_html', 'basic_html'] as $id) {
      if (isset($formats[$id])) {
        return $id;
      }
    }
    return $formats ? (string) array_key_first($formats) : NULL;
  }

}
