<?php
declare(strict_types=1);
/**
 * Novoton API Kit — domain accessor contract.
 *
 * A focused contract that exposes only the five domain sub-clients. New callers
 * should type-hint this (or a single sub-client interface) instead of the bulky
 * legacy `NovotonApiInterface`, which still carries 29 deprecated flat delegate
 * methods for backward compatibility.
 *
 * Migration path:
 *     // Legacy — pulls in 46 methods, couples the caller to the facade:
 *     public function __construct(NovotonApi $api) { ... }
 *     $api->getRoomPrice($params);
 *
 *     // Preferred — pulls in only the domain(s) the caller actually needs:
 *     public function __construct(PricingApiClientInterface $pricing) { ... }
 *     $pricing->getRoomPrice($params);
 *
 *     // Intermediate — useful when a caller needs several domains:
 *     public function __construct(NovotonApiKitInterface $api) { ... }
 *     $api->pricing()->getRoomPrice($params);
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface NovotonApiKitInterface
{
    public function hotels(): HotelApiClientInterface;

    public function pricing(): PricingApiClientInterface;

    public function availability(): AvailabilityApiClientInterface;

    public function reservations(): ReservationApiClientInterface;

    public function destinations(): DestinationApiClientInterface;
}
