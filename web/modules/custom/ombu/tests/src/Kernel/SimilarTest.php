<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Session\AccountInterface;
use Drupal\ombu\Embeddings;
use Drupal\ombu\SimilarLog;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Similar services: their scores and reasons, the markdown, the log.
 *
 * Needs MariaDB 11.7+ (VECTOR), as DDEV has.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class SimilarTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views',
    'search_api',
  ];

  /**
   * Listing ids by name.
   *
   * @var array<string, int>
   */
  private array $ids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['commerce_product', 'comment', 'ombu']);
    \Drupal::moduleHandler()->loadInclude('ombu', 'install');
    $tables = array_keys(ombu_schema());
    if (!in_array(Embeddings::TABLE, $tables, TRUE)) {
      $this->markTestSkipped('Needs MariaDB 11.7+ for VECTOR.');
    }
    $this->installSchema('ombu', $tables);
    Role::create(['id' => AccountInterface::ANONYMOUS_ROLE, 'label' => 'Anonymous'])
      ->grantPermission('view commerce_product')
      ->save();
  }

  /**
   * One provider's listings cannot crowd out the others.
   *
   * The source shares two tags with 101 listings of one provider, and one
   * with three listings of others: those score lower, below 100 of the big
   * provider's. All three are similar still, next to at most per_provider
   * (2) of the big one.
   */
  public function testOneProviderCannotFillTheCandidates(): void {
    $this->filler(5);
    $source = $this->listing('Source', 'Big', ['domains', 'big']);
    for ($i = 0; $i < 101; $i++) {
      $this->listing("Big $i", 'Big', ['domains', 'big']);
    }
    foreach (['Alpha', 'Beta', 'Gamma'] as $provider) {
      $this->listing("$provider one", $provider, ['domains']);
    }
    $ids = array_keys(\Drupal::service('ombu.similar')->scores($source));
    $names = array_map(fn (int $id) => array_search($id, $this->ids, TRUE), $ids);
    $this->assertCount(5, $ids);
    $this->assertSame(['Alpha one', 'Beta one', 'Gamma one'], array_values(array_filter($names, fn ($name) => !str_starts_with($name, 'Big'))));
  }

  /**
   * Scores fuse each list's places; the reasons say which and where.
   */
  public function testReasonsAndFusedScores(): void {
    $source = $this->listing('Source', 'One', ['domains', 'whois'], [1.0, 0.0]);
    $both = $this->listing('Both', 'Two', ['domains', 'whois'], [0.9, 0.1]);
    $terms = $this->listing('Terms only', 'Three', ['domains']);
    $text = $this->listing('Text only', 'Four', ['other'], [0.8, 0.2]);
    $this->listing('Unrelated', 'Five', ['other'], [0.0, 1.0]);
    $this->listing('Retired', 'Six', ['domains', 'whois'], [1.0, 0.0], retired: TRUE);

    $explain = \Drupal::service('ombu.similar')->explain($source);
    // Second in one list each: a tie, by id.
    $this->assertSame([$both, $terms, $text], array_slice(array_keys($explain), 0, 3));
    $this->assertNotContains($this->ids['Retired'], array_keys($explain));
    $this->assertSame(1, $explain[$both]['terms_rank']);
    $this->assertSame(1, $explain[$both]['vector_rank']);
    $this->assertEqualsWithDelta(1 / 61 + 1 / 61, $explain[$both]['score'], 1e-9);
    $this->assertNull($explain[$text]['terms_rank']);
    $this->assertEqualsWithDelta(1 / 62, $explain[$text]['score'], 1e-9);
    $this->assertEqualsWithDelta(1 / 62, $explain[$terms]['score'], 1e-9);
    $this->assertNull($explain[$terms]['vector']);

    // Without the vector weight, texts do not count.
    \Drupal::configFactory()->getEditable('ombu.similar')->set('vector', 0)->save();
    $explain = \Drupal::service('ombu.similar')->explain($source);
    $this->assertNotContains($text, array_keys($explain));
  }

  /**
   * A listing's markdown links its similar services; nothing else changes.
   */
  public function testMarkdownLinksAndGuards(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $this->listing('Other [beta] <b>`x`</b>', 'Two', ['domains']);
    $listing = Product::load($source);

    $markdown = $this->respond($listing, 'text/markdown; charset=utf-8');
    $this->assertStringContainsString("## Similar services\n\n- [Other (beta) ‹b›'x'‹/b›](", $markdown);
    $this->assertStringContainsString('ombu_from=' . $source . '&ombu_pos=0', $markdown);

    $this->assertSame("# Listing\n", $this->respond($listing, 'text/html; charset=utf-8'), 'HTML is left alone.');
    $this->assertSame("# Listing\n", $this->respond($listing, 'text/markdown; charset=utf-8', 404), 'So is an error.');
    $this->assertSame("# Listing\n", $this->respond($listing, 'text/markdown; charset=utf-8', 200, 'entity.commerce_product.edit_form'), 'And another route.');
  }

  /**
   * The log keeps what was shown and why, what was followed, and who.
   */
  public function testLog(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $other = $this->listing('Other', 'Two', ['domains']);
    $agent = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Agent/1'];
    $request = Request::create('/', 'GET', [], [], [], $agent + ['HTTP_REFERER' => 'https://example.com/page?secret=1']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $this->respond(Product::load($source), 'text/markdown; charset=utf-8');
    $follow = Request::create('/', 'GET', ['ombu_from' => $source, 'ombu_pos' => 0], [], [], $agent);
    $this->respond(Product::load($other), 'text/markdown; charset=utf-8', 200, 'entity.commerce_product.canonical', $follow);

    $rows = \Drupal::database()->select(SimilarLog::TABLE, 'l')->fields('l')->orderBy('id')->execute()->fetchAllAssoc('id', FetchAs::Associative);
    $rows = array_values($rows);
    $this->assertSame(['shown', 'follow', 'shown'], array_column($rows, 'kind'));
    $shown = json_decode($rows[0]['data'], TRUE);
    $this->assertSame($other, $shown['suggestions'][0]['id']);
    $this->assertSame(1, $shown['suggestions'][0]['reasons']['terms_rank']);
    $this->assertSame('scores', $rows[0]['engine']);
    $this->assertSame((string) $source, $rows[1]['listing_id']);
    $this->assertSame((string) $other, $rows[1]['target_id']);
    $this->assertSame('0', $rows[1]['position']);
    $this->assertSame('https://example.com/page', $rows[0]['referer'], 'The query is not kept.');
    $this->assertStringNotContainsString('203.0.113.7', implode('|', $rows[0]));
    $this->assertSame($rows[0]['visitor'], $rows[1]['visitor'], 'The same visitor within a month.');

    // A new month, a new salt: the visitor cannot be followed across months.
    \Drupal::state()->set('ombu.similar_log.salt', ['month' => '2000-01', 'value' => 'old']);
    \Drupal::service('ombu.similar_log')->event('click', $source, $other, 0, 'page');
    $last = \Drupal::database()->query('SELECT visitor FROM {' . SimilarLog::TABLE . '} ORDER BY id DESC LIMIT 1')->fetchField();
    $this->assertNotSame($rows[0]['visitor'], $last);

    // A year later, rows are gone.
    \Drupal::database()->update(SimilarLog::TABLE)->fields(['created' => \Drupal::time()->getRequestTime() - SimilarLog::KEEP - 1])->execute();
    \Drupal::service('ombu.similar_log')->purge();
    $this->assertSame(0, (int) \Drupal::database()->query('SELECT COUNT(*) FROM {' . SimilarLog::TABLE . '}')->fetchField());
  }

  /**
   * The page's reports: only parsed ones, about a listing one may view.
   */
  public function testEventReports(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $other = $this->listing('Other', 'Two', ['domains']);
    $hidden = $this->listing('Hidden', 'Three', ['domains'], published: FALSE);
    foreach ([
      'garbage',
      json_encode(['kind' => 'click', 'listing' => $hidden, 'target' => $other, 'position' => 0]),
      json_encode(['kind' => 'nonsense', 'listing' => $source]),
      json_encode([
        'kind' => 'click',
        'listing' => $source,
        'target' => $other,
        'position' => 2,
        'engine' => 'ombu_similar',
      ]),
      json_encode(['kind' => 'shown', 'listing' => $source, 'items' => [$other, 'x'], 'engine' => '<b>']),
    ] as $body) {
      $response = $this->container->get('http_kernel')->handle(Request::create('/similar/event', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
      $this->assertSame(204, $response->getStatusCode());
    }
    $rows = \Drupal::database()->query('SELECT kind, target_id, position, engine, data FROM {' . SimilarLog::TABLE . '} ORDER BY id')->fetchAll(FetchAs::Associative);
    $this->assertSame(['click', 'shown'], array_column($rows, 'kind'));
    $this->assertSame((string) $other, $rows[0]['target_id']);
    $this->assertSame('2', $rows[0]['position']);
    $this->assertSame('ombu_similar', $rows[0]['engine']);
    $this->assertSame('b', $rows[1]['engine'], 'Only a name is kept.');
    $this->assertSame([$other], array_column(json_decode($rows[1]['data'], TRUE)['suggestions'], 'id'));
  }

  /**
   * Runs the markdown subscriber on a response for a listing.
   *
   * @return string
   *   The response's content afterwards.
   */
  private function respond(Product $listing, string $type, int $status = 200, string $route = 'entity.commerce_product.canonical', ?Request $request = NULL): string {
    $request ??= Request::create('/');
    $request->attributes->set('_route', $route);
    $request->attributes->set('commerce_product', $listing);
    $response = new Response("# Listing\n", $status, ['Content-Type' => $type]);
    $this->container->get('ombu.listing_markdown_subscriber')->onResponse(new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    return (string) $response->getContent();
  }

  /**
   * A listing with these tags and, optionally, the head of a vector.
   *
   * @return int
   *   Its id.
   */
  private function listing(string $title, string $provider, array $tags, ?array $vector = NULL, bool $retired = FALSE, bool $published = TRUE): int {
    $listing = Product::create([
      'type' => 'listing',
      'title' => $title,
      'status' => $published,
      'stores' => [$this->store],
      'field_provider_term' => $this->term('listing_provider', $provider),
      'field_tags' => array_map(fn (string $tag) => $this->term('listing_tag', $tag), $tags),
      'field_source' => 'circle',
      'field_retired' => $retired,
    ]);
    $listing->save();
    if ($vector !== NULL) {
      \Drupal::database()->query('INSERT INTO {' . Embeddings::TABLE . '} (product_id, hash, embedding) VALUES (:id, :hash, VEC_FromText(:v))', [
        ':id' => $listing->id(),
        ':hash' => str_repeat('0', 64),
        ':v' => json_encode(array_pad($vector, 4096, 0.0)),
      ]);
    }
    return $this->ids[$title] = (int) $listing->id();
  }

  /**
   * Listings that share nothing, so a tag on all others is not on all.
   *
   * A tag every listing has weighs nothing (log(n / n) = 0).
   */
  private function filler(int $count): void {
    for ($i = 0; $i < $count; $i++) {
      $this->listing("Filler $i", "Filler $i", ["filler $i"]);
    }
  }

  /**
   * A term by name, created once.
   */
  private function term(string $vid, string $name): int {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $found = $storage->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $found ? reset($found) : Term::create(['vid' => $vid, 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

}
