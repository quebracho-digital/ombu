<?php

declare(strict_types=1);

namespace Drupal\ombu\Catalog;

use Drupal\feeds\FeedInterface;
use Drupal\feeds\State;

/**
 * One run of a catalogue feed: what every stage of it needs to share.
 *
 * Feeds keeps a state per stage (fetch, parse, process, clean) and wipes them
 * all when an import begins; this is one more of those states, so it lives as
 * long as one run, is saved after every step with the others (a batch or a
 * cron queue spans many requests), and is gone when the next run starts.
 *
 * It is small on purpose: Feeds saves every state after every item. The keys
 * already read in this run (to read a resource listed twice once) are kept
 * apart, in the key-value store, and written once per page.
 *
 * The rule it carries: only a whole run retires. Whole means the fetcher
 * read the last page, no `max` cut it short, no step failed and every item
 * that was parsed was processed; and the guards agree (the catalogue did not
 * come back empty nor with less than half of what the last whole run read).
 */
#[\AllowDynamicProperties]
final class CatalogRun extends State {

  /**
   * The name of this state among the feed's states.
   */
  public const STAGE = 'ombu_run';

  /**
   * The key-value collection of the run's keys, options and last report.
   */
  public const STORE = 'ombu.catalog_feeds';

  /**
   * Whether the run has begun (the first page was asked for).
   */
  public bool $started = FALSE;

  /**
   * This run's options: max, only_tags, price_match, max_age_days.
   */
  public array $options = [];

  /**
   * The count of each outcome.
   *
   * @var array<string, int>
   */
  public array $counts = [];

  /**
   * The tally of the reasons for rejections.
   *
   * @var array<string, int>
   */
  public array $reasons = [];

  /**
   * Distinct resources read (the guards compare it with the last run).
   */
  public int $read = 0;

  /**
   * Items the parser handed to the processor.
   */
  public int $queued = 0;

  /**
   * Items the processor finished with, whatever the outcome.
   */
  public int $handled = 0;

  /**
   * Resources verified so far (the budget).
   */
  public int $verified = 0;

  /**
   * The fetcher read the last page.
   */
  public bool $complete = FALSE;

  /**
   * A `max` stopped the run before the end of the catalogue.
   */
  public bool $partial = FALSE;

  /**
   * The first error of the run, if any: a run with one retires nothing.
   */
  public ?string $error = NULL;

  /**
   * Whether the guards let this run retire: NULL until the last page.
   */
  public ?bool $retireAllowed = NULL;

  /**
   * Why the guards kept this run from retiring.
   */
  public ?string $retireSkipped = NULL;

  /**
   * Ids of other sources' listings found in this catalogue (Circle: ours).
   *
   * @var array<int, true>
   */
  public array $found = [];

  /**
   * The run of a feed, begun or not.
   */
  public static function of(FeedInterface $feed): self {
    $run = $feed->getState(self::STAGE);
    if (!$run instanceof self) {
      $run = self::create(\Drupal::getContainer(), (int) $feed->id());
      $feed->setState(self::STAGE, $run);
    }
    return $run;
  }

  /**
   * Counts one outcome.
   */
  public function count(string $outcome, int $n = 1): void {
    $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + $n;
  }

  /**
   * Distinct reasons one run keeps: past them the rest go to `other`.
   */
  public const REASONS = 20;

  /**
   * Counts one reason for a rejection.
   *
   * Capped, so a catalogue of poisoned entries cannot grow the report
   * without bound.
   */
  public function reason(string $reason): void {
    if (!isset($this->reasons[$reason]) && count($this->reasons) >= self::REASONS) {
      $reason = 'other';
    }
    $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
  }

  /**
   * Notes the first error; later ones are only logged by whoever saw them.
   */
  public function fail(string $message): void {
    $this->error ??= $message;
  }

  /**
   * Whether this run may retire what it did not keep, now.
   *
   * Asked by the processor before each retirement, so an error that comes
   * after the guards agreed still stops it.
   */
  public function mayRetire(): bool {
    return $this->retireAllowed === TRUE && $this->whole();
  }

  /**
   * Read to the end, not cut short, nothing failed, every item processed.
   */
  public function whole(): bool {
    return $this->complete && !$this->partial && $this->error === NULL && $this->handled >= $this->queued;
  }

  /**
   * The counts as the importers returned them, with the reasons last.
   *
   * @param string[] $outcomes
   *   The outcomes this catalogue reports, in order, each at least 0.
   * @param bool $withReasons
   *   Whether the catalogue tallies reasons (Discovery does, Circle does not).
   */
  public function summary(array $outcomes, bool $withReasons = TRUE): array {
    $report = array_fill_keys($outcomes, 0);
    foreach ($this->counts as $outcome => $n) {
      $report[$outcome] = ($report[$outcome] ?? 0) + $n;
    }
    if ($this->retireSkipped !== NULL) {
      $report['retire_skipped'] = $this->retireSkipped;
    }
    if ($this->error !== NULL) {
      $report['error'] = $this->error;
    }
    if ($withReasons) {
      $reasons = $this->reasons;
      arsort($reasons);
      $report['reasons'] = $reasons;
    }
    return $report;
  }

}
