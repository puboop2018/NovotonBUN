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
     * @param array $guests_data Guests data
     * @return array Guests by room [room_num => [guests]]
     */
    public function getGuestsByRoom(array $guests_data): array;

    /**
     * Get guest counts per room
     *
     * @param array $guests_data Guests data
     * @return array Room counts [room_num => [adults, children]]
     */
    public function getRoomCounts(array $guests_data): array;

    /**
     * Merge guest data from multiple sources
     *
     * @param array $sources Array of guest data sources
     * @return array Merged guests data
     */
    public function merge(array ...$sources): array;
}
