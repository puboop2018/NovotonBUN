<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface;

/**
 * Guest Data Service
 *
 * Handles guest data parsing, formatting, and validation.
 * Supports both single and multi-room bookings.
 * Provider-agnostic — used by all travel providers.
 */
class GuestDataService implements GuestDataServiceInterface
{
    private readonly GuestDataNormalizer $normalizer;

    public function __construct(?GuestDataNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new GuestDataNormalizer();
    }

    /**
     * Parse guests data from booking form.
     *
     * Accepts any supported format (keyed, indexed-array, or JSON string)
     * and always returns canonical keyed format via GuestDataNormalizer.
     *
     * @param array $bookingData Booking form data
     * @return array Parsed guests data in canonical keyed format
     */
    public function parseGuestsData(array $bookingData): array
    {
        // Primary source: guests_data field
        if (!empty($bookingData['guests_data'])) {
            $normalized = $this->normalizer->normalize($bookingData['guests_data']);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        // Fallback: guests array (legacy format)
        if (!empty($bookingData['guests'])) {
            return $this->normalizer->normalize($bookingData['guests']);
        }

        return [];
    }

    /**
     * Format name for API (FirstName LastName)
     *
     * @param array $guest Guest data
     * @return string Formatted API name
     */
    public function formatApiName(array $guest): string
    {
        // If already has api_name, use it
        if (!empty($guest['api_name'])) {
            return $guest['api_name'];
        }

        // Build from first/last name
        $first = trim($guest['first_name'] ?? '');
        $last = trim($guest['last_name'] ?? '');

        if ($first && $last) {
            return $first . ' ' . $last;
        }

        // Fall back to name field
        return trim($guest['name'] ?? 'Guest');
    }

    /**
     * Build comma-separated guest list
     *
     * @param array $guests_data Guests data (keyed array)
     * @return string Guest list
     */
    public function buildGuestList(array $guests_data): string
    {
        $names = [];

        foreach ($guests_data as $guest) {
            if (is_array($guest)) {
                $name = $guest['api_name'] ?? $guest['name'] ?? '';
                if (!empty($name)) {
                    $names[] = $name;
                }
            }
        }

        return implode(', ', $names);
    }

    /**
     * Get holder name from guests data
     *
     * @param array $guests_data Guests data
     * @param array $bookingData Fallback booking data
     * @return string Holder name
     */
    public function getHolderName(array $guests_data, array $bookingData = []): string
    {
        // Look for holder flag
        foreach ($guests_data as $guest) {
            if (is_array($guest) && !empty($guest['is_holder'])) {
                $name = trim($guest['api_name'] ?? $guest['name'] ?? '');
                if ($name !== '') {
                    return $name;
                }
            }
        }

        // Look for room1_adult_1 (usually the holder)
        if (isset($guests_data['room1_adult_1']) && is_array($guests_data['room1_adult_1'])) {
            $guest = $guests_data['room1_adult_1'];
            $name = trim($guest['api_name'] ?? $guest['name'] ?? '');
            if ($name !== '') {
                return $name;
            }
        }

        // First guest
        $first = reset($guests_data);
        if (is_array($first)) {
            $name = trim($first['api_name'] ?? $first['name'] ?? '');
            if ($name !== '') {
                return $name;
            }
        }

        // Fallback to booking data
        return $bookingData['holder_name'] ?? 'Guest';
    }

    /**
     * Get guests grouped by room
     *
     * @param array $guests_data Guests data
     * @return array Guests by room [room_num => [guests]]
     */
    public function getGuestsByRoom(array $guests_data): array
    {
        $by_room = [];

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            // Try to get room from data
            $room = $guest['room'] ?? 1;

            // Or parse from key (room1_adult_1)
            if (preg_match('/^room(\d+)_/', $key, $matches)) {
                $room = (int) $matches[1];
            }

            if (!isset($by_room[$room])) {
                $by_room[$room] = [];
            }

            $by_room[$room][] = $guest;
        }

        ksort($by_room);
        return $by_room;
    }

    /**
     * Get guest counts per room
     *
     * @param array $guests_data Guests data
     * @return array Room counts [room_num => [adults, children]]
     */
    public function getRoomCounts(array $guests_data): array
    {
        $by_room = $this->getGuestsByRoom($guests_data);
        $counts = [];

        foreach ($by_room as $room_num => $guests) {
            $adults = 0;
            $children = 0;

            foreach ($guests as $guest) {
                if (($guest['type'] ?? 'adult') === 'child') {
                    $children++;
                } else {
                    $adults++;
                }
            }

            $counts[$room_num] = [
                'adults' => $adults,
                'children' => $children,
            ];
        }

        return $counts;
    }

    /**
     * Format guests for display
     *
     * @param array $guests_data Guests data
     * @return array Display-formatted guests
     */
    public function formatForDisplay(array $guests_data): array
    {
        $display = [];

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $name = $guest['api_name'] ?? $guest['name'] ?? 'Guest';
            $type = ($guest['type'] ?? 'adult') === 'child' ? 'Child' : 'Adult';
            $room = $guest['room'] ?? 1;

            $display[] = [
                'name' => $name,
                'type' => $type,
                'type_lower' => strtolower($type),
                'age' => $guest['age'] ?? null,
                'room' => $room,
                'is_holder' => !empty($guest['is_holder']),
            ];
        }

        return $display;
    }

    /**
     * Validate guests data
     *
     * @param array $guests_data Guests data
     * @param int $expected_adults Expected adult count
     * @param int $expected_children Expected children count
     * @return array Validation result [valid, errors]
     */
    public function validate(array $guests_data, int $expected_adults = 0, int $expected_children = 0): array
    {
        $errors = [];
        $adults = 0;
        $children = 0;

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            // Check name
            $name = $guest['api_name'] ?? $guest['name'] ?? '';
            if (empty($name) || strlen($name) < 2) {
                $errors[] = "Guest '{$key}' has invalid name";
            }

            // Count by type
            if (($guest['type'] ?? 'adult') === 'child') {
                $children++;
                // Children should have age
                if (!isset($guest['age']) || $guest['age'] < 0 || $guest['age'] > 17) {
                    $errors[] = "Child '{$key}' has invalid age";
                }
            } else {
                $adults++;
            }
        }

        // Check counts
        if ($expected_adults > 0 && $adults !== $expected_adults) {
            $errors[] = "Expected {$expected_adults} adults, found {$adults}";
        }

        if ($expected_children > 0 && $children !== $expected_children) {
            $errors[] = "Expected {$expected_children} children, found {$children}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'adults' => $adults,
            'children' => $children,
        ];
    }

    /**
     * Format guests data for order display (email/admin/frontend).
     *
     * Converts api_name (First Last) to display format (Last, First)
     * and marks the holder guest. Shared by travel_core and provider hooks.
     *
     * @param mixed $guests_data Raw guests data (array or JSON string)
     * @param string $holder_name Holder name for matching
     * @return array Formatted guests array, or empty array if input is empty
     */
    public static function formatGuestsForOrderDisplay($guests_data, string $holder_name = ''): array
    {
        if (empty($guests_data)) {
            return [];
        }

        $guests_data = (new GuestDataNormalizer())->normalize($guests_data);
        if (empty($guests_data)) {
            return [];
        }

        $formatted = [];
        $is_first = true;

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $display_name = $guest['display_name'] ?? $guest['name'] ?? '';
            $api_name     = $guest['api_name'] ?? '';

            if (empty($display_name) && !empty($api_name)) {
                $parts = explode(' ', trim($api_name), 2);
                $display_name = count($parts) === 2
                    ? $parts[1] . ', ' . $parts[0]
                    : $api_name;
            }

            $guest_type = $guest['type'] ?? 'adult';
            $is_holder  = false;

            if ($is_first && $guest_type === 'adult') {
                $is_holder = true;
                $is_first  = false;
            } elseif (!empty($holder_name) && str_contains(strtolower($display_name), strtolower($holder_name))) {
                $is_holder = true;
            }

            $formatted[$key] = [
                'display_name' => $display_name,
                'name'         => $guest['name'] ?? $display_name,
                'type'         => $guest_type,
                'age'          => (int)($guest['age'] ?? 0),
                'is_holder'    => $is_holder,
                'birthday'     => $guest['birthday'] ?? '',
                'room'         => $guest['room'] ?? 1,
            ];
        }

        return $formatted;
    }

    /**
     * Merge guest data from multiple sources
     *
     * @param array $sources Array of guest data sources
     * @return array Merged guests data
     */
    public function merge(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $key => $guest) {
                if (!isset($merged[$key]) || empty($merged[$key]['name'])) {
                    $merged[$key] = $guest;
                } else {
                    // Merge non-empty values
                    foreach ($guest as $field => $value) {
                        if (!empty($value) && empty($merged[$key][$field])) {
                            $merged[$key][$field] = $value;
                        }
                    }
                }
            }
        }

        return $merged;
    }
}
