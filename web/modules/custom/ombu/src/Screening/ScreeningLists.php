<?php

declare(strict_types=1);

namespace Drupal\ombu\Screening;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ombu\EventSubscriber\ScreeningSubscriber;
use Drupal\x402_pay\Plugin\Field\FieldType\X402PriceItem;
use Psr\Log\LoggerInterface;
use Quebracho\X402\AddressList;

/**
 * Ombú's own allow and deny lists, read and changed from the web or drush.
 *
 * The lists themselves are the library's Screening (allow.txt and deny.txt);
 * this service adds what the panel and the command share: the EVM or Solana
 * check, closing the directory to the web and the line in the log.
 */
class ScreeningLists {

  /**
   * The lists, in the order the command shows them.
   */
  private const LISTS = ['allow', 'deny'];

  public function __construct(
    private readonly ScreeningSubscriber $screening,
    private readonly LoggerInterface $logger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * The allow and deny lists, each address a line.
   *
   * @return array{allow: string[], deny: string[]}
   *   The addresses, normalized: EVM ones in lower case.
   */
  public function lists(): array {
    return $this->screening->screening()->own();
  }

  /**
   * Where the list files live.
   */
  public function dir(): string {
    return $this->screening->dir();
  }

  /**
   * Why an address would be refused, or NULL: OFAC, our lists, Chainalysis.
   */
  public function check(string $address): ?string {
    return $this->screening->screening()->check($this->validate($address));
  }

  /**
   * Blocks an address: from now on it buys nothing on Ombú.
   */
  public function deny(string $address): string {
    return $this->place($address, 'deny');
  }

  /**
   * Puts an address on the allow list; it skips the rest of the checks.
   */
  public function allow(string $address): string {
    return $this->place($address, 'allow');
  }

  /**
   * Takes an address off both lists.
   */
  public function remove(string $address): string {
    return $this->place($address, NULL);
  }

  /**
   * Puts an address on one list, or on neither, and logs who did it.
   *
   * @param string $address
   *   The wallet: EVM (0x and 40 hex digits) or Solana (base58).
   * @param string|null $list
   *   "allow", "deny", or NULL to remove it from both.
   *
   * @return string
   *   The address as stored.
   *
   * @throws \InvalidArgumentException
   *   When the address is not an EVM or Solana one, or the list is unknown.
   * @throws \RuntimeException
   *   When the lists cannot be written.
   */
  public function place(string $address, ?string $list): string {
    $address = $this->validate($address);
    if ($list !== NULL && !in_array($list, self::LISTS, TRUE)) {
      throw new \InvalidArgumentException("No such list: $list.");
    }
    $this->screening->screening()->set($address, $list);
    // The lists are nobody's business: closed to the web where the site has
    // no private files directory.
    FileSecurity::writeHtaccess($this->screening->dir());
    $what = match ($list) {
      'allow' => 'put on the allow list',
      'deny' => 'put on the deny list',
      default => 'removed from the screening lists',
    };
    $this->logger->notice('Wallet @address @what by @user (uid @uid).', [
      '@address' => $address,
      '@what' => $what,
      '@user' => $this->userName(),
      '@uid' => (int) $this->currentUser->id(),
    ]);
    return $address;
  }

  /**
   * The address as stored, or NULL when it is neither EVM nor Solana.
   */
  public function normalize(string $address): ?string {
    // AddressList::parse takes the first token and lowercases an EVM address,
    // as the command and the library do; the lists then compare exactly.
    $parsed = AddressList::parse($address)[0] ?? '';
    if ($parsed !== '' && (preg_match('/^0x[0-9a-f]{40}$/', $parsed) || X402PriceItem::isSolanaAddress($parsed))) {
      return $parsed;
    }
    return NULL;
  }

  /**
   * The address, or an exception.
   */
  private function validate(string $address): string {
    return $this->normalize($address) ?? throw new \InvalidArgumentException("Not an EVM or Solana address: $address.");
  }

  /**
   * Who acts: the account name on the web, drush from the command line.
   */
  private function userName(): string {
    return $this->currentUser->isAuthenticated() ? $this->currentUser->getAccountName() : 'drush';
  }

}
