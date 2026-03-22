<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\GuestDataNormalizerInterface;

/**
 * Guest Data Normalizer
 *
 * Standardizes guest data into the canonical keyed format
 * (e.g. "room1_adult_1", "room1_child_1") regardless of input format.
 *
 * Supported input formats:
 *   - Keyed: {"room1_adult_1": {...}, "room1_child_1": {...}}  (canonical)
 *   - Array: [{name, type, room, ...}, ...]                    (legacy)
 *   - JSON string of either format
 */
class GuestDataNormalizer implements GuestDataNormalizerInterface
{
    /**
     * All fields expected on a canonical guest entry, with defaults.
     */
    private const GUEST_DEFAULTS = [
        'name'       => '',
        'api_name'   => '',
        'first_name' => '',
        'last_name'  => '',
        'type'       => 'adult',
        'age'        => 0,
        'birthday'   => '',
        'dob'        => '',
        'room'       => 1,
        'is_holder'  => 0,
    ];

    /**
     * Normalize guest data from any supported format into canonical keyed format.
     *
     * @param array|string $raw  Raw guest data (JSON string, keyed array, or indexed array)
     * @return array Canonical keyed array (e.g. ['room1_adult_1' => [...], ...])
     */
    public function normalize(array|string $raw): array
    {
        $data = $this->decode($raw);

        if (empty($data) || !is_array($data)) {
            return [];
        }

        if ($this->isKeyedFormat($data)) {
            return $this->ensureFields($data);
        }

        if ($this->isArrayFormat($data)) {
            return $this->convertArrayToKeyed($data);
        }

        // Unknown structure — return with field defaults applied
        return $this->ensureFields($data);
    }

    /**
     * Decode a JSON string or pass through an array unchanged.
     *
     * @param array|string $raw
     * @return array
     */
    public function decode($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Encode canonical guest data to JSON for database storage.
     *
     * Normalizes before encoding to guarantee canonical format in DB.
     *
     * @param array|string $data Guest data in any format
     * @return string JSON string in canonical keyed format
     */
    public function toJson($data): string
    {
        $normalized = $this->normalize($data);
        return !empty($normalized) ? json_encode($normalized) : '{}';
    }

    /**
     * Detect whether data is already in the canonical keyed format.
     *
     * Keyed format uses string keys matching "room{N}_{type}_{I}".
     *
     * @param array $data
     * @return bool
     */
    public function isKeyedFormat(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                return false;
            }
            // At least one key must match the room pattern
            if (preg_match('/^room\d+_(adult|child)_\d+$/', $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether data is in the legacy indexed-array format.
     *
     * Array format uses sequential numeric keys with guest entries.
     *
     * @param array $data
     * @return bool
     */
    public function isArrayFormat(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check if all keys are numeric (sequential array)
        foreach (array_keys($data) as $key) {
            if (!is_int($key)) {
                return false;
            }
        }

        // Check that at least the first element looks like a guest entry
        $first = reset($data);
        return is_array($first) && (isset($first['name']) || isset($first['first_name']) || isset($first['type']));
    }

    /**
     * Convert legacy indexed-array format to canonical keyed format.
     *
     * Groups guests by room, then assigns keys like "room1_adult_1".
     *
     * @param array $guests Indexed array of guest entries
     * @return array Canonical keyed array
     */
    private function convertArrayToKeyed(array $guests): array
    {
        $keyed = [];

        // Track counters per room per type
        $counters = []; // [room_num => ['adult' => N, 'child' => N]]

        foreach ($guests as $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $type = strtolower($guest['type'] ?? 'adult');
            if ($type !== 'child') {
                $type = 'adult';
            }

            $room = (int) ($guest['room'] ?? 1);
            if ($room < 1) {
                $room = 1;
            }

            if (!isset($counters[$room])) {
                $counters[$room] = ['adult' => 0, 'child' => 0];
            }
            $counters[$room][$type]++;
            $index = $counters[$room][$type];

            $key = "room{$room}_{$type}_{$index}";

            // Build canonical entry with all fields
            $entry = array_merge(self::GUEST_DEFAULTS, [
                'type' => $type,
                'room' => $room,
            ]);

            // Map incoming fields
            foreach (self::GUEST_DEFAULTS as $field => $default) {
                if (isset($guest[$field])) {
                    $entry[$field] = $guest[$field];
                }
            }

            // Derive missing name fields
            $entry = self::deriveNameFields($entry);

            $keyed[$key] = $entry;
        }

        return $keyed;
    }

    /**
     * Ensure all guest entries have the full set of canonical fields.
     *
     * @param array $data Keyed guest data
     * @return array Keyed guest data with all fields populated
     */
    private function ensureFields(array $data): array
    {
        foreach ($data as $key => &$guest) {
            if (!is_array($guest)) {
                unset($data[$key]);
                continue;
            }

            $guest = array_merge(self::GUEST_DEFAULTS, $guest);
            $guest = self::deriveNameFields($guest);
        }

        return $data;
    }

    /**
     * Derive missing name-related fields from available data.
     *
     * If api_name is missing, build it from first_name + last_name.
     * If first_name/last_name are missing, try to parse from name/api_name.
     *
     * @param array $guest Single guest entry
     * @return array Guest entry with derived name fields
     */
    private static function deriveNameFields(array $guest): array
    {
        $firstName = trim($guest['first_name'] ?? '');
        $lastName  = trim($guest['last_name'] ?? '');
        $name      = trim($guest['name'] ?? '');
        $apiName   = trim($guest['api_name'] ?? '');

        // Build api_name from first/last if missing
        if (empty($apiName) && ($firstName || $lastName)) {
            $apiName = trim($firstName . ' ' . $lastName);
        }

        // Build display name from last, first if missing
        if (empty($name) && ($firstName || $lastName)) {
            $name = $lastName && $firstName
                ? $lastName . ', ' . $firstName
                : ($lastName ?: $firstName);
        }

        // If we only have api_name, use it as name too
        if (empty($name) && !empty($apiName)) {
            $name = $apiName;
        }

        // If we only have name, use it as api_name too
        if (empty($apiName) && !empty($name)) {
            $apiName = $name;
        }

        $guest['name']     = $name;
        $guest['api_name'] = $apiName;

        return $guest;
    }
}
