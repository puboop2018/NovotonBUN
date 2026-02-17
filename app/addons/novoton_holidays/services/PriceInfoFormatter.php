<?php
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
    public static function toScalar($value): string
    {
        if (is_array($value) || is_object($value)) return '';
        return trim((string)$value);
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
        return $rowRoom === $roomId ||
               rawurldecode($rowRoom) === $roomId ||
               $rowRoom === rawurlencode($roomId);
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
     * Match age type (with fuzzy matching)
     *
     * Handles comma/dot variation in age bands (2-11,99 vs 2-11.99)
     */
    public static function matchAgeType(string $rowAge, string $ageType): bool
    {
        $rowAge = trim(preg_replace('/\s+/', ' ', $rowAge));
        $ageType = trim(preg_replace('/\s+/', ' ', $ageType));

        if (strcasecmp($rowAge, $ageType) === 0) {
            return true;
        }

        $rowAgeNorm = str_replace(',', '.', $rowAge);
        $ageTypeNorm = str_replace(',', '.', $ageType);
        if (strcasecmp($rowAgeNorm, $ageTypeNorm) === 0) {
            return true;
        }

        return false;
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
        $fromStr = ($from == floor($from)) ? (string)intval($from) : str_replace('.', ',', rtrim(rtrim(number_format($from, 2, ',', ''), '0'), ','));
        $toStr = ($to == floor($to)) ? (string)intval($to) : str_replace('.', ',', rtrim(rtrim(number_format($to, 2, ',', ''), '0'), ','));

        return $fromStr . '-' . $toStr;
    }

    /**
     * Count persons matching the IdAge specification
     */
    public static function countMatchingPersons(array $occupancy, string $idAge): int
    {
        $count = 0;
        $idAge = strtoupper(trim($idAge));
        $numAdults = count($occupancy['adults']);
        $numChildren = count($occupancy['children']);

        // Check for specific adult positions (3RD ADULT, 4TH ADULT, etc.)
        if (preg_match('/(\d+)(ST|ND|RD|TH)\s*ADULT/i', $idAge, $matches)) {
            $position = intval($matches[1]);
            if ($numAdults >= $position) {
                $count = 1;
            }
            return $count;
        }

        // Regular adults (not positional)
        if ($idAge === 'ADULT' || $idAge === 'ADT' || $idAge === 'ADULTS') {
            return $numAdults;
        }

        // Check for specific child positions (1ST CHD, 2ND CHD, etc.)
        if (preg_match('/(\d+)(ST|ND|RD|TH)\s*(CHD|CHILD)/i', $idAge, $matches)) {
            $position = intval($matches[1]);
            if ($numChildren >= $position) {
                $count = 1;
            }
            return $count;
        }

        // Children with age bands
        if (strpos($idAge, 'CHD') !== false || strpos($idAge, 'CHILD') !== false) {
            $fromAge = 0;
            $toAge = 17;

            if (preg_match('/(\d+)\s*-\s*(\d+)/', $idAge, $ageMatches)) {
                $fromAge = intval($ageMatches[1]);
                $toAge = intval($ageMatches[2]);
            } elseif (strpos($idAge, '0-1') !== false || strpos($idAge, 'INFANT') !== false) {
                $fromAge = 0;
                $toAge = 1;
            } elseif (strpos($idAge, '2-11') !== false) {
                $fromAge = 2;
                $toAge = 11;
            }

            foreach ($occupancy['children'] as $child) {
                $childAge = $child['age'] ?? 0;
                if ($childAge >= $fromAge && $childAge <= $toAge) {
                    $count++;
                }
            }
            return $count;
        }

        // Infant specific
        if (strpos($idAge, 'INFANT') !== false || strpos($idAge, 'INF') !== false) {
            foreach ($occupancy['children'] as $child) {
                if (($child['age'] ?? 0) < 2) {
                    $count++;
                }
            }
            return $count;
        }

        return $count;
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
                $from = $season['FromDate'] ?? '';
                $to = $season['ToDate'] ?? '';
                $id = intval($season['Season'] ?? $season['IdSeason'] ?? 1);

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
