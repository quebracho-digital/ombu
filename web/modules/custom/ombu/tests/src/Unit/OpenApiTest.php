<?php

declare(strict_types=1);

namespace Drupal\Tests\ombu\Unit;

use Drupal\ombu\OpenApi;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Finds the operation that documents an endpoint in its provider's spec.
 */
#[Group('ombu')]
final class OpenApiTest extends UnitTestCase {

  private const SPEC = [
    'openapi' => '3.0.3',
    'paths' => [
      '/v1/weather' => [
        'get' => ['summary' => 'Weather now', 'responses' => ['200' => ['$ref' => '#/components/responses/Weather']]],
      ],
      '/v1/wallets/{address}' => [
        'parameters' => [['name' => 'address', 'in' => 'path']],
        'post' => ['summary' => 'A wallet'],
      ],
      '/wallets/{address}' => [
        'post' => ['summary' => 'Shorter, loses to the longer match'],
      ],
    ],
    'components' => [
      'responses' => ['Weather' => ['description' => 'The weather', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Self']]]]],
      'schemas' => ['Self' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/Self']]]],
    ],
  ];

  public function testMatchesTheEndOfThePathAndInlinesRefs(): void {
    $operation = OpenApi::operation(self::SPEC, 'https://api.example.com/v1/weather', 'GET');
    $this->assertSame('/v1/weather', $operation['path']);
    $this->assertSame('The weather', $operation['responses']['200']['description']);
  }

  public function testRecursiveRefsStop(): void {
    $operation = OpenApi::operation(self::SPEC, 'https://api.example.com/v1/weather', 'GET');
    $this->assertNotNull(json_encode($operation, JSON_THROW_ON_ERROR));
  }

  public function testTemplatesMatchOneSegmentAndSharedParametersCount(): void {
    $operation = OpenApi::operation(self::SPEC, 'https://x.example/api/v1/wallets/0xabc/', 'post');
    $this->assertSame('/v1/wallets/{address}', $operation['path']);
    $this->assertSame('address', $operation['parameters'][0]['name']);
  }

  public function testNothingForAnotherMethodOrPath(): void {
    $this->assertNull(OpenApi::operation(self::SPEC, 'https://api.example.com/v1/weather', 'POST'));
    $this->assertNull(OpenApi::operation(self::SPEC, 'https://api.example.com/v1/weather/today', 'GET'));
  }

  public function testParsesJsonAndYamlAndRefusesTheRest(): void {
    $this->assertSame(['paths' => []], OpenApi::parse('{"paths": {}}'));
    $this->assertSame(['paths' => ['/a' => NULL]], OpenApi::parse("paths:\n  /a:\n"));
    $this->assertNull(OpenApi::parse('<html>Not found</html>'));
  }

}
