<?php

namespace Drupal\ash_facet_protection\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware to throttle abusive faceted search requests.
 *
 * Protects against bots that bombard faceted search pages with unique filter
 * combinations, each of which generates an expensive uncached Solr query.
 */
class FacetThrottleMiddleware implements HttpKernelInterface {

  /**
   * Maximum number of facet (f[]) parameters allowed per request.
   */
  const MAX_FACETS = 8;

  /**
   * Maximum faceted requests per IP within the window.
   */
  const RATE_LIMIT = 30;

  /**
   * Rate limit window in seconds.
   */
  const RATE_WINDOW = 60;

  /**
   * Query parameters to strip (tracking params that fragment cache).
   */
  const STRIP_PARAMS = ['srsltid', 'fbclid'];

  /**
   * Cache key for valid facet aliases.
   */
  const ALIAS_CACHE_KEY = 'ash_facet_protection:valid_aliases';

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * Constructs the middleware.
   */
  public function __construct(HttpKernelInterface $http_kernel) {
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    // Only act on main GET requests.
    if ($type !== self::MAIN_REQUEST || $request->getMethod() !== 'GET') {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $facets = $request->query->all('f');
    $hasFacets = !empty($facets);

    // Redirect to strip tracking parameters that fragment cache keys.
    $stripped = FALSE;
    foreach (self::STRIP_PARAMS as $param) {
      if ($request->query->has($param)) {
        $request->query->remove($param);
        $stripped = TRUE;
      }
    }
    if ($stripped) {
      $qs = http_build_query($request->query->all());
      $baseUri = strtok($request->server->get('REQUEST_URI'), '?');
      $cleanUrl = $qs !== '' ? $baseUri . '?' . $qs : $baseUri;
      return new RedirectResponse($cleanUrl, 301);
    }

    if (!$hasFacets) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    // Block requests with too many facet parameters.
    if (count($facets) > self::MAX_FACETS) {
      return new Response('Too many filters.', 429, [
        'Cache-Control' => 'no-store',
        'Retry-After' => '60',
      ]);
    }

    // Reject requests with invalid facet aliases.
    $validAliases = $this->getValidAliases();
    if ($validAliases !== NULL) {
      foreach ($facets as $facet) {
        $alias = strstr($facet, ':', TRUE);
        if ($alias === FALSE || !isset($validAliases[$alias])) {
          return new Response('Invalid filter.', 400, [
            'Cache-Control' => 'no-store',
          ]);
        }
      }
    }

    // Rate limit faceted requests per IP.
    $ip = $request->getClientIp();
    $cacheKey = 'facet_throttle:' . hash('xxh3', $ip);

    try {
      $cache = \Drupal::cache('default');
      $entry = $cache->get($cacheKey);
      $count = $entry ? (int) $entry->data : 0;

      if ($count >= self::RATE_LIMIT) {
        return new Response('Rate limit exceeded.', 429, [
          'Cache-Control' => 'no-store',
          'Retry-After' => (string) self::RATE_WINDOW,
        ]);
      }

      $cache->set($cacheKey, $count + 1, time() + self::RATE_WINDOW);
    }
    catch (\Exception $e) {
      // If cache is unavailable, don't block the request.
    }

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Gets valid facet aliases from config, cached.
   *
   * @return array|null
   *   Associative array keyed by alias, or NULL if unavailable.
   */
  protected function getValidAliases(): ?array {
    try {
      $cache = \Drupal::cache('default');
      $entry = $cache->get(self::ALIAS_CACHE_KEY);
      if ($entry) {
        return $entry->data;
      }

      $aliases = [];
      $configFactory = \Drupal::configFactory();
      foreach ($configFactory->listAll('facets.facet.') as $name) {
        $alias = $configFactory->get($name)->get('url_alias');
        if ($alias) {
          $aliases[$alias] = TRUE;
        }
      }

      // Cache for 1 hour. Invalidated naturally when facets config changes.
      $cache->set(self::ALIAS_CACHE_KEY, $aliases, time() + 3600, ['config:facets.facet']);

      return $aliases;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
