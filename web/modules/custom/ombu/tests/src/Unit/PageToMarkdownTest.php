<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\Sellers;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The markdown seller keeps the content and drops the page around it.
 */
#[Group('ombu')]
final class PageToMarkdownTest extends UnitTestCase {

  public function testTheTitleApartAndOnlyTheContent(): void {
    $html = '<!doctype html><html><head><title>Example Domain</title><style>body{}</style></head><body>'
      . '<header><nav><a href="/">Home</a></nav></header><main><h1>Hello</h1><p>One <strong>bold</strong> line.</p></main>'
      . '<footer>© someone</footer><script>alert(1)</script></body></html>';
    $page = Sellers::pageToMarkdown($html);
    $this->assertSame('Example Domain', $page['title']);
    $this->assertSame("# Hello\n\nOne **bold** line.", $page['markdown']);
  }

  public function testTheLinksAroundTheContentAreKeptApart(): void {
    $html = '<header><a href="/">Home</a></header><main><a href="/in-content">Inside</a></main>'
      . '<footer><a href="/legal/terms">Terms of use</a> <a href="//cdn.example.com/privacy">Privacy</a>'
      . '<a href="mailto:a@example.com">Mail</a><a href="#top">Top</a><a href="/legal/terms">Terms again</a></footer>';
    $this->assertSame([
      ['text' => 'Home', 'url' => 'https://example.com/'],
      ['text' => 'Terms of use', 'url' => 'https://example.com/legal/terms'],
      ['text' => 'Privacy', 'url' => 'https://cdn.example.com/privacy'],
    ], Sellers::pageToMarkdown($html, 'https://example.com/es/home')['links']);
  }

  public function testAPageWithoutTitle(): void {
    $this->assertSame(['title' => NULL, 'markdown' => 'Just text.', 'links' => []], Sellers::pageToMarkdown('<p>Just text.</p>'));
  }

  public function testAFactCheckSaysWhenTheModelJudgedAlone(): void {
    $alone = ['score' => 1.0, 'claims' => [['claim' => 'x', 'verdict' => 'SUPPORTED', 'coverage' => []]]];
    $this->assertSame('model_only', Sellers::factcheckAnswer($alone)['evidence']);
    $sourced = ['score' => 1.0, 'claims' => [['claim' => 'x', 'verdict' => 'SUPPORTED', 'coverage' => ['sources' => 3]]]];
    $this->assertSame('web', Sellers::factcheckAnswer($sourced)['evidence']);
  }

}
