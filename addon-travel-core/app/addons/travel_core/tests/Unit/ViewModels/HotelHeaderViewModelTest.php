<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel;

final class HotelHeaderViewModelTest extends TestCase
{
    public function testToViewArrayCarriesTheComponentContract(): void
    {
        $vm = new HotelHeaderViewModel(
            name: 'Edart Hotel',
            stars: 4,
            locationLine: 'Durres, Albania',
            mapUrl: 'https://www.google.com/maps?q=1,2',
            productId: 6,
        );

        self::assertSame([
            'name' => 'Edart Hotel',
            'stars' => 4,
            'location_line' => 'Durres, Albania',
            'map_url' => 'https://www.google.com/maps?q=1,2',
            'product_id' => 6,
        ], $vm->toViewArray());
    }

    public function testStarsAreClampedToZeroThroughFive(): void
    {
        self::assertSame(5, (new HotelHeaderViewModel('H', 9, '', '', 0))->stars);
        self::assertSame(0, (new HotelHeaderViewModel('H', -2, '', '', 0))->stars);
    }

    public function testBlankNameFallsBackToHotel(): void
    {
        // Mirrors the old templates' |default:'Hotel' behaviour.
        self::assertSame('Hotel', (new HotelHeaderViewModel('   ', 0, '', '', 0))->name);
        self::assertSame('Hotel', (new HotelHeaderViewModel('', 0, '', '', 0))->name);
    }

    public function testNegativeProductIdIsTreatedAsUnknown(): void
    {
        self::assertSame(0, (new HotelHeaderViewModel('H', 0, '', '', -5))->productId);
    }
}
