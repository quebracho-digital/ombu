<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\ombu\Share;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The share block renders each network as its mark, not as its name.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class ShareRenderTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image',
    'node', 'ai', 'ai_provider_universal', 'ai_provider_universal_factcheck',
  ];

  /**
   * The markup of the block's links, as the block passes them (ShareBlock).
   */
  private function markup(): string {
    $labels = [
      'bluesky' => 'Bluesky',
      'mastodon' => 'Mastodon',
      'x' => 'X',
      'linkedin' => 'LinkedIn',
      'whatsapp' => 'WhatsApp',
      'telegram' => 'Telegram',
      'email' => 'Email',
    ];
    $links = [];
    foreach (Share::links('https://ombu.example/market', 'Ombú market') as $name => $href) {
      $links[$name] = [
        'label' => $labels[$name] ?? $name,
        'href' => $href,
        'external' => $name !== 'email',
      ];
    }
    $build = [
      '#theme' => 'ombu_share',
      '#links' => $links,
      '#url' => 'https://ombu.example/market',
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Every network is a mark, with its name as the accessible name.
   */
  public function testEachNetworkIsItsMark(): void {
    $html = $this->markup();
    $shared = count(Share::links('https://ombu.example/market', 'Ombú market'));
    $this->assertSame($shared, substr_count($html, '<a class="ombu-share__link ombu-share__link--'));
    // One anchor per network, each holding a mark and its name as a label: no
    // network is left as a word, and none is left without its mark, which is
    // what a network added to Share without an icon here would look like.
    preg_match_all('#<a class="ombu-share__link[^"]*"[^>]*aria-label="([^"]+)"[^>]*>(.*?)</a>#s', $html, $anchors, PREG_SET_ORDER);
    $this->assertCount($shared, $anchors);
    $labels = [];
    foreach ($anchors as [, $label, $inner]) {
      $this->assertStringContainsString('<svg class="ombu-share__icon"', $inner, $label);
      $this->assertSame('', trim(strip_tags($inner)), $label);
      $labels[] = $label;
    }
    $this->assertContains('Bluesky (opens in a new tab)', $labels);
    $this->assertContains('Email', $labels);
  }

  /**
   * The copy button is a mark too, and it says what it does.
   */
  public function testTheCopyButtonShowsItsMark(): void {
    $html = $this->markup();
    preg_match('#<button[^>]*class="ombu-share__link ombu-share__copy".*?</button>#s', $html, $button);
    $this->assertNotEmpty($button, 'the copy button');
    $this->assertSame(2, substr_count($button[0], '<svg class="ombu-share__icon"'), 'copy and copied, one shown at a time');
    $this->assertStringContainsString('ombu-share__copy-icon', $button[0]);
    $this->assertStringContainsString('ombu-share__copied-icon', $button[0]);
    $this->assertStringContainsString('visually-hidden', $button[0]);
    $this->assertStringContainsString('Copy link', $button[0], 'the button still says what it does');
  }

  /**
   * The marks are the module's own, so no theme can take them away.
   */
  public function testTheMarksAreTheModulesOwn(): void {
    $this->assertSame(1, preg_match('#<svg class="ombu-share__icon"[^>]*>\s*<path#', $this->markup()));
    $this->assertStringNotContainsString('<use ', $this->markup(), 'a sprite would depend on the theme');
    $this->assertStringNotContainsString('<img ', $this->markup(), 'no request, no broken image');
  }

  /**
   * Names still travel as the accessible name: an external link says so.
   */
  public function testTheNamesAreTranslated(): void {
    $build = [
      '#theme' => 'ombu_share',
      '#links' => [
        'x' => ['label' => 'X', 'href' => 'https://x.com/intent/post?text=Hi', 'external' => TRUE],
        'email' => ['label' => 'Email', 'href' => 'mailto:?subject=Hi', 'external' => FALSE],
      ],
      '#url' => 'https://ombu.example/market',
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('aria-label="X (opens in a new tab)"', $html);
    $this->assertStringContainsString('title="Email"', $html);
    preg_match('#<a class="ombu-share__link ombu-share__link--email".*?</a>#s', $html, $email);
    $this->assertNotEmpty($email);
    $this->assertStringNotContainsString('target=', $email[0], 'email opens in the same tab');
  }

}
