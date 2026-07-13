<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Exceptions\GeocodeTransportException;
use Tygh\Addons\NovotonHolidays\Services\NominatimClient;

/**
 * NominatimClient with an injected transport: street extraction rules
 * (road + house_number in Romanian postal order, road-only, pedestrian /
 * neighbourhood fallbacks, nothing street-like → null), the request shape
 * (jsonv2 /reverse with coordinates), the policy-mandated User-Agent with
 * the contact email, and the "stop the run" transport-failure signal.
 */
#[CoversClass(NominatimClient::class)]
final class NominatimClientTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    /** @var list<string> */
    private array $agents = [];

    /**
     * @param array<string, mixed> $payload
     */
    private function client(int $code, array $payload, string $email = 'ops@example.com'): NominatimClient
    {
        return $this->clientRaw($code, (string) json_encode($payload), $email);
    }

    private function clientRaw(int $code, string $body, string $email = 'ops@example.com'): NominatimClient
    {
        return new NominatimClient(
            function (string $url, string $userAgent) use ($code, $body): array {
                $this->urls[] = $url;
                $this->agents[] = $userAgent;

                return [$code, $body];
            },
            'https://nominatim.example.org',
            $email,
        );
    }

    public function testRoadWithHouseNumberUsesRomanianPostalOrder(): void
    {
        $street = $this->client(200, [
            'address' => ['road' => 'Strada Mihai Eminescu', 'house_number' => '12', 'city' => 'Golem'],
        ])->reverse(41.2818, 19.4416);

        self::assertSame('Strada Mihai Eminescu 12', $street);
    }

    public function testRoadOnly(): void
    {
        $street = $this->client(200, ['address' => ['road' => 'Rruga e Plazhit']])
            ->reverse(41.2818, 19.4416);

        self::assertSame('Rruga e Plazhit', $street);
    }

    public function testPedestrianFallbackWhenNoRoad(): void
    {
        $street = $this->client(200, ['address' => ['pedestrian' => 'Promenada Mamaia']])
            ->reverse(44.25, 28.61);

        self::assertSame('Promenada Mamaia', $street);
    }

    public function testNeighbourhoodFallbackWhenNoWayAtAll(): void
    {
        $street = $this->client(200, ['address' => ['neighbourhood' => 'Sunny Beach Central']])
            ->reverse(42.69, 27.71);

        self::assertSame('Sunny Beach Central', $street);
    }

    public function testNothingStreetLikeGivesNull(): void
    {
        $street = $this->client(200, [
            'address' => ['city' => 'Golem', 'country' => 'Albania', 'postcode' => '2504'],
        ])->reverse(41.2818, 19.4416);

        self::assertNull($street);
    }

    public function testRequestShapeAndUserAgentCarryPolicyRequirements(): void
    {
        $this->client(200, ['address' => []])->reverse(41.2818, 19.4416);

        self::assertCount(1, $this->urls);
        $url = $this->urls[0];
        self::assertStringStartsWith('https://nominatim.example.org/reverse?format=jsonv2', $url);
        self::assertStringContainsString('lat=41.2818000', $url);
        self::assertStringContainsString('lon=19.4416000', $url);
        self::assertStringContainsString('accept-language=ro', $url);

        self::assertSame('NovotonHolidays/1.0 (ops@example.com)', $this->agents[0]);
    }

    public function testUserAgentWithoutContactHasNoEmptyParens(): void
    {
        $client = $this->clientRaw(200, '{}', '');

        self::assertSame('NovotonHolidays/1.0', $client->getUserAgent());
    }

    public function testNon200IsAStopSignal(): void
    {
        $this->expectException(GeocodeTransportException::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->client(429, [])->reverse(41.0, 19.0);
    }

    public function testUnparseableBodyIsAStopSignal(): void
    {
        $this->expectException(GeocodeTransportException::class);

        $this->clientRaw(200, '<html>Bandwidth limit exceeded</html>')->reverse(41.0, 19.0);
    }
}
