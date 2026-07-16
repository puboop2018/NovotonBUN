<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Behavioural pins for DestinationRepository::search(), which backs BOTH the
 * destinations admin grid and the whitelist autocomplete: a plain query stays
 * a name LIKE; an all-digits query ALSO matches the exact destination_id (the
 * id the admin grid shows and the hotels sync logs reference), with the LIKE
 * kept so numeric-named destinations remain findable; LIKE wildcards in user
 * input are escaped.
 */
#[CoversClass(DestinationRepository::class)]
final class DestinationSearchTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $selects = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->selects = [];

        DbStub::$getArray = function (string $query, ...$params): array {
            $this->selects[] = [$query, $params];

            return [];
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testNumericQueryAlsoMatchesDestinationId(): void
    {
        (new DestinationRepository())->search(' 168566 ', 200);

        self::assertCount(1, $this->selects);
        [$query, $params] = $this->selects[0];

        self::assertStringContainsString('name LIKE ?l OR destination_id = ?i', $query);
        self::assertSame(['%168566%', 168566, 200], $params);
    }

    public function testTextQueryStaysNameLikeOnly(): void
    {
        (new DestinationRepository())->search('Antalya', 30);

        self::assertCount(1, $this->selects);
        [$query, $params] = $this->selects[0];

        self::assertStringContainsString('WHERE name LIKE ?l ORDER BY', $query);
        self::assertStringNotContainsString('destination_id = ?i', $query);
        self::assertSame(['%Antalya%', 30], $params);
    }

    public function testLikeWildcardsInUserInputAreEscaped(): void
    {
        (new DestinationRepository())->search('50%', 20);

        [, $params] = $this->selects[0];
        // '50%' is not all-digits → LIKE branch, with the % escaped.
        self::assertSame(['%50\\%%', 20], $params);
    }
}
