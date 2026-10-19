<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ombu\Sellers;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The text sellers run on the fact-check API: status and body pass through.
 */
#[Group('ombu')]
final class SellersRemoteTest extends TestCase {

  public function testChecksGoToTheApiAndErrorsPassThrough(): void {
    new Settings(['ombu_factcheck_api' => ['url' => 'http://checker:8088/', 'auth' => ['ombu', 'secret']]]);
    $sent = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"score": 1, "claims": [{"claim": "c", "verdict": "SUPPORTED", "coverage": {"sources": 2}}]}'),
      new Response(200, [], '{"score": 87, "rationale": "Generic."}'),
      new Response(503, [], '{"error": "This check is not configured on this site."}'),
    ]));
    $stack->push(Middleware::history($sent));
    $sellers = new Sellers($this->createMock(KeyRepositoryInterface::class), new NullLogger(), http: new Client(['handler' => $stack]));
    $listing = $this->createMock(ProductInterface::class);
    $call = fn(string $service, string $body) => $sellers->deliver($service, $listing, Request::create('/', 'POST', content: $body));

    $factcheck = $call('factcheck', '{"claim": "USDC is issued by Circle."}');
    $this->assertSame('web', json_decode($factcheck->getContent(), TRUE)['evidence']);
    $this->assertSame('http://checker:8088/api/factcheck/claims?_format=json', (string) $sent[0]['request']->getUri());
    $this->assertSame('Basic ' . base64_encode('ombu:secret'), $sent[0]['request']->getHeaderLine('Authorization'));
    $this->assertSame(['text' => 'USDC is issued by Circle.'], json_decode((string) $sent[0]['request']->getBody(), TRUE));

    $this->assertSame(87, json_decode($call('aitext', '{"text": "Some prose."}')->getContent(), TRUE)['score']);
    $this->assertSame(503, $call('plagiarism', '{"text": "Some prose."}')->getStatusCode());
    // No text: refused here, never sent (nothing charged).
    $this->assertSame(400, $call('plagiarism', '{}')->getStatusCode());
    $this->assertCount(3, $sent);
  }

}
