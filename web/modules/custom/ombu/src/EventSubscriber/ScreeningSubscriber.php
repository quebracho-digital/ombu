<?php

declare(strict_types=1);

namespace Drupal\ombu\EventSubscriber;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\ChainalysisApi;
use Quebracho\X402\Screening;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ombú's own word on a wallet, in every sale: the library's Screening.
 *
 * The x402_pay module asks OFAC's list for each payer; this adds two lists of ours
 * (`drush ombu:screen`): a wallet on "deny" buys nothing here (the sale
 * answers address_denied before anything is verified or charged), one on
 * "allow" is not asked further. With CHAINALYSIS_API_KEY in the environment
 * the rest are asked to Chainalysis' sanctions API as well; if it does not
 * answer the sale goes on, since OFAC's list was already asked locally.
 *
 * It hears the library's AddressCheck, which x402_pay's gate and Ombú's MCP
 * sale both send through the site's dispatcher.
 */
final class ScreeningSubscriber implements EventSubscriberInterface {

  /**
   * Built when first asked.
   */
  private ?Screening $screening = NULL;

  public function __construct(
    private readonly ClientInterface $http,
    private readonly Settings $settings,
    private readonly string $sitePath,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AddressCheck::class => 'check'];
  }

  /**
   * Asks our lists (and Chainalysis, when there is a key) about an address.
   */
  public function check(AddressCheck $check): void {
    ($this->screening())($check);
  }

  /**
   * The library's Screening over our lists.
   */
  public function screening(): Screening {
    if (!$this->screening) {
      $key = trim((string) getenv('CHAINALYSIS_API_KEY'));
      $api = $key !== '' ? new ChainalysisApi($this->http, new HttpFactory(), $key) : NULL;
      // An answer of Chainalysis is kept an hour, when symfony/cache is there.
      $cache = $api && class_exists(FilesystemAdapter::class) ? new FilesystemAdapter('ombu_screening', 3600, $this->dir() . '/cache') : NULL;
      $this->screening = new Screening($this->dir(), $api, $cache, 3600, Screening::ALLOW);
    }
    return $this->screening;
  }

  /**
   * Where the lists live: allow.txt and deny.txt, an address a line.
   *
   * $settings['ombu_screening_dir'], an absolute path; else under the
   * private files directory when the site has one, the public one otherwise
   * (drush ombu:screen then closes the directory to the web, on Apache).
   */
  public function dir(): string {
    $set = $this->settings->get('ombu_screening_dir');
    if (is_string($set) && $set !== '') {
      return $set;
    }
    $base = (string) ($this->settings->get('file_private_path') ?: $this->sitePath . '/files');
    if (!str_starts_with($base, '/') && defined('DRUPAL_ROOT')) {
      $base = DRUPAL_ROOT . '/' . $base;
    }
    return rtrim($base, '/') . '/ombu/screening';
  }

}
