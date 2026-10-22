<?php

declare(strict_types=1);

namespace Drupal\ombu;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Is a name free? Its domains and the live trademarks that contain it.
 *
 * Domains by RDAP (IANA's bootstrap), or WHOIS where the TLD has no RDAP,
 * where RDAP fails, and for .ar; never "free" without the registry saying so. Trademarks from Argentina's
 * INPI (the JSON behind its public search) and the USPTO (the JSON behind
 * tmsearch.uspto.gov, undocumented: a change fails loudly, never as "none").
 * The same as `urunday mcp` (quebracho-digital/urunday, src/registros.rs).
 */
class NameCheck {

  public const COUNTRIES = ['ar', 'us'];

  public const DEFAULT_TLDS = ['com', 'com.ar', 'io', 'ar'];

  /**
   * What WHOIS servers say for a name nobody holds (.ar .cl .mx .de .co .io).
   */
  private const WHOIS_FREE = ['no entries found', 'not found', 'no match', 'status: free', 'object_not_found', 'no data found', 'no se encuentra registrado'];

  /**
   * TLDs whose RDAP cannot be trusted: rdap.nic.ar hangs on some names
   * (yzur.com.ar, kalibang.ar) and answers one query at a time; its WHOIS
   * answers at once.
   */
  private const WHOIS_FIRST = ['ar'];

  private const INPI = 'https://portaltramites.inpi.gob.ar/MarcasConsultas/GrillaMarcasAvanzada';

  private const USPTO = 'https://tmsearch.uspto.gov/prod-stage-v1-0-0/tmsearch';

  /**
   * Results asked of each trademark office; the total says if there are more.
   */
  private const MAX = 500;

  public function __construct(
    private readonly ClientInterface $http,
    private readonly ?CacheBackendInterface $cache = NULL,
  ) {}

  /**
   * Domains and trademarks at once: the HTTP lookups run in parallel.
   *
   * @param list<string> $tlds
   * @param list<string> $countries
   * @param list<int> $classes
   *   Nice classes to keep; empty keeps all.
   */
  public function check(string $name, array $tlds, array $countries, array $classes): array {
    $domains = array_map(fn($tld) => "$name.$tld", $tlds);
    $rdap = $this->rdapServers();
    $pending = [];
    foreach ($domains as $domain) {
      $tld = self::tld($domain);
      $server = in_array($tld, self::WHOIS_FIRST, TRUE) ? NULL : $rdap[$tld] ?? NULL;
      if ($server) {
        $pending["d:$domain"] = $this->get("$server/domain/$domain");
      }
    }
    foreach ($countries as $country) {
      if ($country === 'us') {
        $pending['t:us'] = $this->usptoRequest($name, $classes);
      }
      // The class goes to the INPI: one query per class, no paging through all.
      foreach ($country === 'ar' ? ($classes ?: [-1]) : [] as $class) {
        $pending["t:ar:$class"] = $this->inpiRequest($name, $class);
      }
    }
    $settled = Utils::settle($pending)->wait();

    $out = ['name' => $name, 'domains' => [], 'trademarks' => []];
    foreach ($domains as $domain) {
      $result = isset($settled["d:$domain"]) ? self::rdapResult($domain, $settled["d:$domain"]) : NULL;
      // No RDAP, or it failed: the registry's WHOIS.
      $out['domains'][] = $result && $result['status'] !== 'unknown' ? $result : $this->whois($domain) + array_filter(['rdap_error' => $result['error'] ?? NULL]);
    }
    foreach ($countries as $country) {
      try {
        $found = ['total' => 0, 'marks' => []];
        foreach ($pending as $key => $_) {
          if (!str_starts_with($key, "t:$country")) {
            continue;
          }
          $r = $settled[$key];
          $response = $r['state'] === 'fulfilled' ? $r['value'] : throw $r['reason'];
          $part = $country === 'ar' ? self::inpiMarks($response) : self::usptoMarks($response);
          $found = ['total' => $found['total'] + $part['total'], 'marks' => [...$found['marks'], ...$part['marks']]];
        }
        $out['trademarks'][$country] = self::summary($found, $classes);
      }
      catch (\Throwable $e) {
        $out['trademarks'][$country] = ['error' => self::reason($e)];
      }
    }
    $out['note'] = 'Trademarks whose name CONTAINS the text; try the root and variants too. Whether two marks are confusingly similar is for a trademark attorney to say.';
    return $out;
  }

  /**
   * TLD => RDAP base URL, from IANA's bootstrap, cached for a day.
   *
   * @return array<string, string>
   */
  public function rdapServers(): array {
    if ($hit = $this->cache?->get('ombu:rdap_bootstrap')) {
      return $hit->data;
    }
    $data = json_decode((string) $this->http->request('GET', 'https://data.iana.org/rdap/dns.json', ['timeout' => 20])->getBody(), TRUE);
    $servers = [];
    foreach ($data['services'] ?? [] as [$tlds, $urls]) {
      foreach ($tlds as $tld) {
        $servers[$tld] = rtrim($urls[0], '/');
      }
    }
    if (!$servers) {
      throw new \RuntimeException("IANA's RDAP bootstrap changed shape.");
    }
    $this->cache?->set('ombu:rdap_bootstrap', $servers, time() + 86400);
    return $servers;
  }

  private function get(string $url): PromiseInterface {
    return $this->http->requestAsync('GET', $url, ['http_errors' => FALSE, 'timeout' => 8, 'headers' => ['Accept' => 'application/rdap+json']]);
  }

  private static function rdapResult(string $domain, array $settled): array {
    if ($settled['state'] !== 'fulfilled') {
      return ['domain' => $domain, 'status' => 'unknown', 'source' => 'rdap', 'error' => self::reason($settled['reason'])];
    }
    $response = $settled['value'];
    return match ($response->getStatusCode()) {
      404 => ['domain' => $domain, 'status' => 'free', 'source' => 'rdap'],
      200 => (function () use ($domain, $response) {
        $data = json_decode((string) $response->getBody(), TRUE) ?: [];
        $expires = NULL;
        foreach ($data['events'] ?? [] as $event) {
          if (($event['eventAction'] ?? '') === 'expiration') {
            $expires = substr((string) $event['eventDate'], 0, 10);
          }
        }
        return ['domain' => $domain, 'status' => 'registered', 'expires' => $expires, 'source' => 'rdap'];
      })(),
      default => ['domain' => $domain, 'status' => 'unknown', 'source' => 'rdap', 'error' => 'HTTP ' . $response->getStatusCode()],
    };
  }

  /**
   * A TLD without RDAP: ask IANA's WHOIS for its server, then that server.
   */
  private function whois(string $domain): array {
    $tld = self::tld($domain);
    try {
      $iana = self::whoisQuery('whois.iana.org', $tld);
      $server = preg_match('/^whois:\s*(\S+)/m', $iana, $m) ? $m[1] : NULL;
      if (!$server && str_contains($iana, 'returned 0 objects')) {
        return ['domain' => $domain, 'status' => 'unknown', 'source' => 'none', 'error' => "There is no .$tld TLD."];
      }
      if (!$server) {
        return ['domain' => $domain, 'status' => 'unknown', 'source' => 'none', 'error' => ".$tld has neither RDAP nor WHOIS: ask its registry."];
      }
      $text = self::whoisQuery($server, $domain);
      $status = self::whoisStatus($text);
      // .ar: "expire: 2027-07-06 …"; gTLDs: "Registry Expiry Date: 2027-…".
      $expires = $status === 'registered' && preg_match('/^\s*(?:expire|registry expiry date|expiration date):\s*(\d{4}-\d{2}-\d{2})/mi', $text, $m) ? $m[1] : NULL;
      return ['domain' => $domain, 'status' => $status] + ($expires ? ['expires' => $expires] : []) + ['source' => "whois $server"];
    }
    catch (\RuntimeException $e) {
      // Some registries (.es, .com.uy) drop port 43 from abroad.
      return ['domain' => $domain, 'status' => 'unknown', 'source' => 'whois', 'error' => $e->getMessage()];
    }
  }

  private static function whoisQuery(string $server, string $query): string {
    $socket = @fsockopen($server, 43, $errno, $error, 8);
    if (!$socket) {
      throw new \RuntimeException("WHOIS $server: $error");
    }
    stream_set_timeout($socket, 10);
    fwrite($socket, "$query\r\n");
    $text = (string) stream_get_contents($socket, 256 * 1024);
    fclose($socket);
    return $text;
  }

  /**
   * Free only on an explicit "not found"; an empty answer says nothing.
   */
  public static function whoisStatus(string $text): string {
    $lower = strtolower($text);
    foreach (self::WHOIS_FREE as $phrase) {
      if (str_contains($lower, $phrase)) {
        return 'free';
      }
    }
    $data = array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== '' && $l[0] !== '%' && $l[0] !== '#');
    return $data ? 'registered' : 'unknown';
  }

  private function inpiRequest(string $text, int $class): PromiseInterface {
    return $this->http->requestAsync('POST', self::INPI, [
      'form_params' => [
        'Tipo_Resolucion' => '', 'Clase' => (string) $class, 'TipoBusquedaDenominacion' => '1', 'Denominacion' => $text,
        'Titular' => '', 'TipoBusquedaTitular' => '0', 'vigentes' => 'false', 'limit' => self::MAX, 'offset' => 0,
      ],
      'timeout' => 30,
    ]);
  }

  /**
   * @return array{total: int, marks: list<array>}
   */
  public static function inpiMarks(ResponseInterface $response): array {
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data['rows'] ?? NULL)) {
      throw new \RuntimeException("INPI's search changed shape.");
    }
    $now = time() * 1000;
    $marks = [];
    foreach ($data['rows'] as $r) {
      $expires = preg_match('~^/Date\((-?\d+)\)/$~', (string) ($r['Fecha_Vencimiento'] ?? ''), $m) && (int) $m[1] > 0 ? (int) $m[1] : NULL;
      [$status, $live] = match ($r['Estado'] ?? NULL) {
        'C' => $expires > $now ? ['granted', TRUE] : ['granted, expired', FALSE],
        // No decision yet: the INPI sends no status at all.
        'E', NULL, '' => ['pending', TRUE],
        'A' => ['abandoned', FALSE],
        'CA' => ['lapsed', FALSE],
        'N' => ['refused', FALSE],
        'D' => ['withdrawn', FALSE],
        'NU' => ['void', FALSE],
        default => ['unknown', FALSE],
      };
      $marks[] = [
        'id' => (string) ($r['Acta'] ?? ''),
        'class' => (int) ($r['Clase'] ?? 0),
        'mark' => self::clean((string) ($r['Denominacion'] ?? '')),
        'owners' => self::clean((string) ($r['Titulares'] ?? '')),
        'status' => $status,
        'live' => $live,
      ];
    }
    return ['total' => (int) ($data['total'] ?? count($marks)), 'marks' => $marks];
  }

  /**
   * Live marks only, the classes in the query: filtering after the cap of
   * MAX results lost marks ("agent" in class 42: 17 live of 1,745).
   */
  private function usptoRequest(string $text, array $classes): PromiseInterface {
    // Letters and digits only: the text goes into an Elasticsearch query_string.
    $words = array_map(fn($w) => '*' . mb_strtolower($w) . '*', preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    $query = 'wordmark:(' . implode(' AND ', $words) . ') AND alive:true';
    if ($classes) {
      $query .= ' AND (' . implode(' OR ', array_map(fn($c) => sprintf('internationalClass:"IC %03d"', $c), $classes)) . ')';
    }
    return $this->http->requestAsync('POST', self::USPTO, [
      'json' => [
        'query' => ['query_string' => ['query' => $query]],
        'size' => self::MAX,
        '_source' => ['wordmark', 'alive', 'registrationDate', 'internationalClass', 'ownerName', 'id'],
      ],
      'timeout' => 30,
    ]);
  }

  /**
   * One mark per class, as the INPI lists them.
   *
   * @return array{total: int, marks: list<array>}
   */
  public static function usptoMarks(ResponseInterface $response): array {
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data['hits']['hits'] ?? NULL)) {
      throw new \RuntimeException("The USPTO's search changed shape.");
    }
    $marks = [];
    foreach ($data['hits']['hits'] as $hit) {
      $s = $hit['source'] ?? [];
      $live = (bool) ($s['alive'] ?? FALSE);
      // "IC 035"; old US classes ("IC A") become 0.
      $classes = array_map(fn($c) => (int) preg_replace('/\D/', '', (string) $c), $s['internationalClass'] ?? []) ?: [0];
      foreach ($classes as $class) {
        $marks[] = [
          'id' => (string) ($s['id'] ?? $hit['id'] ?? ''),
          'class' => $class,
          'mark' => self::clean((string) ($s['wordmark'] ?? '(design only)')),
          'owners' => implode(' - ', $s['ownerName'] ?? []),
          'status' => $live ? (empty($s['registrationDate']) ? 'pending' : 'registered') : 'dead',
          'live' => $live,
        ];
      }
    }
    return ['total' => (int) ($data['hits']['totalValue'] ?? count($marks)), 'marks' => $marks];
  }

  /**
   * The live marks in the classes asked, and how many there are of each.
   */
  public static function summary(array $found, array $classes): array {
    $marks = array_values(array_filter($found['marks'], fn($m) => !$classes || in_array($m['class'], $classes, TRUE)));
    $live = array_values(array_filter($marks, fn($m) => $m['live']));
    usort($live, fn($a, $b) => [$a['class'], $a['id']] <=> [$b['class'], $b['id']]);
    $out = ['found' => count($marks), 'live_count' => count($live), 'live' => array_map(fn($m) => array_diff_key($m, ['live' => 0]), $live)];
    if ($found['total'] > count($found['marks'])) {
      $out['truncated'] = sprintf('%d marks match, %d read: try a longer text or fewer classes.', $found['total'], count($found['marks']));
    }
    return $out;
  }

  private static function tld(string $domain): string {
    return substr($domain, strrpos($domain, '.') + 1);
  }

  private static function clean(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', $s));
  }

  private static function reason(mixed $e): string {
    return $e instanceof \Throwable ? $e->getMessage() : (string) $e;
  }

}
