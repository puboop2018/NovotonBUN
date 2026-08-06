<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Providers;

use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\TravelCore\Contracts\HotelProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite implementation of HotelProductProviderInterface.
 *
 * Eurosite hotels are not (yet) materialized as CS-Cart products — search is
 * destination-driven — so product resolution intentionally returns null.
 * ownsHotelId() answers from ?:eurosite_hotels so registry-wide hotel-id
 * resolution (travel_booking dispatcher, admin links) can attribute Eurosite
 * codes correctly.
 */
final class EurositeHotelProductProvider implements HotelProductProviderInterface
{
    private HotelRepository $hotels;

    public function __construct(?HotelRepository $hotels = null)
    {
        $this->hotels = $hotels ?? new HotelRepository();
    }

    #[\Override]
    public function resolveProduct(int $productId, string $productCode): ?HotelSeoData
    {
        return null; // no CS-Cart products for eurosite hotels yet
    }

    #[\Override]
    public function ownsHotelId(string $hotelId): bool
    {
        try {
            return $this->hotels->findByProductCode(TypeCoerce::toString($hotelId)) !== null;
        } catch (\Throwable) {
            return false; // contract: never throw from ownership probes
        }
    }

    #[\Override]
    public function productIdForHotelId(string $hotelId): ?int
    {
        return null; // no product links yet
    }
}
