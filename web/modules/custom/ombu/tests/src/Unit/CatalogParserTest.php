<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\DiscoveryCatalogImporter;
use Drupal\ombu\Feeds\Parser\CircleParser;
use Drupal\ombu\Feeds\Parser\DiscoveryParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The feeds' parsers read a catalogue entry as the importers did.
 */
#[Group('ombu')]
final class CatalogParserTest extends UnitTestCase {

  private const ACCEPT = [
    'scheme' => 'exact',
    'network' => 'eip155:8453',
    'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
    'payTo' => '0x589a2314a2E05F45e40C4823Da3Ba58D421dB3d8',
    'amount' => '5000',
  ];

  /**
   * Bazaar and PayAI entries, good and bad.
   */
  public static function discoveryEntries(): array {
    return [
      'bazaar' => [[
        'resource' => 'https://seller.example/q',
        'description' => 'Quote',
        'serviceName' => 'Seller',
        'tags' => ['quote', ' pricing ', ['not', 'scalar'], str_repeat('x', 60)],
        'lastUpdated' => '2026-11-08T20:00:00Z',
        'quality' => ['l30DaysTotalCalls' => 10],
        'accepts' => [self::ACCEPT, ['scheme' => 'exact']],
        'extensions' => [
          'bazaar' => [
            'info' => [
              'input' => ['type' => 'http', 'method' => 'post'],
              'output' => ['type' => 'json'],
            ],
          ],
        ],
      ],
      ],
      'payai' => [[
        'resource' => 'https://pay.example/chat',
        'method' => 'POST',
        'description' => '',
        'serviceName' => NULL,
        'tags' => NULL,
        'accepts' => [['maxAmountRequired' => '10'] + self::ACCEPT],
        'inputSchema' => ['body' => ['model' => 'x']],
      ],
      ],
      'http only' => [['resource' => 'http://plain.example/x', 'accepts' => [self::ACCEPT]]],
      'no requirement' => [['resource' => 'https://x.example/y', 'accepts' => []]],
      'no resource' => [['accepts' => [self::ACCEPT]]],
      'resource not a string' => [['resource' => ['https://x.example'], 'accepts' => [self::ACCEPT]]],
    ];
  }

  /**
   * The Discovery parser's normalize() is the importer's, entry by entry.
   */
  #[DataProvider('discoveryEntries')]
  public function testDiscoveryParserReadsAsTheImporter(array $entry): void {
    $this->assertSame(DiscoveryCatalogImporter::normalize($entry), DiscoveryParser::normalize($entry));
  }

  /**
   * What the parser keeps of a Bazaar entry.
   */
  public function testDiscoveryShape(): void {
    $normal = DiscoveryParser::normalize(self::discoveryEntries()['bazaar'][0]);
    $this->assertSame('POST https://seller.example/q', $normal['key']);
    $this->assertSame(['quote', 'pricing', str_repeat('x', 40)], $normal['tags']);
    $this->assertCount(1, $normal['accepts'], 'An incomplete requirement is dropped.');
    $this->assertStringNotContainsString('quality', $normal['hash'], 'Volatile counters are not part of the hash.');
  }

  /**
   * Circle: the key, every complete requirement, the exact ones, the entry.
   */
  public function testCircleShape(): void {
    $entry = [
      'resource' => 'https://api.example.com/v1/weather',
      'accepts' => [
        self::ACCEPT,
        ['scheme' => 'upto', 'amount' => '1'] + self::ACCEPT,
        ['scheme' => 'exact'],
      ],
      'metadata' => ['method' => 'get'],
    ];
    $normal = CircleParser::normalize($entry);
    $this->assertSame('GET https://api.example.com/v1/weather', $normal['key']);
    $this->assertCount(2, $normal['accepts']);
    $this->assertSame([self::ACCEPT], $normal['exact']);
    $this->assertSame($entry, $normal['item'], 'The entry goes on whole: the processor hashes it as the importer did.');
  }

  /**
   * Circle: no resource or no exact requirement is not a listing.
   */
  public function testCircleSkips(): void {
    $this->assertNull(CircleParser::normalize(['accepts' => [self::ACCEPT]]));
    $upto = ['scheme' => 'upto'] + self::ACCEPT;
    $this->assertNull(CircleParser::normalize(['resource' => 'https://x.example', 'accepts' => [$upto]]));
    $this->assertNull(CircleParser::normalize(['resource' => 'https://x.example', 'accepts' => 'not a list']));
  }

  /**
   * A malformed discovery entry is refused, not thrown.
   */
  public function testDiscoveryRefusesMalformedEntries(): void {
    $entries = [
      ['resource' => 'https://x.example/a', 'accepts' => 'not-an-array'],
      ['resource' => 'http://x.example/a', 'accepts' => []],
      [
        'resource' => 'https://x.example/a',
        'accepts' => [['asset' => 'x']],
        'extensions' => 'not-an-array',
      ],
    ];
    foreach ($entries as $i => $entry) {
      $this->assertNull(DiscoveryParser::normalize($entry), "entry $i");
    }
  }

  /**
   * A malformed Circle entry is refused, not thrown.
   */
  public function testCircleRefusesMalformedEntries(): void {
    $https = 'https://x.example/a';
    $exact = ['scheme' => 'exact', 'amount' => '1', 'asset' => 'x'];
    $accepts = [
      // Not https.
      ['http://x.example/a', $exact],
      // An amount that is not digits.
      [$https, ['amount' => 'abc'] + $exact],
      [$https, ['amount' => '-1'] + $exact],
      // A network nobody knows.
      [$https, $exact + ['network' => 'not-a-chain', 'payTo' => '0xabc']],
      // No asset.
      [$https, ['scheme' => 'exact', 'amount' => '1', 'network' => 'base', 'payTo' => '0xabc']],
    ];
    foreach ($accepts as $i => [$resource, $accept]) {
      $entry = ['resource' => $resource, 'accepts' => [$accept]];
      $this->assertNull(CircleParser::normalize($entry), "entry $i");
    }
  }

  /**
   * A Circle entry of x402 v1 is read: its amount is `maxAmountRequired`.
   */
  public function testCircleReadsTheV1Amount(): void {
    $normal = CircleParser::normalize([
      'resource' => 'https://x.example/a',
      'accepts' => [[
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'maxAmountRequired' => '2500',
        'payTo' => '0xabc',
        'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
      ],
      ],
    ]);
    $this->assertNotNull($normal);
    $this->assertSame('2500', $normal['exact'][0]['amount']);
  }

}
