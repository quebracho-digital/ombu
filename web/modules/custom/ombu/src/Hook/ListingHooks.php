<?php

declare(strict_types=1);

namespace Drupal\ombu\Hook;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\ombu\Cards;
use Drupal\ombu\Chains;
use Drupal\ombu\CircleCatalogImporter;
use Drupal\ombu\Rating;
use Drupal\ombu\Sales;
use Drupal\ombu\Sellers;
use Drupal\ombu\Similar;
use Drupal\search_api\Query\QueryInterface;
use Drupal\views\ViewExecutable;

/**
 * The listing pages, the market, the Circle sync and the legal notices.
 */
final class ListingHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly Similar $similar,
  ) {}

  /**
   * Re-imports Circle's catalogue every 6 hours.
   */
  #[Hook('cron')]
  public function cron(): void {
    $state = \Drupal::state();
    // ponytail: full re-import every 6 hours inside cron; move to a queue if
    // the catalogue outgrows one cron run (2.9k listings, ~2026-10). Run cron
    // from the system, not automated_cron: an import is minutes of a
    // visitor's request.
    if (\Drupal::time()->getRequestTime() - $state->get('ombu.circle_last', 0) < 21600) {
      return;
    }
    try {
      \Drupal::service('ombu.circle_importer')->import();
    }
    catch (\RuntimeException) {
      // Another import is running; it records its own finish time.
    }
  }

  /**
   * A listing: headings, rating, retired notice, who answers for it.
   */
  #[Hook('commerce_product_view')]
  public function view(array &$build, ProductInterface $product, $display, string $view_mode): void {
    if ($product->bundle() !== 'listing') {
      return;
    }
    // The title is the page's h1, and each card's h2 on /market, so screen
    // readers can jump between them.
    if (isset($build['title']) && ($tag = ['full' => 'h1', 'teaser' => 'h2'][$view_mode] ?? NULL)) {
      $build['title']['#prefix'] = "<$tag class=\"listing-title\">";
      $build['title']['#suffix'] = "</$tag>";
    }
    // Its logo before the page's title, as on the cards (Cards::logo()).
    if ($view_mode === 'full' && isset($build['title']) && ($logo = Cards::logo($product))) {
      $build['title']['#prefix'] .= '<img class="listing-logo" src="' . Html::escape($logo) . '" alt="" width="48" height="48">';
      $build['#cache']['tags'] = [...$build['#cache']['tags'] ?? [], 'taxonomy_term_list', 'user_list'];
    }

    $rating = Rating::of($product);
    $build['rating'] = [
      '#markup' => $rating
        ? sprintf('<div class="listing-rating">%s %s <small>(%d)</small></div>', Rating::stars($rating['average']), $rating['average'], $rating['count'])
        : '<div class="listing-rating"><small>' . $this->t('No reviews yet') . '</small></div>',
      '#weight' => -5,
      '#cache' => ['tags' => ['comment_list']],
    ];

    // ponytail: no cart for people until the Commerce x402 gateway exists;
    // the price still shows, and agents pay over x402 as before.
    unset($build['variations']);

    // Retired: its provider no longer offers it. The page and its reviews
    // stay; buying does not.
    if ($product->hasField('field_retired') && $product->get('field_retired')->value) {
      $build['retired'] = [
        '#markup' => '<div class="messages messages--warning listing-retired">' . $this->t('Retired: its provider no longer offers this. Reviews stay open.') . '</div>',
        '#weight' => -6,
      ];
    }

    if (isset($build['field_network'])) {
      foreach (Element::children($build['field_network']) as $delta) {
        $build['field_network'][$delta]['#context']['value'] = Chains::name((string) $build['field_network'][$delta]['#context']['value']);
      }
    }

    if ($view_mode === 'full') {
      // Who answers for it (the terms, section 2), and how to report it.
      // A path, not the contact route: the page works before the form exists.
      $report = Url::fromUserInput('/contact/report', ['query' => ['about' => $product->toUrl()->setAbsolute()->toString()]]);
      $third_party = $product->get('field_source')->value !== 'ours';
      $build['legal'] = [
        '#markup' => '<p class="listing-legal"><small>'
          . ($third_party ? $this->t('Offered by a third party: Ombú is not its seller and does not answer for it. <a href=":terms">Terms</a>.', [':terms' => Url::fromUserInput('/terms')->toString()]) . ' ' : '')
          . $this->t('<a href=":report">Report this listing</a>', [':report' => $report->toString()])
          . '</small></p>',
        // Above the reviews (12), not under their form.
        '#weight' => 11.8,
      ];
      $build['#attached']['library'][] = 'ombu/listing';
      // Its own sales: after the details (schemas, 11), above the legal
      // line and the reviews (12).
      $build['sales'] = Sales::build($product) + ['#weight' => 11.5];
      // Alternatives, under the reviews (12): Similar's view, or its own
      // cards when the views are off.
      $build['similar'] = $this->similar->build($product) + ['#weight' => 13];
      // People buy it too: the agents' door, paid with their wallet.
      $examples = [
        'ping' => '{}',
        'markdown' => '{"url": "https://www.arc.io"}',
        'terms' => '{"url": "https://www.circle.com/legal/privacy-policy"}',
        'factcheck' => '{"claim": "USDC is issued by Circle."}',
        'aitext' => '{"text": "In today\'s fast-paced world, it is important to note that innovation plays a pivotal role."}',
        'plagiarism' => '{"text": "We hold these truths to be self-evident, that all men are created equal, that they are endowed by their Creator with certain unalienable Rights."}',
      ];
      $service = (string) ($product->hasField('field_service') ? $product->get('field_service')->value : '');
      $price = $product->hasField('field_x402_price') ? $product->get('field_x402_price')->first() : NULL;
      if (isset($examples[$service]) && $price?->get('enabled')->getValue()) {
        $build['buy'] = [
          '#type' => 'html_tag',
          '#tag' => 'form',
          '#attributes' => [
            'class' => ['ombu-buy'],
            'data-ombu-buy' => Url::fromRoute('x402_pay.entity', ['entity_type' => 'commerce_product', 'entity' => $product->id(), 'view_mode' => 'full'])->toString(),
            'data-ombu-solana' => Url::fromRoute('x402_pay.solana_transaction')->toString(),
          ],
          'title' => ['#markup' => '<h2>' . $this->t('Try it') . '</h2><label for="ombu-buy-input">' . $this->t('Input (JSON)') . '</label>'],
          'input' => ['#type' => 'html_tag', '#tag' => 'textarea', '#value' => $examples[$service], '#attributes' => ['id' => 'ombu-buy-input', 'rows' => 2, 'class' => ['form-control']]],
          'button' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Pay @price USDC with your wallet', ['@price' => $price->get('amount')->getValue()]), '#attributes' => ['type' => 'submit', 'class' => ['btn', 'btn-primary']]],
          'note' => ['#markup' => '<p><small>' . (str_starts_with(Sellers::network($product), 'solana:')
            ? $this->t('One signature in your wallet (Phantom, Solflare, Backpack), no fees; USDC on Solana. Charged only if the seller answers.')
            : $this->t('One signature in your wallet (MetaMask), no gas; USDC on Arc. Charged only if the seller answers.')) . '</small></p>'],
          'result' => ['#markup' => '<pre class="ombu-buy__result" aria-live="polite"></pre>'],
          '#attached' => ['library' => ['ombu/buy']],
          '#weight' => 11.4,
        ];
      }
      // Belgrade keeps a column for images; without them the details take
      // the page's width.
      $build['#attributes']['class'][] = $product->hasField('images') && !$product->get('images')->isEmpty() ? 'listing--images' : 'listing--no-images';
      // Schemas readable: indented, monospaced, escaped.
      foreach (['field_input_schema', 'field_output_schema'] as $field) {
        $json = json_decode((string) ($build[$field][0]['#context']['value'] ?? ''), TRUE);
        if ($json !== NULL) {
          $build[$field][0] = [
            '#type' => 'html_tag',
            '#tag' => 'pre',
            'json' => ['#plain_text' => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
          ];
        }
      }
      $docs = (string) ($build['field_docs_url'][0]['#context']['value'] ?? '');
      if (preg_match('~^https?://~i', $docs)) {
        $build['field_docs_url'][0] = ['#type' => 'link', '#title' => $docs, '#url' => Url::fromUri($docs)];
      }
    }

    if ($view_mode === 'full' && $product->hasField('field_openapi') && ($operation = json_decode((string) $product->get('field_openapi')->value, TRUE))) {
      $spec = $product->get('field_provider_term')->entity?->get('field_openapi_url')->value;
      $build['openapi'] = [
        '#type' => 'details',
        '#title' => $this->t('API: @method @path', ['@method' => strtoupper((string) $product->get('field_http_method')->value), '@path' => $operation['path'] ?? '']),
        '#weight' => 11,
        'summary' => ['#plain_text' => trim(($operation['summary'] ?? '') . "\n\n" . ($operation['description'] ?? ''))],
        'operation' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          // Third-party text: escaped, never markup.
          'json' => ['#plain_text' => json_encode($operation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ],
        'spec' => $spec && preg_match('~^https?://~i', $spec) ? [
          '#type' => 'link',
          '#title' => $this->t("The provider's full OpenAPI"),
          '#url' => Url::fromUri($spec),
          '#prefix' => '<p>',
          '#suffix' => '</p>',
        ] : [],
      ];
    }
  }

  /**
   * Staff deleting a Circle listing is a takedown: it is not imported again.
   *
   * Listings Circle drops are retired, never deleted (CircleCatalogImporter).
   */
  #[Hook('commerce_product_delete')]
  public function delete(ProductInterface $product): void {
    CircleCatalogImporter::block($product);
  }

  /**
   * A sale in x402's log leads to the listing it bought.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['x402_pay_log']['listing'] = [
      'title' => $this->t('Listing'),
      'help' => $this->t('The product a sale bought.'),
      'relationship' => [
        'id' => 'standard',
        'base' => 'commerce_product_field_data',
        'base field' => 'product_id',
        'field' => 'entity_id',
        'label' => $this->t('Listing'),
        'extra' => [['left_field' => 'entity_type', 'value' => 'commerce_product']],
      ],
    ];
  }

  #[Hook('views_pre_render')]
  public function viewsPreRender(ViewExecutable $view): void {
    if ($view->id() === 'market') {
      $view->element['#attached']['library'][] = 'ombu/market';
      $view->attachment_before[] = Sales::build();
    }
  }

  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'ombu/brand';
  }

  /**
   * A listing shows once per language: its translation, or the original
   * where it has none (Search API's "Language (with fallback)").
   */
  #[Hook('search_api_query_alter')]
  public function searchApiQueryAlter(QueryInterface $query): void {
    if ($query->getIndex()->id() === 'listings' && $query->getIndex()->getField('language_with_fallback')) {
      $query->addCondition('language_with_fallback', \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId());
    }
  }

  /**
   * The withdrawal button: what a cancellation needs, and for which sales.
   */
  #[Hook('form_contact_message_withdrawal_form_alter')]
  public function withdrawalFormAlter(array &$form, FormStateInterface $form_state): void {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Cancel the purchase of a service sold by Ombú (seller: Quebracho) within 10 calendar days, without giving a reason. Include the transaction of the payment. We return the amount paid to the wallet that paid it. Purchases from other sellers are cancelled with them: Ombú is not their seller (<a href=":terms">terms</a>, section 3).', [':terms' => Url::fromUserInput('/terms')->toString()]) . '</p>',
      '#weight' => -100,
    ];
  }

  /**
   * Says what a report needs, and fills in the listing it came from.
   */
  #[Hook('form_contact_message_report_form_alter')]
  public function reportFormAlter(array &$form, FormStateInterface $form_state): void {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Report a listing, a review, a seller, an account or anything on Ombú that breaks our <a href=":terms">terms</a> or the law. Include the link to it and why it should come down. Reports are confidential: we do not tell the person reported who sent them, unless the law requires it.', [':terms' => Url::fromUserInput('/terms')->toString()]) . '</p>',
      '#weight' => -100,
    ];
    // ponytail: the link comes from the query, as plain text in the message;
    // nothing trusts it.
    $about = mb_substr((string) \Drupal::request()->query->get('about', ''), 0, 500);
    if ($about !== '' && isset($form['message']['widget'][0]['value'])) {
      $form['message']['widget'][0]['value']['#default_value'] = $about . "\n\n";
    }
  }

}
