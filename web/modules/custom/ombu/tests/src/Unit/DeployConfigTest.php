<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * What an update hook creates must be what config/sync names, or cim deletes.
 *
 * Update 10036 and 10039 create config from the module's own files; the cim
 * that follows on deploy compares it with config/sync by name and UUID. A
 * different UUID is a delete and a create, and for the feed types the delete is
 * refused because their feeds exist (found by rehearsing the deploy on a copy
 * of the production database).
 */
#[Group('ombu')]
final class DeployConfigTest extends UnitTestCase {

  private const ROOT = __DIR__ . '/../../../../../../..';

  /**
   * Tests that the module's file and config/sync carry the same UUID.
   *
   * @param string $dir
   *   The module's config directory.
   * @param string $name
   *   The config name.
   */
  #[DataProvider('createdByUpdates')]
  public function testTheUuidMatchesConfigSync(string $dir, string $name): void {
    $module = Yaml::parseFile(__DIR__ . "/../../../config/$dir/$name.yml");
    $sync = Yaml::parseFile(self::ROOT . "/config/sync/$name.yml");
    $this->assertNotEmpty($module['uuid'] ?? NULL, "$name has no uuid in the module: an update would give it a new one.");
    $this->assertSame($sync['uuid'], $module['uuid']);
  }

  /**
   * The config the updates create from the module's files.
   *
   * @return array<string, array{string, string}>
   *   Named by config.
   */
  public static function createdByUpdates(): array {
    $names = [
      'install' => ['field.storage.commerce_product.field_a2ui', 'field.field.commerce_product.listing.field_a2ui'],
      'optional' => ['feeds.feed_type.ombu_bazaar', 'feeds.feed_type.ombu_payai', 'feeds.feed_type.ombu_circle'],
    ];
    $cases = [];
    foreach ($names as $dir => $list) {
      foreach ($list as $name) {
        $cases[$name] = [$dir, $name];
      }
    }
    return $cases;
  }

  /**
   * Tests that config/sync carries Feeds and what it installs.
   */
  public function testConfigSyncCarriesFeeds(): void {
    $extension = Yaml::parseFile(self::ROOT . '/config/sync/core.extension.yml');
    $this->assertArrayHasKey('feeds', $extension['module']);
    foreach (['feeds.settings', 'views.view.feeds_feed', 'system.action.feeds_feed_import_action'] as $name) {
      $this->assertFileExists(self::ROOT . "/config/sync/$name.yml");
    }
  }

}
