<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\x402_pay\Event\StopEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The stop switch: payments first, then the whole site read-only.
 */
#[Group('ombu')]
#[RunTestsInSeparateProcesses]
final class StopTest extends CommerceKernelTestBase {

  private const EVM = '0x1234567890abcdef1234567890ABCDEF12345678';

  private const SOLANA = '6PJNCqXycHy7qsPPsz1wj9REkZxnJQFutEgfKqyZpuGU';

  /**
   * The library's flag file, for this test only.
   *
   * @var string
   */
  private string $flag;

  /**
   * The set BlogMarkdownTest needs to load ombu and make posts.
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_cart', 'commerce_number_pattern',
    'state_machine', 'profile', 'entity_reference_revisions', 'comment', 'taxonomy',
    'link', 'text', 'filter', 'options', 'key', 'x402_pay', 'ombu', 'file', 'image', 'views',
    'search_api', 'search_api_db', 'facets', 'better_exposed_filters', 'facets_exposed_filters', 'language', 'mcp_server',
    'node', 'datetime', 'path', 'path_alias',
  ];

  /**
   * Set up.
   */
  protected function setUp(): void {
    parent::setUp();
    // A real file of this test's own: x402_pay's default lands under the web
    // root when the site lives in vfs.
    $this->flag = sys_get_temp_dir() . '/ombu-stop-' . bin2hex(random_bytes(4)) . '/paused';
    $this->setSetting('x402_pay_stop_file', $this->flag);
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'filter']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    ConfigurableLanguage::createFromLangcode('pt-br')->save();
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'blog', 'label' => 'Body'])->save();
    Vocabulary::create(['vid' => 'blog_category', 'name' => 'Blog category'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_blog_category',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create(['field_name' => 'field_blog_category', 'entity_type' => 'node', 'bundle' => 'blog', 'label' => 'Category'])->save();
    $this->config('ombu.stop')
      ->setData(Yaml::decode(file_get_contents(__DIR__ . '/../../../config/install/ombu.stop.yml')))
      ->save();
  }

  /**
   * Tear down.
   */
  protected function tearDown(): void {
    @unlink($this->flag);
    @rmdir(dirname($this->flag));
    parent::tearDown();
  }

  /**
   * Pausing sets the payments' switch first, then the site's; resuming, back.
   */
  public function testPaymentsArePausedBeforeTheSiteAndResumedAfter(): void {
    $stop = $this->container->get('ombu.stop');
    $payments = $this->container->get('x402_pay.stop');
    $seen = [];
    $this->container->get('event_dispatcher')->addListener(StopEvent::class, function (StopEvent $event) use (&$seen, $stop) {
      $seen[] = [$event->pausing, $event->layers['library'], $stop->engaged()];
    });

    $this->assertFalse($stop->engaged());
    $stop->engage('Key rotation');
    $this->assertTrue($payments->engaged());
    $this->assertTrue($payments->layers()['library'], 'The library is paused.');
    $this->assertTrue($stop->engaged());
    $this->assertSame('Key rotation', $stop->note()['why']);
    $this->assertSame([TRUE, TRUE, FALSE], $seen[0], 'When the library paused, the site was not read-only yet.');

    $stop->release();
    $this->assertFalse($stop->engaged());
    $this->assertFalse($payments->engaged());
    $this->assertSame([FALSE, FALSE, FALSE], $seen[1], 'The site was writable again before payments resumed.');
  }

  /**
   * Pausing payments alone (drush x402:stop) leaves the site writable.
   */
  public function testPausingPaymentsAloneIsNotAStopOfTheSite(): void {
    $this->container->get('x402_pay.stop')->engage('only payments');
    $this->assertFalse($this->container->get('ombu.stop')->engaged());
    $this->assertNull($this->request('POST', '/market/sell', 'ombu.sell')->getResponse());
    $this->assertSame([], Node::loadMultiple(), 'No post for a pause of payments alone.');
  }

  /**
   * Stopped, the site only reads: writes and payments get a temporary error.
   */
  public function testStoppedTheSiteIsReadOnly(): void {
    $this->assertNull($this->request('POST', '/market/sell', 'ombu.sell')->getResponse(), 'Not stopped: writes pass.');
    $this->container->get('ombu.stop')->engage('test');

    $this->assertNull($this->request('GET', '/market', 'view.market.page')->getResponse(), 'Reads pass.');
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
      $refused = $this->request($method, '/market/sell', 'ombu.sell')->getResponse();
      $this->assertSame(503, $refused?->getStatusCode(), $method);
      $this->assertSame('300', $refused->headers->get('Retry-After'));
      $this->assertStringNotContainsString('test', (string) $refused->getContent(), 'The reason is not sent here.');
    }
    // Staff still sign in (to resume), and agents still read over MCP.
    $this->assertNull($this->request('POST', '/user/login', 'user.login')->getResponse());
    $this->assertNull($this->request('POST', '/user/password', 'user.pass')->getResponse());
    $this->assertNull($this->request('POST', '/user/reset/1/1/abc/login', 'user.reset.login')->getResponse());
    $this->assertNull($this->request('POST', '/mcp', 'mcp_server.handle')->getResponse());
    // A payment is never looked at, whatever the method.
    $paying = $this->request('GET', '/market/listing/1', 'entity.commerce_product.canonical', ['PAYMENT-SIGNATURE' => 'abc']);
    $this->assertSame(503, $paying->getResponse()?->getStatusCode());
    $this->assertSame(['error' => 'payments_paused'], json_decode((string) $paying->getResponse()->getContent(), TRUE));

    $this->container->get('ombu.stop')->release();
    $this->assertNull($this->request('POST', '/market/sell', 'ombu.sell')->getResponse(), 'Resumed: writes pass.');
  }

  /**
   * Stopped, no response shows a wallet, and a 402 becomes a temporary error.
   */
  public function testStoppedNoWalletIsShownAndA402IsATemporaryError(): void {
    $tx = '0x' . str_repeat('ab', 32);
    $html = '<p>Pays ' . self::EVM . ' or ' . self::SOLANA . ', tx ' . $tx . '</p><link href="/files/css/css_' . self::SOLANA . '.css">';
    $this->assertSame($html, $this->respond(new Response($html))->getContent(), 'Not stopped: untouched.');

    $this->container->get('ombu.stop')->engage('test');
    $shown = (string) $this->respond(new Response($html))->getContent();
    $this->assertStringNotContainsString(self::EVM, $shown);
    $this->assertStringNotContainsString('or ' . self::SOLANA, $shown);
    $this->assertStringContainsString($tx, $shown, 'A transaction is not a wallet.');
    $this->assertStringContainsString('css_' . self::SOLANA . '.css', $shown, 'A file name is not a wallet.');
    $this->assertStringContainsString('no-store', (string) $this->respond(new Response($html))->headers->get('Cache-Control'), 'No proxy keeps it.');
    // A token that looks like an address is still a token.
    $form = '<a href="/user/logout?token=' . self::SOLANA . '">out</a><input name="form_token" value="' . self::SOLANA . '">';
    $this->assertSame($form, $this->respond(new Response($form))->getContent());
    // Sent in pieces, filtered all the same.
    $streamed = $this->respond(new StreamedResponse(function (): void {
      echo 'data: ' . self::EVM . "\n\n";
    }, 200, ['Content-Type' => 'text/event-stream']));
    ob_start();
    $streamed->sendContent();
    $this->assertSame("data: [hidden]\n\n", ob_get_clean());

    $json = $this->respond(new JsonResponse(['accepts' => [['payTo' => self::EVM, 'amount' => '100']]]));
    $this->assertSame(['accepts' => [['payTo' => '[hidden]', 'amount' => '100']]], json_decode((string) $json->getContent(), TRUE));

    $required = new JsonResponse(['x402Version' => 2, 'accepts' => [['payTo' => self::EVM]]], 402, ['PAYMENT-REQUIRED' => base64_encode(self::EVM)]);
    $paused = $this->respond($required);
    $this->assertSame(503, $paused->getStatusCode());
    $this->assertSame('300', $paused->headers->get('Retry-After'));
    $this->assertFalse($paused->headers->has('PAYMENT-REQUIRED'));
    $this->assertSame(['error' => 'payments_paused'], json_decode((string) $paused->getContent(), TRUE));

    // A sale under way when the site stopped keeps its receipt and its body.
    $receipt = ['PAYMENT-RESPONSE' => 'abc'];
    $this->assertSame(402, $this->respond(new Response('{}', 402, $receipt))->getStatusCode());
    $this->assertSame($html, $this->respond(new Response($html, 200, $receipt))->getContent());

    $this->container->get('ombu.stop')->release();
    $this->assertSame($html, $this->respond(new Response($html))->getContent(), 'Resumed: untouched.');
    $this->assertSame(402, $this->respond($required)->getStatusCode());
  }

  /**
   * Each stop and each resume is a blog post, from a template, per language.
   */
  public function testEachStopAndResumeIsABlogPostInEveryLanguage(): void {
    $stop = $this->container->get('ombu.stop');
    $stop->engage('Rotating <b>keys</b>', ['es' => 'Rotación de claves']);
    $posts = array_values(Node::loadMultiple());
    $this->assertCount(1, $posts);
    $post = $posts[0];
    $this->assertSame('blog', $post->bundle());
    $this->assertTrue($post->isPublished());
    $templates = $this->config('ombu.stop')->get('templates');
    $this->assertSame($templates['en']['stop_title'], $post->label());
    $this->assertStringContainsString('Rotating &lt;b&gt;keys&lt;/b&gt;', $post->get('body')->value, 'The reason is text, never markup.');
    foreach (['es', 'pt-br'] as $lang) {
      $this->assertTrue($post->hasTranslation($lang), $lang);
      $this->assertSame($templates[$lang]['stop_title'], $post->getTranslation($lang)->label());
    }
    $this->assertStringContainsString('Rotación de claves', $post->getTranslation('es')->get('body')->value);
    $this->assertStringContainsString('Rotating', $post->getTranslation('pt-br')->get('body')->value, 'No reason in a language: the main one.');
    $category = $post->get('field_blog_category')->entity;
    $this->assertSame($templates['en']['category'], $category->label());
    $this->assertSame($templates['es']['category'], $category->getTranslation('es')->label());

    // Asked twice, stopped once: one post.
    $stop->engage('again');
    $this->assertCount(1, Node::loadMultiple());

    $stop->release('All clear');
    $posts = array_values(Node::loadMultiple());
    $this->assertCount(2, $posts);
    $this->assertSame($templates['en']['resume_title'], $posts[1]->label());
    $this->assertStringContainsString('All clear', $posts[1]->get('body')->value);
    $this->assertSame($category->id(), $posts[1]->get('field_blog_category')->target_id, 'One category, reused.');
    $stop->release();
    $this->assertCount(2, Node::loadMultiple(), 'Not stopped: no post for a resume.');

    // Switched off in the settings: stopped all the same, no post.
    $this->config('ombu.stop')->set('post', FALSE)->save();
    $stop->engage('quiet');
    $this->assertTrue($stop->engaged());
    $this->assertCount(2, Node::loadMultiple());
  }

  /**
   * Runs a request through the subscriber, as the router would have named it.
   */
  private function request(string $method, string $path, string $route, array $headers = []): RequestEvent {
    $request = Request::create($path, $method);
    $request->attributes->set('_route', $route);
    $request->headers->add($headers);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('ombu.stop_subscriber')->onRequest($event);
    return $event;
  }

  /**
   * Runs a response through the subscriber.
   */
  private function respond(Response $response): Response {
    $request = Request::create('/market');
    $request->attributes->set('_route', 'view.market.page');
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, clone $response);
    $this->container->get('ombu.stop_subscriber')->onResponse($event);
    return $event->getResponse();
  }

}
