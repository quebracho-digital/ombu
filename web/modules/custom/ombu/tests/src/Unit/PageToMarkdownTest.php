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

  public function testAPageWithoutTitle(): void {
    $this->assertSame(['title' => NULL, 'markdown' => 'Just text.'], Sellers::pageToMarkdown('<p>Just text.</p>'));
  }

  public function testAFactCheckSaysWhenTheModelJudgedAlone(): void {
    $alone = ['score' => 1.0, 'claims' => [['claim' => 'x', 'verdict' => 'SUPPORTED', 'coverage' => []]]];
    $this->assertSame('model_only', Sellers::factcheckAnswer($alone)['evidence']);
    $sourced = ['score' => 1.0, 'claims' => [['claim' => 'x', 'verdict' => 'SUPPORTED', 'coverage' => ['sources' => 3]]]];
    $this->assertSame('web', Sellers::factcheckAnswer($sourced)['evidence']);
  }

}
