<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\ombu\A2ui\Surface;

/**
 * The A2UI surfaces of the seller-check service: its form and its answer.
 *
 * The form is stored on the listing (`field_a2ui`) and drawn as its "Try it".
 * The answer travels in the service's JSON under `a2ui`, next to the plain
 * data an agent reads: a person gets a card, an agent ignores the key.
 */
final class SellerCheckSurface {

  /**
   * The surface id of both.
   */
  public const ID = 'sellercheck';

  /**
   * The input form: which listing, and how much the buyer will pay for a call.
   *
   * Its data model is the body that is sent.
   *
   * @return array<int, array<string, mixed>>
   *   A2UI messages.
   */
  public static function form(): array {
    return self::messages(['listing' => '', 'budget' => 0.05], [
      ['id' => 'root', 'component' => 'Column', 'children' => ['hint', 'listing', 'budget']],
      [
        'id' => 'hint',
        'component' => 'Text',
        'variant' => 'caption',
        'text' => "A listing's id, or the URL of its endpoint. The budget is the most you would pay for one call.",
      ],
      [
        'id' => 'listing',
        'component' => 'TextField',
        'label' => 'Listing (id or endpoint URL)',
        'value' => ['@path' => '/listing'],
      ],
      [
        'id' => 'budget',
        'component' => 'TextField',
        'variant' => 'number',
        'label' => 'Budget (USDC)',
        'value' => ['@path' => '/budget'],
      ],
    ]);
  }

  /**
   * The answer as a card: verdict, price and the reasons behind it.
   *
   * @param array<string, mixed> $result
   *   The service's answer: listing, verdict, verdictLabel and reasons.
   *
   * @return array<int, array<string, mixed>>
   *   A2UI messages.
   */
  public static function result(array $result): array {
    $model = $result + ['reasonCount' => count($result['reasons'] ?? [])];
    $count = [
      'value' => ['@path' => '/reasonCount'],
      'one' => '1 reason',
      'other' => ['@call' => 'formatString', 'args' => ['value' => '${/reasonCount} reasons']],
    ];
    return self::messages($model, [
      ['id' => 'root', 'component' => 'Card', 'child' => 'body'],
      ['id' => 'body', 'component' => 'Column', 'children' => ['title', 'verdict', 'price', 'count', 'reasons']],
      [
        'id' => 'title',
        'component' => 'Text',
        'text' => ['@call' => 'formatString', 'args' => ['value' => '# ${/listing/title}']],
      ],
      [
        'id' => 'verdict',
        'component' => 'Text',
        'text' => ['@call' => 'formatString', 'args' => ['value' => 'Verdict: ${/verdictLabel}']],
      ],
      [
        'id' => 'price',
        'component' => 'Text',
        'variant' => 'caption',
        'text' => [
          '@call' => 'formatCurrency',
          'args' => ['value' => ['@path' => '/listing/price'], 'currency' => 'USDC', 'decimals' => 3],
        ],
      ],
      [
        'id' => 'count',
        'component' => 'Text',
        'variant' => 'caption',
        'text' => ['@call' => 'pluralize', 'args' => $count],
      ],
      ['id' => 'reasons', 'component' => 'List', 'children' => ['componentId' => 'reason', 'path' => '/reasons']],
      ['id' => 'reason', 'component' => 'Row', 'children' => ['number', 'why'], 'align' => 'start'],
      ['id' => 'number', 'component' => 'Text', 'text' => ['@call' => '@index', 'args' => ['offset' => 1]]],
      ['id' => 'why', 'component' => 'Text', 'text' => ['@path' => 'text']],
    ]);
  }

  /**
   * The two messages that make a surface.
   *
   * @param array<string, mixed> $model
   *   The data model.
   * @param array<int, array<string, mixed>> $components
   *   The components.
   *
   * @return array<int, array<string, mixed>>
   *   A2UI messages.
   */
  private static function messages(array $model, array $components): array {
    return [
      [
        'version' => '1.0',
        'createSurface' => ['surfaceId' => self::ID, 'catalogId' => Surface::BASIC_CATALOG, 'dataModel' => $model],
      ],
      ['version' => '1.0', 'updateComponents' => ['surfaceId' => self::ID, 'components' => $components]],
    ];
  }

}
