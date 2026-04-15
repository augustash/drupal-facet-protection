<?php

namespace Drupal\Tests\ash_facet_protection\Kernel;

use Drupal\ash_facet_protection\Middleware\FacetThrottleMiddleware;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the FacetThrottleMiddleware.
 *
 * @group ash_facet_protection
 */
class FacetThrottleMiddlewareTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ash_facet_protection'];

  /**
   * The middleware under test.
   */
  protected FacetThrottleMiddleware $middleware;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Pre-seed the alias cache with test aliases.
    // This avoids needing the facets module installed for tests.
    \Drupal::cache('default')->set(FacetThrottleMiddleware::ALIAS_CACHE_KEY, [
      'departments' => TRUE,
      'locations' => TRUE,
      'category' => TRUE,
    ], time() + 3600);

    $innerKernel = new class implements HttpKernelInterface {
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('OK', 200);
      }
    };

    $this->middleware = new FacetThrottleMiddleware($innerKernel);
  }

  /**
   * Tests that requests without facets pass through.
   */
  public function testNoFacetsPassThrough(): void {
    $request = Request::create('/staff-directory');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that valid facets under the limit pass through.
   */
  public function testValidFacetsPassThrough(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15', 'locations:3'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that too many facets returns 429.
   */
  public function testTooManyFacetsBlocked(): void {
    $facets = [];
    for ($i = 0; $i <= FacetThrottleMiddleware::MAX_FACETS; $i++) {
      $facets[] = "departments:$i";
    }
    $request = Request::create('/staff-directory', 'GET', ['f' => $facets]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertEquals('Too many filters.', $response->getContent());
  }

  /**
   * Tests that invalid facet aliases return 400.
   */
  public function testInvalidAliasBlocked(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['fakeparam:123'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Invalid filter.', $response->getContent());
  }

  /**
   * Tests that rate limiting kicks in after threshold.
   */
  public function testRateLimitExceeded(): void {
    // Seed the rate limit cache just below the threshold.
    $ip = '127.0.0.1';
    $cacheKey = 'facet_throttle:' . hash('xxh3', $ip);
    \Drupal::cache('default')->set($cacheKey, FacetThrottleMiddleware::RATE_LIMIT, time() + 60);

    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15'],
    ], [], [], ['REMOTE_ADDR' => $ip]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertEquals('Rate limit exceeded.', $response->getContent());
  }

  /**
   * Tests that srsltid triggers a redirect to the clean URL.
   */
  public function testSrsltidRedirects(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory', $response->headers->get('Location'));
  }

  /**
   * Tests that fbclid triggers a redirect to the clean URL.
   */
  public function testFbclidRedirects(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'fbclid' => 'def456',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory', $response->headers->get('Location'));
  }

  /**
   * Tests that other query params are preserved when tracking params redirect.
   */
  public function testTrackingParamRedirectPreservesOtherParams(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'page' => '2',
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory?page=2', $response->headers->get('Location'));
  }

  /**
   * Tests that gclid is stripped internally, not redirected.
   */
  public function testGclidStrippedInternally(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'gclid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($request->query->has('gclid'));
    $this->assertStringNotContainsString('gclid', $request->server->get('REQUEST_URI'));
  }

  /**
   * Tests that msclkid is stripped internally, not redirected.
   */
  public function testMsclkidStrippedInternally(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'msclkid' => 'def456',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($request->query->has('msclkid'));
    $this->assertStringNotContainsString('msclkid', $request->server->get('REQUEST_URI'));
  }

  /**
   * Tests that analytics strip params preserve other query params.
   */
  public function testAnalyticsStripPreservesOtherParams(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'color' => 'blue',
      'gclid' => 'abc123',
      'gad_source' => '1',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('blue', $request->query->get('color'));
    $this->assertFalse($request->query->has('gclid'));
    $this->assertFalse($request->query->has('gad_source'));
  }

  /**
   * Tests that redirect params take priority over strip params.
   */
  public function testRedirectParamsTakePriority(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'srsltid' => 'abc',
      'gclid' => 'def',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
  }

  /**
   * Tests that POST requests are not affected.
   */
  public function testPostRequestsPassThrough(): void {
    $request = Request::create('/staff-directory', 'POST', [
      'f' => ['fakeparam:999', 'a:1', 'b:2', 'c:3', 'd:4', 'e:5', 'f:6', 'g:7', 'h:8', 'i:9'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

}
