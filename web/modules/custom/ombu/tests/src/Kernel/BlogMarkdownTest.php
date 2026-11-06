<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The blog's list as markdown: published posts only, and only when asked.
 *
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class BlogMarkdownTest extends CommerceKernelTestBase {

  // The same set CatalogueTest needs to load ombu, plus the node type the blog uses.
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views',
    'search_api', 'search_api_db', 'facets', 'better_exposed_filters', 'facets_exposed_filters', 'language', 'mcp_server',
    'node', 'datetime', 'path', 'path_alias',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'filter']);
    $type = NodeType::create(['type' => 'blog', 'name' => 'Blog']);
    $type->save();
    // The body field the blog posts carry; NodeType::create does not add it.
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'blog', 'label' => 'Body'])->save();
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Makes a blog post with a body.
   */
  private function post(string $title, bool $published, string $summary = '', int $created = 0): void {
    $node = Node::create([
      'type' => 'blog',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'created' => $created ?: \Drupal::time()->getRequestTime(),
      'body' => ['value' => '<p>The first paragraph of ' . $title . '.</p>', 'summary' => $summary, 'format' => 'basic_html'],
    ]);
    $node->save();
  }

  /**
   * Runs the blog list request through the subscriber, as the router would.
   */
  private function listing(string $path, array $headers = []): ?string {
    $request = Request::create($path);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $request->attributes->set('_route', 'view.blog.page_1');
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('ombu.blog_markdown_subscriber')->onRequest($event);
    return $event->hasResponse() ? (string) $event->getResponse()->getContent() : NULL;
  }

  /**
   * Published posts are listed, newest first, with their summary; drafts are not.
   */
  public function testPublishedPostsAreListedAsMarkdown(): void {
    $now = \Drupal::time()->getRequestTime();
    $this->post('Older post', TRUE, 'The older summary.', $now - 3600);
    $this->post('Draft post', FALSE, '', $now - 1800);
    $this->post('Newer post', TRUE, '', $now);

    $md = $this->listing('/blog.md');
    $this->assertNotNull($md);
    $this->assertStringContainsString('# Blog', $md);
    $this->assertStringContainsString('Newer post', $md);
    $this->assertStringContainsString('Older post', $md);
    $this->assertStringContainsString('The older summary.', $md);
    $this->assertStringNotContainsString('Draft post', $md);
    $this->assertLessThan(strpos($md, 'Older post'), strpos($md, 'Newer post'), 'The newest post comes first.');
  }

  /**
   * Without .md or the markdown Accept, the list is left to the HTML page.
   */
  public function testTheHtmlListIsLeftAlone(): void {
    $this->post('Some post', TRUE);
    $this->assertNull($this->listing('/blog'));
  }

  /**
   * The Accept header asks for the same markdown.
   */
  public function testAcceptHeaderAsksForMarkdown(): void {
    $this->post('Accepted post', TRUE);
    $md = $this->listing('/blog', ['Accept' => 'text/markdown']);
    $this->assertNotNull($md);
    $this->assertStringContainsString('Accepted post', $md);
  }

}
