<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Guest Data Service Interface
 *
 * Composite contract for guest data parsing, formatting, and validation.
 * Extends focused interfaces (GuestParserInterface, GuestDisplayInterface,
 * GuestValidatorInterface) so consumers can type-hint the narrower interface
 * when they only need a subset of capabilities.
 *
 * Provider-agnostic — handles canonical guest data formats.
 */
interface GuestDataServiceInterface extends GuestParserInterface, GuestDisplayInterface, GuestValidatorInterface
{
    /**
     * Get guests grouped by room
     *
     * @param array<string, mixed> $guests_data Guests data
     * @return array<int, array<int, array<string, mixed>>> Guests by room [room_num => [guests]]
     */
    public function getGuestsByRoom(array $guests_data): array;

    /**
     * Get guest counts per room
     *
     * @param array<string, mixed> $guests_data Guests data
     * @return array<int, array{adults: int, children: int}> Room counts [room_num => [adults, children]]
     */
    public function getRoomCounts(array $guests_data): array;

    /**
     * Merge guest data from multiple sources
     *
     * @param array<string, mixed> $sources Array of guest data sources
     * @return array<string, mixed> Merged guests data
     */
    public function merge(array ...$sources): array;

    /**
     * Parse date of birth from guest form data.
     *
     * @param array<string, mixed> $guest Guest form data
     * @return string YYYY-MM-DD or '' if invalid/missing
     */
    public static function parseDob(array $guest): string;

    /**
     * Parse and validate guest data from a booking form submission.
     *
     * @param array<string, mixed> $guests Raw guests array from form
     * @param string $checkIn Check-in date for child age validation
     * @param string $provider Provider name for log messages
     * @return array<string, mixed>|false Parsed result or false if validation fails
     */
    public static function parseAndValidateGuests(
        array $guests,
        string $checkIn = '',
        string $provider = '',
    ): array|false;
}
