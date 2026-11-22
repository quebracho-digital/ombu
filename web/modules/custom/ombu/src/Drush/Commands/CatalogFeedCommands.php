<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\ombu\Catalog\CatalogFeeds;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the catalogue feeds (Feeds) by hand: Bazaar, PayAI, Circle.
 *
 * The same counts as ombu:import-catalog and ombu:import-circle, from the
 * Feeds version of each importer; both are kept while the feeds are
 * compared with the importers. The whole import runs in this request; the
 * feed's page and Feeds' cron run it in steps instead, with the same result.
 */
final class CatalogFeedCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.catalog_feeds')]
    private readonly CatalogFeeds $feeds,
  ) {
    parent::__construct();
  }

  /**
   * Imports a catalogue through its feed and prints the counts.
   *
   * Bazaar and PayAI list only what Ombú verified itself (needs
   * ALCHEMY_API_KEY). The narrowing options are for this run only; the feed
   * type's processor holds the defaults.
   */
  #[CLI\Command(name: 'ombu:feed-import')]
  #[CLI\Argument(name: 'source', description: 'bazaar, payai or circle (or the source of another catalogue feed).')]
  #[CLI\Option(name: 'max', description: 'Stop after reading this many resources (0 = all). A partial run retires nothing.')]
  #[CLI\Option(name: 'only-tags', description: 'Comma-separated tags: import only resources carrying one of them (Bazaar, PayAI).')]
  #[CLI\Option(name: 'price-match', description: 'payTo must have received a transfer of exactly the price the listing had on that day (Bazaar, PayAI).')]
  #[CLI\Option(name: 'max-age-days', description: 'The newest incoming transfer no older than this many days, 0 = any (Bazaar, PayAI).')]
  #[CLI\Usage(name: 'drush ombu:feed-import bazaar --max=300', description: 'Read the first 300 resources of Bazaar and list the verified ones.')]
  #[CLI\Usage(name: 'drush ombu:feed-import circle', description: "A whole run of Circle's catalogue.")]
  public function import(
    string $source,
    array $options = [
      'max' => 0,
      'only-tags' => '',
      'price-match' => FALSE,
      'max-age-days' => 0,
    ],
  ): void {
    $counts = $this->feeds->import($source, [
      'max' => (int) $options['max'],
      'only_tags' => array_values(array_filter(array_map('trim', explode(',', (string) $options['only-tags'])))),
      'price_match' => $options['price-match'] ? TRUE : NULL,
      'max_age_days' => (int) $options['max-age-days'] ?: NULL,
    ]);
    $this->logger()->success(json_encode($counts, JSON_UNESCAPED_SLASHES));
  }

  /**
   * The last run of each catalogue feed, however it was started.
   */
  #[CLI\Command(name: 'ombu:feed-report')]
  #[CLI\Argument(name: 'source', description: 'One catalogue; all when left out.')]
  #[CLI\Usage(name: 'drush ombu:feed-report circle', description: "The counts of Circle's last run, from cron or by hand.")]
  public function report(string $source = ''): void {
    foreach ($source !== '' ? [$source] : array_keys(CatalogFeeds::types()) as $one) {
      $report = $this->feeds->lastReport($one);
      $this->io()->writeln($report
        ? sprintf('%s  %s  %s  %s', $one, date('Y-m-d H:i', (int) $report['time']), $report['whole'] ? 'whole' : 'partial', json_encode($report['counts'], JSON_UNESCAPED_SLASHES))
        : "$one  never");
    }
  }

}
