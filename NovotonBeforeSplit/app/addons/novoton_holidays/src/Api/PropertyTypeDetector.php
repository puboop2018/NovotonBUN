<?php
declare(strict_types=1);
/**
 * Property Type Detector
 *
 * Detects property type from hotel name, package names, and room names
 * using a 3-pass cascade algorithm:
 *   Pass 1: Hotel name (most reliable)
 *   Pass 2: Package names (fallback)
 *   Pass 3: Room names (restricted subset)
 *   Default: 'hotel'
 *
 * @package NovotonHolidays
 * @since 3.4.0
 */

namespace Tygh\Addons\NovotonHolidays\Api;

class PropertyTypeDetector
{
    /**
     * Keyword → property_type mapping, ordered by specificity (most specific first).
     * 'hotel' is last because it's the most generic and also the default.
     */
    private const KEYWORD_MAP = [
        ['pattern' => '/\bVILLAS?\b/i',              'type' => 'villa'],
        ['pattern' => '/\bAPART(?:MENTS?|HOTEL)?\b/i', 'type' => 'apartment'],
        ['pattern' => '/\bCHALETS?\b/i',             'type' => 'chalet'],
        ['pattern' => '/\bGUEST\s*HOUSES?\b/i',      'type' => 'guest-house'],
        ['pattern' => '/\bGUESTHOUSES?\b/i',         'type' => 'guest-house'],
        ['pattern' => '/\bPENSI(?:ON|UNE|UNEA)\b/i', 'type' => 'guest-house'],
        ['pattern' => '/\bRESORTS?\b/i',             'type' => 'resort'],
        ['pattern' => '/\bCOMPLEX(?:UL)?\b/i',       'type' => 'resort'],
        ['pattern' => '/\bHOSTELS?\b/i',             'type' => 'hostel'],
        ['pattern' => '/\bMOTELS?\b/i',              'type' => 'motel'],
        ['pattern' => '/\bBOARDING\s*HOUSES?\b/i',   'type' => 'boarding-house'],
        ['pattern' => '/\bCABINS?\b/i',              'type' => 'cabin'],
        ['pattern' => '/\bCABAN[AĂ]?\b/iu',          'type' => 'cabin'],
        ['pattern' => '/\bBUNGALOWS?\b/i',           'type' => 'cabin'],
        ['pattern' => '/\bHOTEL\b/i',                'type' => 'hotel'],
    ];

    /**
     * Room-level keywords — restricted subset.
     * Only room types that strongly imply the overall property type.
     * Suite, Deluxe, Superior etc. do NOT determine property type.
     */
    private const ROOM_KEYWORD_MAP = [
        ['pattern' => '/\bAPART/i',    'type' => 'apartment'],
        ['pattern' => '/\bVILLA/i',    'type' => 'villa'],
        ['pattern' => '/\bBUNGALOW/i', 'type' => 'cabin'],
        ['pattern' => '/\bSTUDIO/i',   'type' => 'apartment'],
        ['pattern' => '/\bCHALET/i',   'type' => 'chalet'],
    ];

    /**
     * Detect property type using the 3-pass cascade.
     *
     * @param string   $hotelName    Hotel name from API
     * @param string[] $packageNames Package names (can be empty)
     * @param string[] $roomNames    Room names/IDs (can be empty)
     * @return string  Canonical property type code (never null)
     */
    public function detect(string $hotelName, array $packageNames = [], array $roomNames = []): string
    {
        // Pass 1: Hotel name (primary — most reliable signal)
        $result = $this->matchAgainst($hotelName, self::KEYWORD_MAP);
        if ($result !== null) {
            return $result;
        }

        // Pass 2: Package names (secondary)
        foreach ($packageNames as $packageName) {
            $result = $this->matchAgainst((string) $packageName, self::KEYWORD_MAP);
            if ($result !== null) {
                return $result;
            }
        }

        // Pass 3: Room names (tertiary — restricted keyword set)
        foreach ($roomNames as $roomName) {
            $result = $this->matchAgainst((string) $roomName, self::ROOM_KEYWORD_MAP);
            if ($result !== null) {
                return $result;
            }
        }

        // Default: hotel
        return 'hotel';
    }

    /**
     * Detect property type from a single name string (Pass 1 only).
     * Used by NovotonNormalizer::normalizePropertyType().
     *
     * @param string $name Text to scan for property type keywords
     * @return string|null Property type code or null if no match
     */
    public function detectFromName(string $name): ?string
    {
        return $this->matchAgainst($name, self::KEYWORD_MAP);
    }

    /**
     * Match a text against a keyword map.
     *
     * @param string $text       Text to scan
     * @param array  $keywordMap Array of ['pattern' => regex, 'type' => code]
     * @return string|null       Matched type or null
     */
    private function matchAgainst(string $text, array $keywordMap): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach ($keywordMap as $entry) {
            if (preg_match($entry['pattern'], $text)) {
                return $entry['type'];
            }
        }

        return null;
    }
}
