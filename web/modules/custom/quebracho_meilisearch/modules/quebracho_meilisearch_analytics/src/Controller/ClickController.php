<?php

declare(strict_types=1);

namespace Drupal\quebracho_meilisearch_analytics\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\quebracho_meilisearch_analytics\AnalyticsEvents;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives search result clicks from the browser.
 *
 * The browser never talks to Meilisearch, so no API key is exposed.
 */
class ClickController implements ContainerInjectionInterface {

  /**
   * Clicks accepted per client and window.
   */
  public const FLOOD_THRESHOLD = 60;

  /**
   * The flood window, in seconds.
   */
  public const FLOOD_WINDOW = 60;

  /**
   * Constructs a ClickController.
   */
  public function __construct(
    protected AnalyticsEvents $events,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('quebracho_meilisearch_analytics.events'),
      $container->get('entity_type.manager'),
      $container->get('flood'),
    );
  }

  /**
   * Records a click.
   *
   * Responds 202 when the event was sent, 204 when there was nothing to send
   * (self-hosted Meilisearch), 400, 403, 404 or 429 otherwise.
   */
  public function click(Request $request): Response {
    $site = $request->headers->get('Sec-Fetch-Site');
    if ($site !== NULL && !in_array($site, ['same-origin', 'none'], TRUE)) {
      return new Response('', Response::HTTP_FORBIDDEN);
    }
    if (!$this->flood->isAllowed('quebracho_meilisearch_analytics.click', self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register('quebracho_meilisearch_analytics.click', self::FLOOD_WINDOW);

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)
      || !is_string($data['index'] ?? NULL) || !preg_match('/^[a-z0-9_]{1,64}$/', $data['index'])
      || !is_string($data['queryUid'] ?? NULL) || !preg_match('/^[0-9a-f-]{36}$/i', $data['queryUid'])
      || !is_string($data['objectId'] ?? NULL) || !preg_match('/^[A-Za-z0-9_-]{1,511}$/', $data['objectId'])
      || !is_int($data['position'] ?? NULL) || $data['position'] < 0) {
      return new Response('', Response::HTTP_BAD_REQUEST);
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->load($data['index']);
    if (!$index instanceof IndexInterface) {
      return new Response('', Response::HTTP_NOT_FOUND);
    }

    $sent = $this->events->click($index, $data['queryUid'], $data['objectId'], $data['position']);
    return new Response('', $sent ? Response::HTTP_ACCEPTED : Response::HTTP_NO_CONTENT);
  }

}
