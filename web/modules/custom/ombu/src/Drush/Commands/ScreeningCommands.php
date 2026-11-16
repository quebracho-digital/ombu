<?php

declare(strict_types=1);

namespace Drupal\ombu\Drush\Commands;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\ombu\EventSubscriber\ScreeningSubscriber;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ombú's own lists of wallets: who buys nothing here, who is not asked.
 */
final class ScreeningCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'ombu.screening_subscriber')]
    private readonly ScreeningSubscriber $screening,
  ) {
    parent::__construct();
  }

  /**
   * Lists our allow and deny lists, or puts a wallet on one (or off both).
   */
  #[CLI\Command(name: 'ombu:screen')]
  #[CLI\Argument(name: 'action', description: 'list, deny, allow, remove or check.')]
  #[CLI\Argument(name: 'address', description: 'The wallet (EVM or Solana).')]
  #[CLI\Usage(name: 'drush ombu:screen deny 0x1234…', description: 'This wallet buys nothing on Ombú from now on.')]
  #[CLI\Usage(name: 'drush ombu:screen check 0x1234…', description: 'What a sale would say of it (OFAC, our lists, Chainalysis if there is a key).')]
  public function screen(string $action = 'list', string $address = ''): void {
    $screening = $this->screening->screening();
    if ($action === 'list') {
      foreach ($screening->own() as $list => $addresses) {
        $this->io()->writeln("$list (" . count($addresses) . ')');
        foreach ($addresses as $one) {
          $this->io()->writeln("  $one");
        }
      }
      $this->io()->writeln('Lists in ' . $this->screening->dir());
      return;
    }
    if ($address === '') {
      throw new \InvalidArgumentException('Which address?');
    }
    if ($action === 'check') {
      $this->io()->writeln($screening->check($address) ?? 'clear');
      return;
    }
    if (!in_array($action, ['deny', 'allow', 'remove'], TRUE)) {
      throw new \InvalidArgumentException("Unknown action: $action (list, deny, allow, remove or check).");
    }
    $screening->set($address, $action === 'remove' ? NULL : $action);
    // The lists are nobody's business: closed to the web where the site has
    // no private files directory.
    FileSecurity::writeHtaccess($this->screening->dir());
    $this->io()->writeln($action === 'remove' ? "$address is on no list of ours." : "$address is on our $action list.");
  }

}
