<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\ai_provider_universal_terms\Service\TermsAnalyzer;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyRepositoryInterface;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\Signer;
use GuzzleHttp\ClientInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Our sellers. Each answers a paid call with JSON.
 *
 * Input is the request's JSON body. Anything but a 2xx is never charged:
 * 400 for input the service cannot use, 422 for a page it may not or
 * cannot fetch, 502 when the model fails, 503 when it is not set up.
 */
class Sellers {

  /**
   * Characters past which a terms analysis goes to the long-documents model.
   *
   * ponytail: a fixed size, ~45 s on the local model; measure if it misses.
   */
  public const LONG_TERMS = 20000;

  /**
   * The model a long terms analysis runs on, while it runs (SellerSubscriber).
   */
  public ?string $longModel = NULL;

  public function __construct(
    private readonly KeyRepositoryInterface $keys,
    private readonly LoggerInterface $logger,
    private readonly ?PageFetcher $fetcher = NULL,
    private readonly ?TermsAnalyzer $terms = NULL,
    private readonly ?FactChecker $factChecker = NULL,
    private readonly ?ClientInterface $http = NULL,
  ) {}

  /**
   * The listing's own seller, from the key it names; NULL if unusable.
   */
  public function seller(ProductInterface $listing): ?Signer {
    $key = $this->keys->getKey((string) $listing->get('field_seller_key')->value);
    try {
      return $key ? new Signer(trim((string) $key->getKeyValue())) : NULL;
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * The listing's seller address when it has no key here; NULL if none.
   *
   * Selling to it needs a Circle API key (see x402_pay's settings).
   */
  public function address(ProductInterface $listing): ?string {
    $address = trim((string) $listing->get('field_seller_address')->value);
    return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? $address : NULL;
  }

  /**
   * Who the listing's sales pay: its cold address first, else its key's.
   *
   * The key stays for what it signs (ping), not for the money.
   */
  public function payTo(ProductInterface $listing): ?string {
    return $this->address($listing) ?? $this->seller($listing)?->address();
  }

  public function deliver(string $service, ProductInterface $listing, Request $request): Response {
    $input = json_decode($request->getContent(), TRUE);
    $input = is_array($input) ? $input : [];
    try {
      return match ($service) {
        'ping' => $this->ping($listing),
        'markdown' => $this->markdown($input),
        'terms' => $this->terms($input),
        'factcheck' => $this->factcheck($input),
        'aitext' => $this->remote('ai', $this->text($input)),
        'plagiarism' => $this->remote('plagiarism', $this->text($input)),
        default => new JsonResponse(['error' => "Unknown service: $service"], 503),
      };
    }
    catch (PageFetchException $e) {
      return new JsonResponse(['error' => (string) $e->getMessage()], 422);
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Seller @service failed: @message', ['@service' => $service, '@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'The seller failed; nothing was charged.'], 502);
    }
  }

  /**
   * The time now, signed by the seller: proves the call reached it.
   */
  private function ping(ProductInterface $listing): Response {
    $seller = $this->seller($listing) ?? throw new \RuntimeException('No seller key.');
    $time = gmdate('Y-m-d\TH:i:s\Z');
    return new JsonResponse(['time' => $time, 'signer' => $seller->address(), 'signature' => $seller->sign(Eip712::keccak($time))]);
  }

  /**
   * A public page's main content as markdown: no navigation, no scripts.
   */
  private function markdown(array $input): Response {
    if (!is_string($input['url'] ?? NULL)) {
      return new JsonResponse(['error' => 'Send {"url": "https://…"}.'], 400);
    }
    if (!$this->fetcher) {
      return new JsonResponse(['error' => 'Not available right now.'], 503);
    }
    return new JsonResponse(['url' => $input['url']] + self::pageToMarkdown($this->fetcher->fetch($input['url']), $input['url']));
  }

  /**
   * A page's title, its content as markdown, and the links around it.
   *
   * The navigation is dropped from the markdown but its links are kept
   * apart: a site's terms and privacy policy are usually only linked from
   * its footer. Absolute URLs, at most 100.
   *
   * @return array{title: ?string, markdown: string, links: list<array{text: string, url: string}>}
   */
  public static function pageToMarkdown(string $html, string $base = ''): array {
    $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)) : NULL;
    $converter = new HtmlConverter([
      'strip_tags' => TRUE,
      'remove_nodes' => 'head script style nav header footer aside form noscript iframe svg',
      'hard_break' => TRUE,
      'header_style' => 'atx',
    ]);
    return ['title' => $title ?: NULL, 'markdown' => trim($converter->convert($html)), 'links' => self::navigationLinks($html, $base)];
  }

  /**
   * The links in a page's nav, header and footer, made absolute.
   *
   * @return list<array{text: string, url: string}>
   */
  private static function navigationLinks(string $html, string $base): array {
    $document = new \DOMDocument();
    if (!@$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NONET)) {
      return [];
    }
    $origin = preg_match('~^https?://[^/]+~i', $base, $m) ? $m[0] : '';
    $links = [];
    foreach ((new \DOMXPath($document))->query('//nav//a[@href] | //header//a[@href] | //footer//a[@href]') as $a) {
      $href = trim($a->getAttribute('href'));
      // ponytail: page-relative hrefs ("terms.html") are skipped, sites link root-relative.
      $url = match (TRUE) {
        (bool) preg_match('~^https?://~i', $href) => $href,
        str_starts_with($href, '//') => 'https:' . $href,
        str_starts_with($href, '/') && $origin !== '' => $origin . $href,
        default => NULL,
      };
      $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
      if ($url !== NULL && !isset($links[$url])) {
        $links[$url] = ['text' => $text, 'url' => $url];
      }
    }
    return array_slice(array_values($links), 0, 100);
  }

  /**
   * What matters in a terms of service or privacy policy, each point quoting
   * the clause it comes from. Input: url or text.
   */
  private function terms(array $input): Response {
    if (!is_string($input['url'] ?? NULL) && !is_string($input['text'] ?? NULL)) {
      return new JsonResponse(['error' => 'Send {"url": "https://…"} or {"text": "…"}.'], 400);
    }
    if (!$this->terms?->isConfigured() || (isset($input['url']) && !$this->fetcher)) {
      return new JsonResponse(['error' => 'Not available right now.'], 503);
    }
    $text = isset($input['url']) ? $this->fetcher->fetchText($input['url']) : $input['text'];
    if (mb_strlen($text) > $this->terms->maxChars()) {
      return new JsonResponse(['error' => sprintf('Longer than %d characters.', $this->terms->maxChars())], 413);
    }
    // A long document on the local model outlives Cloudflare's 100 s: a
    // faster remote one, set in settings.php ($settings['ombu_long_terms_model']).
    if (mb_strlen($text) > self::LONG_TERMS) {
      $this->longModel = Settings::get('ombu_long_terms_model');
    }
    try {
      return new JsonResponse($this->terms->analyze($text));
    }
    finally {
      $this->longModel = NULL;
    }
  }

  /**
   * Verdicts on a claim, or on every claim in a text. On the fact-check API
   * (with web search) when one is set, else the local model on its own.
   */
  private function factcheck(array $input): Response {
    $text = $this->text($input + ['text' => $input['claim'] ?? NULL]);
    if ($text === NULL) {
      return new JsonResponse(['error' => 'Send {"claim": "…"} or {"text": "…"}.'], 400);
    }
    if (Settings::get('ombu_factcheck_api')) {
      $response = $this->remote('claims', $text);
      return $response->isSuccessful() ? new JsonResponse(self::factcheckAnswer(json_decode((string) $response->getContent(), TRUE) ?: [])) : $response;
    }
    if (!$this->factChecker?->isConfigured()) {
      return new JsonResponse(['error' => 'Not available right now.'], 503);
    }
    $result = isset($input['claim']) ? $this->factChecker->verifyClaims([$text]) : $this->factChecker->verify('', $text);
    return new JsonResponse(self::factcheckAnswer($result));
  }

  /**
   * The input's non-empty "text", or NULL.
   */
  private function text(array $input): ?string {
    return is_string($input['text'] ?? NULL) && trim($input['text']) !== '' ? trim($input['text']) : NULL;
  }

  /**
   * One check on the fact-check API (ai_provider_universal_factcheck's
   * /api/factcheck/{check}), its status and body passed through.
   */
  private function remote(string $check, ?string $text): Response {
    if ($text === NULL) {
      return new JsonResponse(['error' => 'Send {"text": "…"}.'], 400);
    }
    $api = Settings::get('ombu_factcheck_api');
    if (!$api || !$this->http) {
      return new JsonResponse(['error' => 'Not available right now.'], 503);
    }
    $response = $this->http->request('POST', rtrim($api['url'], '/') . "/api/factcheck/$check?_format=json", [
      'auth' => $api['auth'],
      'json' => ['text' => $text],
      'http_errors' => FALSE,
      'timeout' => 900,
    ]);
    return new JsonResponse(json_decode((string) $response->getBody(), TRUE), $response->getStatusCode());
  }

  /**
   * The checker's result, saying whether web evidence was behind it.
   */
  public static function factcheckAnswer(array $result): array {
    $sourced = array_filter($result['claims'] ?? [], fn($claim) => !empty($claim['coverage']));
    return ['evidence' => $sourced ? 'web' : 'model_only'] + $result;
  }

}
