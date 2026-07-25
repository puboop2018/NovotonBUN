<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Hooks\OrderHooks;
use Tygh\Addons\SphinxHolidays\Hooks\UserHooks;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Behavioural coverage for the hook bodies moved out of func.php:
 * calculate_cart_items' stored-price preservation and the login/register
 * session-booking link. Cart fixtures use NUMERIC cart ids (real CS-Cart
 * carts key products by int hashes).
 */
#[CoversClass(OrderHooks::class)]
#[CoversClass(UserHooks::class)]
final class HookBodiesTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $query, ...$params): int {
            $this->queries[] = [$query, $params];

            return 0;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testPreserveStoredPricesResetsSphinxLinesToBasePrice(): void
    {
        $cart = [
            'products' => [
                101 => [
                    'price' => 999.0,
                    'base_price' => 450.0,
                    'stored_price' => 'Y',
                    'extra' => ['sphinx_booking' => 1],
                ],
                102 => [
                    'price' => 20.0,
                    'base_price' => 15.0,
                    'stored_price' => 'Y',
                    'extra' => [],
                ],
            ],
        ];

        OrderHooks::preserveStoredPrices($cart);

        self::assertSame(450.0, $cart['products'][101]['price'], 'sphinx line snaps back to the stored base price');
        self::assertSame(20.0, $cart['products'][102]['price'], 'non-sphinx lines are untouched');
    }

    public function testPreserveStoredPricesIgnoresNonArrayAndEmptyCarts(): void
    {
        $notArray = 'nope';
        OrderHooks::preserveStoredPrices($notArray);
        self::assertSame('nope', $notArray);

        $empty = ['products' => []];
        OrderHooks::preserveStoredPrices($empty);
        self::assertSame(['products' => []], $empty);
    }

    public function testLinkSessionBookingsUpdatesSessionOwnedRows(): void
    {
        UserHooks::linkSessionBookings('42', 'sess-abc');

        self::assertNotEmpty($this->queries, 'the repository must be reached');
        [$sql, $params] = $this->queries[0];
        self::assertSame(
            'UPDATE ?:sphinx_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0',
            $sql,
        );
        self::assertSame([42, 'sess-abc'], $params);
    }

    public function testLinkSessionBookingsSkipsAnonymousAndSessionlessCalls(): void
    {
        UserHooks::linkSessionBookings(0, 'sess-abc');
        UserHooks::linkSessionBookings('42', ''); // explicit empty session
        self::assertSame([], $this->queries, 'no db work without a real user id and session');
    }
}
