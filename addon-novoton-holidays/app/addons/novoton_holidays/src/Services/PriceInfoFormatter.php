<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Formatter
 *
 * Stateless utility methods for matching, normalization, date helpers,
 * person counting, and debug output formatting.
 *
 * Extracted from PriceInfoCalculation to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PriceInfoFormatter
{
    /**
     * Safely extract a scalar string value from a field that may be array/object
     * (SimpleXML json_encode of empty elements produces [])
     */
    /**
     * @param mixed $value
     */
    public static function toScalar($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    /**
     * Normalize IdAge for fee matching: strip trailing "BY X AD" suffix
     */
    public static function feeKey(string $idAge): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $idAge));
        $s = preg_replace('/\s+BY\s+\d+\s+AD\s*$/i', '', $s);
        return trim($s);
    }

    /**
     * Match room ID (handle URL encoding)
     */
    public static function matchRoom(string $rowRoom, string $roomId): bool
    {
        if (empty($roomId)) return true;
        if (strcasecmp($rowRoom, $roomId) === 0) return true;
        if (strcasecmp(rawurldecode($rowRoom), $roomId) === 0) return true;
        if (strcasecmp($rowRoom, rawurlencode($roomId)) === 0) return true;
        // Normalize spaces/plus signs for occupancy patterns like "DBL 2+1"
        $normRow = str_replace(['+', '%2B', '%2b'], '+', rawurldecode($rowRoom));
        $normId  = str_replace(['+', '%2B', '%2b'], '+', rawurldecode($roomId));
        return strcasecmp(trim($normRow), trim($normId)) === 0;
    }

    /**
     * Match board ID
     */
    public static function matchBoard(string $rowBoard, string $boardId): bool
    {
        if (empty($boardId)) return true;
        return strcasecmp($rowBoard, $boardId) === 0;
    }

    /**
     * Match age type — exact matching only.
     *
     * Compares case-insensitively with whitespace normalization.
     * The only tolerance is comma/dot equivalence in age bands
     * (2-11,99 == 2-11.99) since this is a serialization artifact.
     *
     * No fuzzy matching, no ordinal stripping. "1 ST CHD 2-11,99" and
     * "2 ND CHD 2-11,99" are distinct types with different pricing
     * percentages — they must never match each other.
     */
    public static function matchAgeType(string $rowAge, string $ageType): bool
    {
        $rowAge = trim(preg_replace('/\s+/', ' ', $rowAge));
        $ageType = trim(preg_replace('/\s+/', ' ', $ageType));

        if (strcasecmp($rowAge, $ageType) === 0) {
            return true;
        }

        // Comma/dot normalization — the only tolerance.
        // Age bands use comma in some locales (2-11,99) and dot in others (2-11.99).
        $rowAgeNorm = str_replace(',', '.', $rowAge);
        $ageTypeNorm = str_replace(',', '.', $ageType);

        return strcasecmp($rowAgeNorm, $ageTypeNorm) === 0;
    }

    /**
     * Match accommodation type
     */
    public static function matchAccType(string $rowAcc, string $accType): bool
    {
        $rowAcc = strtoupper(trim($rowAcc));
        $accType = strtoupper(trim($accType));

        if ($rowAcc === $accType) {
            return true;
        }

        return self::normalizeAccType($rowAcc) === self::normalizeAccType($accType);
    }

    /**
     * Normalize accommodation type to canonical form
     */
    public static function normalizeAccType(string $acc): string
    {
        $acc = strtoupper(trim($acc));

        if (in_array($acc, ['RB', 'REGULAR', 'REGULAR BED', 'REGULAR_BED'], true)) {
            return 'REGULAR';
        }

        if (in_array($acc, ['EB', 'EXTRA BED', 'EXTRA_BED', 'EXTRABED'], true)) {
            return 'EXTRA BED';
        }

        return $acc;
    }

    /**
     * Get ordinal for adult (3 RD, 4 TH, etc.)
     */
    public static function getOrdinal(int $num): string
    {
        $ordinals = [
            1 => '1 ST',
            2 => '2 ND',
            3 => '3 RD',
            4 => '4 TH',
            5 => '5 TH'
        ];
        return $ordinals[$num] ?? $num . ' TH';
    }

    /**
     * Get ordinal for child (delegates to getOrdinal)
     */
    public static function getChildOrdinal(int $num): string
    {
        return self::getOrdinal($num);
    }

    /**
     * Format age band label to match season_price conventions.
     *
     * Produces labels like "0-1,99", "2-11,99", "12-17,99"
     */
    public static function formatAgeBandLabel(float $from, float $to): string
    {
        $fromStr = ($from === floor($from)) ? (string)(int) $from : str_replace('.', ',', rtrim(rtrim(number_format($from, 2, ',', ''), '0'), ','));
        $toStr = ($to === floor($to)) ? (string)(int) $to : str_replace('.', ',', rtrim(rtrim(number_format($to, 2, ',', ''), '0'), ','));

        return $fromStr . '-' . $toStr;
    }

    /**
     * Count persons matching the IdAge specification.
     *
     * IdAge strings from the B2B API encode two independent dimensions:
     *   1. An optional ordinal position  ("1 ST", "2ND", "3 RD" …)
     *   2. A person-type with optional age band ("ADULT", "CHD 2-11,99", "INFANT")
     *
     * Algorithm:
     *   - Parse the positional prefix (if any) and the type separately.
     *   - For positional adult patterns (e.g., "3 RD ADULT"):
     *     Check if any occupant actually has that positional age_type.
     *     This prevents double-counting when a room classifies all adults
     *     as plain "ADULT" (e.g., FAM 4+1 rooms with no separate 3RD/4TH
     *     ADULT pricing).
     *   - For non-positional patterns: count all persons matching the type.
     *   - For positional child patterns: return 1 when the Nth matching
     *     child exists, else 0.
     */
    public static function countMatchingPersons(array $occupancy, string $idAge): int
    {
        $idAge = strtoupper(trim($idAge));
        if ($idAge === '') {
            return 0;
        }

        // --- Step 1: Extract optional positional prefix ---
        // Handles "1ST", "1 ST", "2ND", "2 ND", "3RD", "3 RD", "4TH" etc.
        $position = null;
        $type = $idAge;

        if (preg_match('/^(\d+)\s*(ST|ND|RD|TH)\s+(.+)$/i', $idAge, $m)) {
            $position = (int) $m[1];
            $type = trim($m[3]);
        } elseif (preg_match('/^(\d+)(ST|ND|RD|TH)(.+)$/i', $idAge, $m)) {
            // No space variant: "1STCHD 2-11,99" or "2NDADULT"
            $position = (int) $m[1];
            $type = trim($m[3]);
        }

        // --- Step 2: Handle positional adult patterns ---
        // For positional adult patterns like "3 RD ADULT", check if any
        // occupant in the adults array actually has that positional age_type.
        // This avoids double-counting when a room has no separate positional
        // adult pricing (all adults are plain "ADULT").
        if ($position !== null && ($type === 'ADULT' || $type === 'ADT' || $type === 'ADULTS')) {
            $normalizedIdAge = trim(preg_replace('/\s+/', ' ', $idAge));
            foreach ($occupancy['adults'] as $adult) {
                $adultAgeNorm = strtoupper(trim(preg_replace('/\s+/', ' ', $adult['age_type'] ?? '')));
                if ($adultAgeNorm === $normalizedIdAge) {
                    return 1;
                }
            }
            return 0;
        }

        // --- Step 3: Count persons matching the type ---
        $typeCount = self::countByType($occupancy, $type);

        // --- Step 4: Apply positional constraint (children) ---
        if ($position !== null) {
            return ($typeCount >= $position) ? 1 : 0;
        }

        return $typeCount;
    }

    /**
     * Count persons matching a (non-positional) type string.
     */
    private static function countByType(array $occupancy, string $type): int
    {
        // Adult types
        if ($type === 'ADULT' || $type === 'ADT' || $type === 'ADULTS') {
            return count($occupancy['adults']);
        }

        // Infant types
        if (str_contains($type, 'INFANT') || $type === 'INF') {
            $count = 0;
            foreach ($occupancy['children'] as $child) {
                if (($child['age'] ?? 0) < 2) {
                    $count++;
                }
            }
            return $count;
        }

        // Child types (with optional age band)
        if (str_contains($type, 'CHD') || str_contains($type, 'CHILD')) {
            return self::countChildrenInBand($occupancy, $type);
        }

        return 0;
    }

    /**
     * Count children whose age falls within the band encoded in the type string.
     *
     * Parses age ranges like "CHD 2-11,99", "CHD 0-1,99", bare "CHD" (0-17).
     */
    private static function countChildrenInBand(array $occupancy, string $type): int
    {
        $fromAge = 0;
        $toAge = 17;

        // "2-11,99", "0-1.99", "12-17" — extract numeric range with optional decimal
        if (preg_match('/(\d+)\s*-\s*(\d+)[,.](\d+)/', $type, $m)) {
            $fromAge = (int) $m[1];
            $toAge = (int) $m[2] + (int) $m[3] / pow(10, strlen($m[3]));
        } elseif (preg_match('/(\d+)\s*-\s*(\d+)/', $type, $m)) {
            $fromAge = (int) $m[1];
            $toAge = (int) $m[2];
        }

        $count = 0;
        foreach ($occupancy['children'] as $child) {
            $childAge = $child['age'] ?? 0;
            if ($childAge >= $fromAge && $childAge <= $toAge) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if a fee's IdAge correlates with any of the season_price age types.
     *
     * Unlike matchAgeType(), this method does NOT strip ordinal prefixes.
     * A fee IdAge of "3 RD ADULT" only correlates if season_price explicitly
     * contains "3 RD ADULT" — it will NOT match plain "ADULT".
     *
     * This prevents double-counting in rooms where all adults are classified
     * as plain "ADULT" (e.g., FAM 4+1 rooms with no separate 3RD/4TH ADULT
     * pricing rows in season_price).
     *
     * Comma/dot and whitespace normalization IS applied for consistency.
     */
    public static function correlatesWithSeasonAgeTypes(string $feeIdAge, array $seasonAgeTypes): bool
    {
        $feeNorm = strtoupper(trim(preg_replace('/\s+/', ' ', str_replace(',', '.', $feeIdAge))));
        if ($feeNorm === '') {
            return false;
        }

        foreach ($seasonAgeTypes as $spAge) {
            $spNorm = strtoupper(trim(preg_replace('/\s+/', ' ', str_replace(',', '.', $spAge))));
            if ($feeNorm === $spNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two date ranges overlap
     */
    public static function datesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        return $start1 <= $end2 && $start2 <= $end1;
    }

    /**
     * Count nights overlapping with a date range
     */
    public static function countOverlappingNights(string $checkIn, int $nights, string $fromDate, string $toDate): int
    {
        $count = 0;
        $checkInDate = new \DateTime($checkIn);

        for ($i = 0; $i < $nights; $i++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$i} days");
            $dateStr = $currentDate->format('Y-m-d');

            if ($dateStr >= $fromDate && $dateStr <= $toDate) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create error result
     */
    public static function errorResult(string $message, array $debugLog = [], bool $debug = false): array
    {
        return [
            'success' => false,
            'error' => $message,
            'price' => 0,
            'debug_log' => $debug ? $debugLog : null
        ];
    }

    /**
     * Verify season-to-price correlation (debug helper)
     *
     * @param array $priceinfo Priceinfo data
     * @param string $checkIn Check-in date
     * @param int $nights Number of nights
     * @return array Debug info showing date -> season -> priceKey mapping
     */
    public static function verifySeasonPriceMapping(array $priceinfo, string $checkIn, int $nights): array
    {
        $seasons = $priceinfo['seasons'] ?? [];

        $parsedSeasons = [];
        if (isset($seasons['Season'])) {
            $parsedSeasons = [$seasons];
        } elseif (isset($seasons[0]['Season'])) {
            $parsedSeasons = $seasons;
        } elseif (isset($seasons['season'])) {
            $parsedSeasons = $seasons['season'];
            if (isset($parsedSeasons['Season'])) {
                $parsedSeasons = [$parsedSeasons];
            }
        }

        $mapping = [];
        $checkInDate = new \DateTime($checkIn);

        for ($night = 0; $night < $nights; $night++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$night} days");
            $dateStr = $currentDate->format('Y-m-d');

            $seasonNum = 1;
            $matchedSeason = null;

            foreach ($parsedSeasons as $season) {
                $from = $season['FromDate'] ?? $season['DateFrom'] ?? '';
                $to = $season['ToDate'] ?? $season['DateTo'] ?? '';
                $id = (int) ($season['Season'] ?? $season['IdSeason'] ?? 1);

                if ($dateStr >= $from && $dateStr <= $to) {
                    $seasonNum = $id;
                    $matchedSeason = $season;
                    break;
                }
            }

            $priceKey = 'Price' . $seasonNum;

            $mapping[] = [
                'night' => $night + 1,
                'date' => $dateStr,
                'season_num' => $seasonNum,
                'price_key' => $priceKey,
                'matched_range' => $matchedSeason ? ($matchedSeason['FromDate'] . ' to ' . $matchedSeason['ToDate']) : 'DEFAULT'
            ];
        }

        return [
            'total_seasons_found' => count($parsedSeasons),
            'seasons_raw' => $parsedSeasons,
            'night_mapping' => $mapping
        ];
    }

    /**
     * Get sample prices for verification (debug helper)
     *
     * @param array $priceinfo Priceinfo data
     * @param string $roomId Room ID
     * @param string $boardId Board ID
     * @return array Price values by column
     */
    public static function getSamplePrices(array $priceinfo, string $roomId, string $boardId): array
    {
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $samples = [];
        foreach ($seasonPrices as $row) {
            $rowRoom = $row['IdRoom'] ?? '';
            $rowBoard = $row['IdBoard'] ?? '';

            if (self::matchRoom($rowRoom, $roomId) && self::matchBoard($rowBoard, $boardId)) {
                $sample = [
                    'IdAge' => $row['IdAge'] ?? '',
                    'IdAcc' => $row['IdAcc'] ?? '',
                    'Code' => $row['Code'] ?? '',
                    'Base' => $row['Base'] ?? '',
                    'RoomPrice' => $row['RoomPrice'] ?? 'No',
                ];

                for ($i = 1; $i <= 20; $i++) {
                    $key = 'Price' . $i;
                    if (isset($row[$key]) && $row[$key] !== '') {
                        $sample[$key] = $row[$key];
                    }
                }

                $samples[] = $sample;
            }
        }

        return $samples;
    }
}
