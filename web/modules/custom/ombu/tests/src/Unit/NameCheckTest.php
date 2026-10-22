<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ombu\NameCheck;
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
 * The name check: what each registry's answer means.
 */
#[Group('ombu')]
final class NameCheckTest extends TestCase {

  public function testWhoisIsFreeOnlyWhenTheRegistrySaysSo(): void {
    $this->assertSame('free', NameCheck::whoisStatus("El dominio no se encuentra registrado en NIC Argentina\n"));
    $this->assertSame('free', NameCheck::whoisStatus("Domain: x.de\nStatus: free\n"));
    $this->assertSame('registered', NameCheck::whoisStatus("% NIC.ar\ndomain:\tyzur.com.ar\nexpire:\t2027-07-06\n"));
    // A registry that drops us answers nothing: that is not "free".
    $this->assertSame('unknown', NameCheck::whoisStatus("% rate limited\n\n"));
  }

  public function testInpiStatusesAndLiveMarks(): void {
    $future = (time() + 86400) * 1000;
    $found = NameCheck::inpiMarks(new Response(200, [], json_encode(['total' => 4, 'rows' => [
      ['Acta' => 1, 'Clase' => 35, 'Denominacion' => 'SHUK', 'Titulares' => 'A', 'Estado' => 'C', 'Fecha_Vencimiento' => "/Date($future)/"],
      ['Acta' => 2, 'Clase' => 35, 'Denominacion' => 'EL  SHUK', 'Titulares' => 'B', 'Estado' => NULL, 'Fecha_Vencimiento' => '/Date(-62135586000000)/'],
      ['Acta' => 3, 'Clase' => 36, 'Denominacion' => 'SHUKO', 'Titulares' => 'C', 'Estado' => 'C', 'Fecha_Vencimiento' => '/Date(1000)/'],
      ['Acta' => 4, 'Clase' => 42, 'Denominacion' => 'SHUKA', 'Titulares' => 'D', 'Estado' => 'A', 'Fecha_Vencimiento' => '/Date(-62135586000000)/'],
    ]])));
    $this->assertSame(['granted', 'pending', 'granted, expired', 'abandoned'], array_column($found['marks'], 'status'));
    $summary = NameCheck::summary($found, [35, 42]);
    $this->assertSame(3, $summary['found']);
    $this->assertSame(['SHUK', 'EL SHUK'], array_column($summary['live'], 'mark'));
    $this->assertArrayNotHasKey('truncated', $summary);
  }

  public function testUsptoOneRowPerClassAndSaysWhenTruncated(): void {
    $found = NameCheck::usptoMarks(new Response(200, [], json_encode(['hits' => ['totalValue' => 900, 'hits' => [
      ['id' => '1', 'source' => ['wordmark' => 'THE SHUK MARKETPLACE', 'alive' => TRUE, 'registrationDate' => '2024-01-01', 'internationalClass' => ['IC 035', 'IC 042'], 'ownerName' => ['X LLC']]],
      ['id' => '2', 'source' => ['wordmark' => 'OURSHUK', 'alive' => TRUE, 'registrationDate' => NULL, 'internationalClass' => ['IC 035'], 'ownerName' => ['Y']]],
    ]]])));
    $this->assertSame([35, 42, 35], array_column($found['marks'], 'class'));
    $this->assertSame(['registered', 'registered', 'pending'], array_column($found['marks'], 'status'));
    $this->assertStringContainsString('900 marks match, 3 read', NameCheck::summary($found, [])['truncated']);
  }

  public function testAChangedShapeFailsInsteadOfSayingNone(): void {
    $this->expectException(\RuntimeException::class);
    NameCheck::usptoMarks(new Response(200, [], '{"results": []}'));
  }

  public function testTheSellerRefusesBadInputWithoutCallingOut(): void {
    $sent = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($sent));
    $sellers = new Sellers($this->createMock(KeyRepositoryInterface::class), new NullLogger(), http: new Client(['handler' => $stack]));
    $listing = $this->createMock(ProductInterface::class);
    foreach ([
      '{}',
      '{"name": "hashuk.com"}',
      '{"name": "x"}',
      '{"name": "acme", "countries": ["fr"]}',
      '{"name": "acme", "classes": [99]}',
      '{"name": "acme", "tlds": ["com", "a b"]}',
    ] as $body) {
      $this->assertSame(400, $sellers->deliver('names', $listing, Request::create('/', 'POST', content: $body))->getStatusCode(), $body);
    }
    $this->assertCount(0, $sent);
  }

}
