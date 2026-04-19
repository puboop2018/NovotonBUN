<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;

/**
 * Unit coverage for the pure-state surface of SphinxHttpClient.
 *
 * The `request()` body wraps curl_init/curl_exec inline — network-level
 * coverage is deferred until that seam is extracted into a transport
 * interface. The tests here poke the stateful fields via reflection to
 * exercise the circuit-breaker + rate-limit header parsing logic without
 * any HTTP traffic.
 */
#[CoversClass(SphinxHttpClient::class)]
class SphinxHttpClientTest extends TestCase
{
    private const int CB_THRESHOLD = 5;
    private const int CB_TIMEOUT = 60;

    private function makeClient(): SphinxHttpClient
    {
        return new SphinxHttpClient(
            baseUrl: 'https://api.example.com',
            apiKey: 'test-key',
            maxRetries: 3,
            retryDelayMs: 500,
            retryMultiplier: 2.0,
            cbThreshold: self::CB_THRESHOLD,
            cbTimeout: self::CB_TIMEOUT,
            debugLogging: false,
        );
    }

    private function setPrivate(object $instance, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($instance, $prop);
        $ref->setValue($instance, $value);
    }

    private function getPrivate(object $instance, string $prop): mixed
    {
        $ref = new \ReflectionProperty($instance, $prop);
        return $ref->getValue($instance);
    }

    // ── constructor ─────────────────────────────────────────────────────────

    public function testConstructorStripsTrailingSlashFromBaseUrl(): void
    {
        $client = new SphinxHttpClient('https://api.example.com/', 'k');
        $this->assertSame('https://api.example.com', $this->getPrivate($client, 'baseUrl'));

        $noSlash = new SphinxHttpClient('https://api.example.com', 'k');
        $this->assertSame('https://api.example.com', $this->getPrivate($noSlash, 'baseUrl'));
    }

    public function testConstructorDefaultsAreExposedByAccessors(): void
    {
        $client = $this->makeClient();
        $this->assertSame(self::CB_TIMEOUT, $client->getCircuitBreakerTimeout());
        $this->assertSame(0, $client->getLastHttpCode());
        $this->assertSame('', $client->getLastError());
        $this->assertNull($client->getLastResponseRaw());
        $this->assertNull($client->getRateLimitLimit());
        $this->assertNull($client->getRateLimitRemaining());
        $this->assertNull($client->getRateLimitReset());
        $this->assertSame(0, $client->getRateLimitHitCount());
    }

    // ── isCircuitOpen ───────────────────────────────────────────────────────

    public function testIsCircuitOpenFalseWhenFailureCountBelowThreshold(): void
    {
        $client = $this->makeClient();
        $this->setPrivate($client, 'failureCount', self::CB_THRESHOLD - 1);
        $this->assertFalse($client->isCircuitOpen());
    }

    public function testIsCircuitOpenTrueWhenThresholdReachedAndWithinTimeout(): void
    {
        $client = $this->makeClient();
        $this->setPrivate($client, 'failureCount', self::CB_THRESHOLD);
        // Opened one second ago — well inside the 60s timeout.
        $this->setPrivate($client, 'circuitOpenedAt', time() - 1);

        $this->assertTrue($client->isCircuitOpen());
    }

    public function testIsCircuitOpenResetsFailureCountAfterTimeout(): void
    {
        $client = $this->makeClient();
        $this->setPrivate($client, 'failureCount', self::CB_THRESHOLD);
        // Opened long before the timeout window — should half-open.
        $this->setPrivate($client, 'circuitOpenedAt', time() - (self::CB_TIMEOUT + 5));

        $this->assertFalse($client->isCircuitOpen());
        $this->assertSame(0, $this->getPrivate($client, 'failureCount'));
    }

    // ── parseResponseHeaders (private, invoked via reflection) ──────────────

    private function invokeParseHeader(SphinxHttpClient $client, string $header): int
    {
        $ch = curl_init();
        try {
            $ref = new \ReflectionMethod($client, 'parseResponseHeaders');
            /** @var int $result */
            $result = $ref->invoke($client, $ch, $header);
            return $result;
        } finally {
            curl_close($ch);
        }
    }

    public function testParseResponseHeadersExtractsRateLimitHeaders(): void
    {
        $client = $this->makeClient();

        $this->invokeParseHeader($client, "X-RateLimit-Limit: 100\r\n");
        $this->invokeParseHeader($client, "X-RateLimit-Remaining: 42\r\n");
        $this->invokeParseHeader($client, "X-RateLimit-Reset: 1700000000\r\n");

        $this->assertSame(100, $client->getRateLimitLimit());
        $this->assertSame(42, $client->getRateLimitRemaining());
        $this->assertSame(1700000000, $client->getRateLimitReset());
    }

    public function testParseResponseHeadersCaseInsensitive(): void
    {
        $client = $this->makeClient();
        $this->invokeParseHeader($client, "x-ratelimit-limit: 50\r\n");
        $this->assertSame(50, $client->getRateLimitLimit());
    }

    public function testParseResponseHeadersExtractsRetryAfter(): void
    {
        $client = $this->makeClient();
        $this->invokeParseHeader($client, "Retry-After: 30\r\n");
        $this->assertSame(30, $this->getPrivate($client, 'retryAfter'));
    }

    public function testParseResponseHeadersIgnoresMalformedHeaders(): void
    {
        $client = $this->makeClient();
        // No colon at all — split into a single-part array, skipped entirely.
        $this->invokeParseHeader($client, "malformed-line\r\n");
        $this->assertNull($client->getRateLimitLimit());
        $this->assertNull($client->getRateLimitRemaining());
    }

    public function testParseResponseHeadersIgnoresUnknownHeaders(): void
    {
        $client = $this->makeClient();
        $this->invokeParseHeader($client, "Content-Type: application/json\r\n");
        $this->invokeParseHeader($client, "X-Request-Id: abc123\r\n");

        $this->assertNull($client->getRateLimitLimit());
        $this->assertNull($client->getRateLimitRemaining());
        $this->assertNull($client->getRateLimitReset());
    }

    public function testParseResponseHeadersReturnsHeaderByteLength(): void
    {
        $client = $this->makeClient();
        $header = "X-RateLimit-Limit: 100\r\n";
        $this->assertSame(strlen($header), $this->invokeParseHeader($client, $header));
    }

    // ── getRateLimitState ───────────────────────────────────────────────────

    public function testGetRateLimitStateReturnsNullsByDefault(): void
    {
        $state = $this->makeClient()->getRateLimitState();

        $this->assertSame([
            'limit' => null,
            'remaining' => null,
            'reset' => null,
            'reset_in' => null,
        ], $state);
    }

    public function testGetRateLimitStateComputesResetInWhenResetSet(): void
    {
        $client = $this->makeClient();
        $this->setPrivate($client, 'rateLimitLimit', 100);
        $this->setPrivate($client, 'rateLimitRemaining', 42);
        $this->setPrivate($client, 'rateLimitReset', time() + 60);

        $state = $client->getRateLimitState();
        $this->assertSame(100, $state['limit']);
        $this->assertSame(42, $state['remaining']);
        $this->assertIsInt($state['reset_in']);
        // Should be close to 60 — allow a couple of seconds of drift.
        $this->assertGreaterThanOrEqual(58, $state['reset_in']);
        $this->assertLessThanOrEqual(60, $state['reset_in']);
    }

    public function testGetRateLimitStateClampsResetInToZeroWhenResetInPast(): void
    {
        $client = $this->makeClient();
        $this->setPrivate($client, 'rateLimitReset', time() - 30);

        $this->assertSame(0, $client->getRateLimitState()['reset_in']);
    }
}
