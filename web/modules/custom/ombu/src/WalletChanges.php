<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The listings retired because their seller answers with another wallet.
 *
 * The importers note each one in the state; the panel lists them, the form
 * accepts one, and drush ombu:wallet-changes does both. Accepting asks the
 * endpoint again (DiscoveryCatalogImporter::acceptWallet): only a pass
 * replaces the wallet on file.
 */
class WalletChanges {

  /**
   * The catalogues whose importers can note a changed wallet.
   */
  public const SOURCES = ['bazaar', 'payai'];

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Every listing waiting for review, from every source.
   *
   * @return array<int, array<string, mixed>>
   *   The changes, by listing id: id, source, url, old, new and time.
   */
  public function waiting(): array {
    $changes = [];
    foreach (self::SOURCES as $source) {
      $noted = $this->state->get("ombu.catalog.wallet_changed.$source", []);
      if (!is_array($noted)) {
        continue;
      }
      foreach ($noted as $id => $change) {
        if (!is_array($change)) {
          continue;
        }
        $changes[(int) $id] = [
          'id' => (int) $id,
          'source' => $source,
          'url' => (string) ($change['url'] ?? ''),
          'old' => array_values(array_filter((array) ($change['old'] ?? []), 'is_string')),
          'new' => array_values(array_filter((array) ($change['new'] ?? []), 'is_string')),
          'time' => (int) ($change['time'] ?? 0),
        ];
      }
    }
    return $changes;
  }

  /**
   * One listing waiting for review, or NULL.
   *
   * @return array<string, mixed>|null
   *   The change: id, source, url, old, new and time.
   */
  public function change(int $id): ?array {
    return $this->waiting()[$id] ?? NULL;
  }

  /**
   * Accepts the wallet a listing's seller answers with now.
   *
   * @param int $id
   *   The listing id.
   * @param string|null $source
   *   The catalogue the command was told to use; NULL to take the listing's
   *   own source (the panel does that: the listing names it).
   *
   * @return array
   *   The verifier's report; its verdict says whether the wallet was taken.
   *
   * @throws \InvalidArgumentException
   *   When there is no such listing, or it is not from the source asked for.
   * @throws \RuntimeException
   *   When the source has no importer that can accept a wallet.
   */
  public function accept(int $id, ?string $source = NULL): array {
    $product = $this->entityTypes->getStorage('commerce_product')->load($id);
    if (!$product instanceof ProductInterface) {
      throw new \InvalidArgumentException("No listing $id.");
    }
    $actual = (string) $product->get('field_source')->value;
    $source ??= $actual;
    if (!in_array($source, self::SOURCES, TRUE)) {
      throw new \InvalidArgumentException("Listing $id is not from a source with wallet changes.");
    }
    if ($actual !== $source) {
      throw new \InvalidArgumentException("Listing $id is not from $source.");
    }
    $importer = $this->container->get("ombu.{$source}_importer");
    if (!is_object($importer) || !method_exists($importer, 'acceptWallet')) {
      throw new \RuntimeException("No wallet-change importer for $source.");
    }
    return $importer->acceptWallet($product);
  }

}
