<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;

/**
 * Provider-specific bridge between a CS-Cart product and a hotel record.
 *
 * Each provider addon (novoton_holidays, sphinx_holidays) registers an
 * implementation with TravelProviderRegistry::setHotelProductProvider().
 * travel_core calls TravelProviderRegistry::resolveProductOwner() and
 * iterates registered providers — zero provider-specific SQL stays in core.
 */
interface HotelProductProviderInterface
{
    /**
     * Try to claim a product as a hotel for this provider.
     *
     * The implementation must return null (not throw) when the product
     * does not belong to this provider.
     *
     * @param int $productId CS-Cart product_id
     * @param string $productCode CS-Cart product_code (may be empty)
     * @return HotelSeoData|null null when this provider does not own the product
     */
    public function resolveProduct(int $productId, string $productCode): ?HotelSeoData;

    /**
     * Whether this provider owns the given (provider-native) hotel id.
     *
     * Used by the generic booking dispatcher to resolve a provider from a
     * bare hotel_id when no product context is available. Must return false
     * (not throw) for ids this provider does not recognise.
     */
    public function ownsHotelId(string $hotelId): bool;
}
