<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Guest Parser Interface
 *
 * Responsible for parsing raw guest data from booking forms
 * and formatting names for API consumption.
 */
interface GuestParserInterface
{
    /**
     * Parse guests data from booking form.
     *
     * @param array<string, mixed> $bookingData Booking form data
     * @return array<string, mixed> Parsed guests data in canonical keyed format
     */
    public function parseGuestsData(array $bookingData): array;

    /**
     * Format name for API (FirstName LastName)
     *
     * @param array<string, mixed> $guest Guest data
     * @return string Formatted API name
     */
    public function formatApiName(array $guest): string;
}
