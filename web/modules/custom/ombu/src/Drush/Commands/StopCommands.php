<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\ombu\Stop;
use Drupal\x402_pay\Stop as PaymentsStop;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The stop switch from the command line.
 *
 * `drush x402:stop` pauses payments alone; this one pauses payments and then
 * puts the whole site in read-only mode, and writes the blog post.
 */
final class StopCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.stop')]
    private readonly Stop $stop,
    #[Autowire(service: 'x402_pay.stop')]
    private readonly PaymentsStop $payments,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Stops or resumes Ombú: payments first, then the site read-only.
   */
  #[CLI\Command(name: 'ombu:stop')]
  #[CLI\Argument(name: 'action', description: 'on, off or status.')]
  #[CLI\Argument(name: 'why', description: 'The reason, in English. Public: it goes into the blog post.')]
  #[CLI\Option(name: 'es', description: 'The reason in Spanish, for the post.')]
  #[CLI\Option(name: 'pt-br', description: 'The reason in Brazilian Portuguese, for the post.')]
  #[CLI\Usage(name: 'drush ombu:stop on "Rotating a key" --es="Rotación de una clave"', description: 'Pause payments, make the site read-only and publish the post.')]
  #[CLI\Usage(name: 'drush ombu:stop off', description: 'Bring the site back, then payments, and publish the post.')]
  #[CLI\Usage(name: 'drush ombu:stop status', description: 'Say how it stands; exits 1 while the site is stopped.')]
  public function stop(string $action = 'status', string $why = '', array $options = ['es' => '', 'pt-br' => '']): int {
    $reasons = array_filter(['es' => (string) $options['es'], 'pt-br' => (string) $options['pt-br']]);
    if ($action === 'status') {
      $note = $this->stop->note();
      $layers = $this->payments->layers();
      $this->io()->writeln($this->stop->engaged()
        ? 'Site: STOPPED (read-only)' . ($note ? ' since ' . $this->dateFormatter->format($note['time'], 'custom', 'Y-m-d H:i T') . ($note['why'] !== '' ? ": {$note['why']}" : '') : '')
        : 'Site: running');
      $this->io()->writeln('Payments: ' . ($this->payments->engaged() ? 'PAUSED' : 'running')
        . ' (library ' . ($layers['library'] ? 'on' : 'off') . ', module ' . ($layers['module'] ? 'on' : 'off') . ')');
      return $this->stop->engaged() ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
    }
    if (!in_array($action, ['on', 'off'], TRUE)) {
      throw new \InvalidArgumentException("Unknown action: $action (on, off or status).");
    }
    $done = $action === 'on' ? $this->stop->engage($why, $reasons) : $this->stop->release($why, $reasons);
    $failed = array_filter($done['payments'] + ['site' => $done['site']]);
    foreach ($failed as $switch => $error) {
      $this->logger()->error("$switch: $error");
    }
    $this->io()->writeln($action === 'on' ? 'Ombú is stopped: payments paused, site read-only.' : 'Ombú is running again.');
    if ($done['post']) {
      $this->io()->writeln("Blog post: node/{$done['post']}");
    }
    return $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
