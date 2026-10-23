<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
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
    $this->pushRequest(Request::create('/', 'GET', [], [], [], $agent + ['HTTP_REFERER' => 'https://user:pw@example.com/page?secret=1#top']));

    $this->respond(Product::load($source), 'text/markdown; charset=utf-8');
    $this->respond(Product::load($source), 'text/markdown; charset=utf-8');
    $follow = Request::create('/', 'GET', ['ombu_from' => (string) $source, 'ombu_pos' => '0'], [], [], $agent);
    $this->respond(Product::load($other), 'text/markdown; charset=utf-8', 200, 'entity.commerce_product.canonical', $follow);

    $rows = $this->rows();
    $this->assertSame(['shown', 'follow', 'shown'], array_column($rows, 'kind'), 'The same block, read twice in an hour, counts once.');
    $shown = json_decode($rows[0]['data'], TRUE);
    $this->assertSame($other, $shown['suggestions'][0]['id']);
    $this->assertSame(1, $shown['suggestions'][0]['reasons']['terms_rank']);
    $this->assertSame('scores', $rows[0]['engine']);
    $this->assertSame((string) $source, $rows[1]['listing_id']);
    $this->assertSame((string) $other, $rows[1]['target_id']);
    $this->assertSame('0', $rows[1]['position']);
    $this->assertSame('https://example.com/page', $rows[0]['referer'], 'No user, query or fragment.');
    $this->assertSame('0', $rows[0]['authenticated']);
    $this->assertStringNotContainsString('203.0.113.7', implode('|', $rows[0]));
    $month = date('Y-m', \Drupal::time()->getRequestTime());
    $expected = hash_hmac('sha256', "$month|203.0.113.7|Agent/1", Settings::getHashSalt());
    $this->assertSame($expected, $rows[0]['visitor'], 'Keyed with hash_salt, bound to the month.');

    // A year later, rows are gone.
    \Drupal::database()->update(SimilarLog::TABLE)->fields(['created' => \Drupal::time()->getRequestTime() - SimilarLog::KEEP - 1])->execute();
    \Drupal::service('ombu.similar_log')->purge();
    $this->assertSame([], $this->rows());
  }

  /**
   * Hostile links neither break the page nor reach the log.
   */
  public function testHostileValuesAreDropped(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $this->listing('Other', 'Two', ['domains']);
    $this->pushRequest(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.8']));
    $links = [
      ['99999999999', '0'],
      ['-5', '0'],
      [(string) $source, '99999999999999'],
      [(string) $source, 'x'],
    ];
    foreach ($links as [$from, $position]) {
      $request = Request::create('/', 'GET', ['ombu_from' => $from, 'ombu_pos' => $position]);
      $markdown = $this->respond(Product::load($source), 'text/markdown; charset=utf-8', 200, 'entity.commerce_product.canonical', $request);
      $this->assertStringContainsString('## Similar services', $markdown);
    }
    $follows = array_filter($this->rows(), fn ($row) => $row['kind'] === 'follow');
    $this->assertCount(1, $follows, 'Only the follow with a real listing, its place clamped.');
    $this->assertSame((string) (2 * 12), current($follows)['position']);
  }

  /**
   * A visitor sending Global Privacy Control is not logged.
   */
  public function testGlobalPrivacyControl(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $this->listing('Other', 'Two', ['domains']);
    $request = Request::create('/', 'GET', [], [], [], ['HTTP_SEC_GPC' => '1']);
    $this->pushRequest($request);
    $this->respond(Product::load($source), 'text/markdown; charset=utf-8', 200, 'entity.commerce_product.canonical', $request);
    $this->assertSame([], $this->rows());
  }

  /**
   * The page's reports: only well-formed ones, about listings one may view.
   */
  public function testEventReports(): void {
    $this->filler(1);
    $source = $this->listing('Source', 'One', ['domains']);
    $other = $this->listing('Other', 'Two', ['domains']);
    $hidden = $this->listing('Hidden', 'Three', ['domains'], published: FALSE);
    $click = ['kind' => 'click', 'listing' => $source, 'target' => $other, 'position' => 2, 'engine' => 'ombu_similar'];
    foreach ([
      'garbage',
      json_encode(['listing' => $hidden] + $click),
      json_encode(['kind' => 'nonsense'] + $click),
      json_encode(['engine' => '<b>'] + $click),
      json_encode(['target' => 99999999999] + $click),
      json_encode($click),
      json_encode([
        'kind' => 'shown',
        'listing' => $source,
        'items' => [$other, 'x', [1], -3, 99999999999, $hidden],
        'engine' => 'scores',
      ]),
    ] as $body) {
      $this->assertSame(204, $this->report($body)->getStatusCode());
    }
    $this->assertSame(204, $this->report(json_encode($click + ['position' => 9]), 'text/plain')->getStatusCode());

    $rows = $this->rows();
    $this->assertSame(['click', 'shown'], array_column($rows, 'kind'));
    $this->assertSame((string) $other, $rows[0]['target_id']);
    $this->assertSame('2', $rows[0]['position']);
    $this->assertSame('ombu_similar', $rows[0]['engine']);
    $this->assertSame([$other], array_column(json_decode($rows[1]['data'], TRUE)['suggestions'], 'id'), 'Only published listings.');
  }

  /**
   * Over the flood limit, a visitor's reports are refused.
   */
  public function testReportsAreCapped(): void {
    $status = [];
    for ($i = 0; $i < 121; $i++) {
      $status[] = $this->report('{}')->getStatusCode();
    }
    $this->assertSame(204, $status[119]);
    $this->assertSame(429, $status[120]);
  }

  /**
   * Posts a report to /similar/event.
   */
  private function report(string $body, string $type = 'application/json'): Response {
    return $this->container->get('http_kernel')->handle(Request::create('/similar/event', 'POST', [], [], [], ['CONTENT_TYPE' => $type], $body));
  }

  /**
   * The log's rows, oldest first.
   */
  private function rows(): array {
    $query = \Drupal::database()->select(SimilarLog::TABLE, 'l')->fields('l')->orderBy('id');
    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Makes a request the current one, with a session.
   */
  private function pushRequest(Request $request): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
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
