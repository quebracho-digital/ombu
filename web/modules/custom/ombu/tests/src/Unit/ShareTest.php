<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\Share;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\ombu\Share
 * @group ombu
 */
class ShareTest extends UnitTestCase {

  /**
   * @covers ::marketQuery
   */
  public function testOnlyTheMarketsFiltersSurviveInAStableOrder(): void {
    $query = Share::marketQuery([
      'utm_source' => 'newsletter',
      'page' => '3',
      'tag' => 'weather',
      'search' => '  price feed ',
      'network' => ['eip155:8453', 'eip155:5042'],
      'category' => '',
      'seller' => [],
      'f' => ['x' => 'y'],
    ]);
    $this->assertSame(['network' => ['eip155:8453', 'eip155:5042'], 'search' => 'price feed', 'tag' => 'weather'], $query);
  }

  /**
   * @covers ::marketQuery
   */
  public function testAFilterValueIsNeverAnArrayOfArraysNorTooLong(): void {
    $query = Share::marketQuery(['search' => str_repeat('a', 500), 'tag' => [['nested']], 'provider' => ['ok', ['nested']]]);
    $this->assertSame(200, mb_strlen($query['search']));
    $this->assertArrayNotHasKey('tag', $query);
    $this->assertSame(['ok'], $query['provider']);
  }

  /**
   * @covers ::links
   */
  public function testTheAddressTravelsEncodedInsideEachIntent(): void {
    $url = 'https://ombu.example/market?search=a%26b&tag=x';
    $links = Share::links($url, 'Ombú & friends');
    $this->assertSame(['bluesky', 'mastodon', 'x', 'linkedin', 'whatsapp', 'telegram', 'email'], array_keys($links));
    foreach ($links as $name => $href) {
      $this->assertStringStartsWith($name === 'email' ? 'mailto:' : 'https://', $href);
      $this->assertStringContainsString(rawurlencode($url), $href, $name);
      // The text is encoded too: its "&" cannot start a parameter. LinkedIn
      // takes the address alone.
      $this->assertStringNotContainsString('Ombú', $href, $name);
      if ($name !== 'linkedin') {
        $this->assertStringContainsString(rawurlencode('Ombú & friends'), $href, $name);
      }
    }
    $this->assertStringContainsString('subject=' . rawurlencode('Ombú & friends'), $links['email']);
  }

  /**
   * @covers ::links
   */
  public function testALinkOnlyReachesTheNetworksHost(): void {
    $links = Share::links('https://ombu.example/blog/x', 'Title');
    $hosts = array_map(fn($h) => parse_url($h, PHP_URL_HOST), array_diff_key($links, ['email' => 1]));
    $this->assertSame(['bsky.app', 'mastodon.social', 'x.com', 'www.linkedin.com', 'wa.me', 't.me'], array_values($hosts));
  }

  /**
   * @covers ::description
   */
  public function testADescriptionIsTextOnOneLineCutAtAWord(): void {
    $this->assertSame('Hello & welcome to Ombú', Share::description("<p>Hello &amp; <b>welcome</b>\n to   Ombú</p><script>x()</script>"));
    $long = str_repeat('word ', 100);
    $description = Share::description($long, 50);
    $this->assertLessThanOrEqual(51, mb_strlen($description));
    $this->assertStringEndsWith('word…', $description);
  }

}
