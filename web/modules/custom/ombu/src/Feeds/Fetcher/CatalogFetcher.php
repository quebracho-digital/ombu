<?php

declare(strict_types=1);

namespace Drupal\ombu\Feeds\Fetcher;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\Fetcher\FetcherInterface;
use Drupal\feeds\Plugin\Type\PluginBase;
use Drupal\feeds\Result\RawFetcherResult;
use Drupal\feeds\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ombu\Catalog\CatalogFeeds;
use Drupal\ombu\Catalog\CatalogRun;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads an x402 discovery catalogue one page at a time.
 *
 * Bazaar, PayAI and Circle all answer `GET {url}?limit&offset` with
 * `{items: [...], pagination: {total}}`. Each call reads one page of
 * `page_size` and keeps the next offset in the fetch state, so Feeds parses
 * and processes that page before it asks for the next one (in a batch or a
 * cron queue each page is its own step). The pointer moves by the number of
 * items the server actually returned, not by the page size it asked for: a
 * server that clips `limit` must not skip entries. A run finishes on an empty
 * page, or when the offset reaches `pagination.total`; without a total it goes
 * on until a page comes back empty, with a cap on pages so a broken catalogue
 * cannot loop forever. Hitting the cap stops the run without retiring.
 *
 * The address is the feed type's configuration, not the feed's: only staff
 * who administer feed types can point it elsewhere, and it is exported and
 * reviewed like the rest of the configuration (what ombu.services.yml held).
 * It must be https. A catalogue that wants a key gets it from the Key module
 * (`auth_key`), sent in `auth_header`; the secret never sits in the config.
 *
 * An empty or broken answer is not an empty catalogue: it is an error, the
 * run stops there and retires nothing.
 *
 * @FeedsFetcher(
 *   id = "ombu_catalog",
 *   title = @Translation("x402 discovery catalogue"),
 *   description = @Translation("Reads a paged x402 discovery API (Bazaar, PayAI, Circle)."),
 * )
 */
final class CatalogFetcher extends PluginBase implements FetcherInterface, ContainerFactoryPluginInterface, PluginFormInterface {

  /**
   * Pages read at most in one run, when the catalogue does not say its total.
   *
   * 100 per page is 1,000,000 resources; a server that clips the page to 20
   * still reaches 200,000. Past this the run stops and retires nothing.
   */
  private const MAX_PAGES = 10000;

  /**
   * Builds the fetcher.
   *
   * The services are protected, not readonly: Feeds serializes its plugins
   * between the steps of a batch, and Drupal's serialization sets them back.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected ClientInterface $httpClient,
    protected ?KeyRepositoryInterface $keys,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'url' => '',
      'page_size' => 100,
      'timeout' => 30,
      // A page of 100 resources is well under 1 MB today; 10 MB is room
      // without letting a catalogue fill the memory.
      'max_bytes' => 10485760,
      'auth_header' => '',
      'auth_key' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(FeedInterface $feed, StateInterface $state) {
    $run = CatalogRun::of($feed);
    $source = CatalogFeeds::sourceOf($feed->getType());
    $offset = (int) ($state->pointer ?? 0);
    $pages = (int) ($state->pages ?? 0) + 1;
    $state->pages = $pages;
    try {
      $body = $this->page($offset);
      $page = json_decode($body, TRUE);
      // An empty or broken answer is not an empty catalogue: it would retire
      // every listing of the source.
      if (!is_array($page) || !is_array($page['items'] ?? NULL)) {
        throw new \RuntimeException("The $source catalogue did not answer with a list of items.");
      }
    }
    catch (\Throwable $e) {
      $run->fail($e->getMessage());
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    $count = count($page['items']);
    $next = $offset + $count;
    $total = isset($page['pagination']['total']) && is_numeric($page['pagination']['total'])
      ? (int) $page['pagination']['total']
      : NULL;
    $state->pointer = $next;
    if ($total !== NULL) {
      $state->total = $total;
    }
    if ($count === 0 || ($total !== NULL && $next >= $total)) {
      $state->setCompleted();
      $run->complete = TRUE;
    }
    elseif ($pages >= self::MAX_PAGES) {
      // Stop, but the run is not whole: it retires nothing.
      $state->setCompleted();
      $run->fail("The $source catalogue answered more than " . self::MAX_PAGES . ' pages without a total; the run is partial.');
    }
    elseif ($total !== NULL) {
      $state->progress($total, $next);
    }
    else {
      // No total: keep the fetch state incomplete so Feeds asks for the next
      // page. The progress is a guess that never reaches 1.0.
      $state->total = $next + 1;
      $state->progress = min(0.99, $next / ($next + 1));
    }
    return new RawFetcherResult($body);
  }

  /**
   * One page of the catalogue, as text.
   */
  private function page(int $offset): string {
    $url = (string) $this->configuration['url'];
    if (!preg_match('#^https://[^/\s]+#i', $url)) {
      throw new \RuntimeException('The catalogue address must be https.');
    }
    // The catalogue's own query (a filter, a version) stays; the page is ours.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $headers = ['Accept' => 'application/json'];
    if ($this->configuration['auth_header'] !== '' && $this->configuration['auth_key'] !== '' && $this->keys) {
      $value = $this->keys->getKey((string) $this->configuration['auth_key'])?->getKeyValue();
      if (!is_string($value) || $value === '') {
        throw new \RuntimeException('The catalogue key is not set.');
      }
      $headers[(string) $this->configuration['auth_header']] = $value;
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    $max = (int) $this->configuration['max_bytes'];
    $response = $this->httpClient->request('GET', strtok($url, '?'), [
      'query' => ['limit' => (int) $this->configuration['page_size'], 'offset' => $offset] + $query,
      'timeout' => (int) $this->configuration['timeout'],
      'headers' => $headers,
      // A discovery catalogue has no reason to redirect. A same-host redirect
      // is allowed; another host is refused so the key header cannot travel.
      'allow_redirects' => [
        'max' => 3,
        'protocols' => ['https'],
        'strict' => TRUE,
        'referer' => FALSE,
        'on_redirect' => function ($request, $response, $uri) use ($host) {
          if (strcasecmp($uri->getHost(), $host) !== 0) {
            throw new \RuntimeException('The catalogue redirected to another host.');
          }
        },
      ],
      // Stream the body so max_bytes is enforced while reading, not after.
      'stream' => TRUE,
      'on_headers' => function (ResponseInterface $response) use ($max) {
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && (int) $length > $max) {
          throw new \RuntimeException("The catalogue answered more than $max bytes for one page.");
        }
      },
    ]);
    $status = $response->getStatusCode();
    if ($status >= 300 && $status < 400) {
      throw new \RuntimeException("The catalogue redirected ($status) more times than allowed.");
    }
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof() && strlen($body) <= $max) {
      $body .= $stream->read(65536);
    }
    if (strlen($body) > $max) {
      throw new \RuntimeException("The catalogue answered more than $max bytes for one page.");
    }
    return $body;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Catalogue address'),
      '#description' => $this->t('The discovery API, https. Pages are asked with limit and offset.'),
      '#default_value' => $this->configuration['url'],
      '#required' => TRUE,
    ];
    $form['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Resources per page'),
      '#min' => 1,
      '#max' => 1000,
      '#default_value' => $this->configuration['page_size'],
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout, in seconds'),
      '#min' => 1,
      '#default_value' => $this->configuration['timeout'],
    ];
    $form['auth_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Header that carries the key'),
      '#description' => $this->t('For a catalogue that asks for one, e.g. Authorization. Empty: none.'),
      '#default_value' => $this->configuration['auth_header'],
    ];
    $form['auth_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key'),
      '#description' => $this->t('The machine name of a key (Key module) that holds the header value.'),
      '#default_value' => $this->configuration['auth_key'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!preg_match('#^https://[^/\s]+#i', (string) $form_state->getValue('url'))) {
      $form_state->setErrorByName('url', $this->t('The catalogue address must be https.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration([
      'url' => (string) $form_state->getValue('url'),
      'page_size' => (int) $form_state->getValue('page_size'),
      'timeout' => (int) $form_state->getValue('timeout'),
      'max_bytes' => (int) $this->configuration['max_bytes'],
      'auth_header' => trim((string) $form_state->getValue('auth_header')),
      'auth_key' => trim((string) $form_state->getValue('auth_key')),
    ]);
  }

}
