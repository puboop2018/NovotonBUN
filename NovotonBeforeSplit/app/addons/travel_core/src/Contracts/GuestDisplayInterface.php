<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Guest Display Interface
 *
 * Responsible for formatting guest data for display purposes
 * (cart, checkout, order pages, emails).
 */
interface GuestDisplayInterface
{
    /**
     * Build comma-separated guest list
     *
     * @param array $guests_data Guests data (keyed array)
     * @return string Guest list
     */
    public function buildGuestList(array $guests_data): string;

    /**
     * Get holder name from guests data
     *
     * @param array $guests_data Guests data
     * @param array $bookingData Fallback booking data
     * @return string Holder name
     */
    public function getHolderName(array $guests_data, array $bookingData = []): string;

    /**
     * Format guests for display
     *
     * @param array $guests_data Guests data
     * @return array Display-formatted guests
     */
    public function formatForDisplay(array $guests_data): array;
}
