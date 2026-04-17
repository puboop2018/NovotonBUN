<?php

declare(strict_types=1);

/**
 * Novoton API Kit — domain accessor contract.
 *
 * A focused contract that exposes only the five domain sub-clients plus a
 * single debugInfo() accessor. Callers should type-hint this (or a single
 * sub-client interface) rather than the concrete NovotonApi facade.
 *
 * The previous bulky NovotonApiInterface — which carried 29 flat delegate
 * methods for backward compatibility — was retired in PR #10 once every
 * addon callsite was migrated to the sub-client accessors.
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

use Tygh\Addons\TravelCore\ValueObjects\RequestDebugInfo;

interface NovotonApiKitInterface
{
    public function hotels(): HotelApiClientInterface;

    public function pricing(): PricingApiClientInterface;

    public function availability(): AvailabilityApiClientInterface;

    public function reservations(): ReservationApiClientInterface;

    public function destinations(): DestinationApiClientInterface;

    /**
     * Immutable snapshot of the most recent API request/response state.
     *
     * Diagnostic / test-harness callers use this to inspect the raw XML
     * request, response, HTTP code, and last error after an API call,
     * without reaching for the legacy public `$lastRequest` / `$lastResponse`
     * / `$lastHttpCode` / `$lastError` properties on the concrete facade.
     */
    public function debugInfo(): RequestDebugInfo;
}
