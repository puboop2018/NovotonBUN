<?php
declare(strict_types=1);
/**
 * Novoton API Interface
 *
 * Contract for the API facade that coordinates domain-specific API clients.
 * Covers hotels, pricing, availability, reservations, and destinations.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\HotelApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\AvailabilityApiClient;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;

interface NovotonApiInterface
{
    // ── Domain client accessors ──

    public function hotels(): HotelApiClient;
    public function pricing(): PricingApiClient;
    public function availability(): AvailabilityApiClient;
    public function reservations(): ReservationApiClient;
    public function destinations(): DestinationApiClient;

    // ── Commission (delegates to CommissionCalculator, not a domain client) ──

    public function applyCommission(float $price): float;

    // ── Debug ──

    public function getLastRequest(): string;
    public function getLastResponse(): string;
    public function getLastRequestFormatted(): array;
    public function getLastError(): string;
    public function getLastResponseRaw(): string;
    public function getLastHttpCode(): int;

    // ── Circuit breaker ──

    public function getCircuitStatus(): array;
    public function resetCircuitBreaker(): void;

    // ── Cache ──

    public function clearCache(?string $function = null): int;
}
