<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Calculator
 *
 * Applies pricing formulas: base price, fees, discounts, reductions,
 * priority rules, and commission.
 *
 * Extracted from PriceInfoCalculation to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PriceInfoCalculator
{
    private float $commission;

    private PriceInfoParser $parser;

    private ?\Closure $logger;

    public function __construct(PriceInfoParser $parser, float $commission, ?callable $logger = null)
    {
        $this->parser = $parser;
        $this->commission = max(0.0, $commission);
        $this->logger = $logger !== null ? $logger(...) : null;
    }

    /**
     * Calculate base price from season_price rows
     * @param array<string, mixed> $occupancy
     * @param array<string, mixed> $seasonsByNight
     * @return array<string, mixed>
     */
    public function calculateBasePrice(array $occupancy, array $seasonsByNight, string $roomId, string $boardId, int $nights): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $total = 0;
        $byNight = [];
        $byPerson = [];
        $byPersonByNight = [];  // person_key => [nightIdx => price]
        $matchedRows = [];      // person_key => matched row info (first night only)

        // Initialize per-person entries so every occupant appears in the output
        foreach ($occupancy['adults'] as $adult) {
            $key = 'adult_' . $adult['index'];
            $byPerson[$key] = 0;
            $byPersonByNight[$key] = [];
        }
        foreach ($occupancy['children'] as $child) {
            $key = 'child_' . $child['index'];
            $byPerson[$key] = 0;
            $byPersonByNight[$key] = [];
        }

        foreach ($seasonsByNight as $nightIdx => $nightInfo) {
            $nightTotal = 0;
            $seasonNum = $nightInfo['season'];
            $priceKey = 'Price' . $seasonNum;

            $roomPriceCharged = false;

            // Adults
            foreach ($occupancy['adults'] as $adult) {
                $personKey = 'adult_' . $adult['index'];
                $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $adult['age_type'], $adult['acc_type'], $nights);

                if ($row) {
                    $isRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';
                    $isExtraBed = str_contains(strtolower($adult['acc_type']), strtolower('EXTRA'));

                    // RoomPrice dedup: only skip regular-bed occupants covered by
                    // the per-room charge.  Extra-bed guests always have their own
                    // supplement and must never be blocked.
                    if ($isRoomPrice && !$isExtraBed) {
                        if ($roomPriceCharged) {
                            $byPersonByNight[$personKey][$nightIdx] = 0;
                            continue;
                        }
                        $roomPriceCharged = true;
                    }

                    $price = $this->getPriceFromRow($row, $priceKey);
                    $nightTotal += $price;
                    $byPersonByNight[$personKey][$nightIdx] = $price;

                    if (!isset($byPerson[$personKey])) {
                        $byPerson[$personKey] = 0;
                    }
                    $byPerson[$personKey] += $price;

                    // Capture matched row info on first night
                    if (!isset($matchedRows[$personKey])) {
                        $rowCode = PriceInfoFormatter::toScalar($row['Code'] ?? '');
                        $rowBase = PriceInfoFormatter::toScalar($row['Base'] ?? '');
                        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? '';
                        $isPercentage = false;
                        if (is_string($rawPrice) && str_contains($rawPrice, '%')) {
                            $isPercentage = true;
                        } elseif ($rowCode !== '' && $rowBase !== '' && $rowCode !== $rowBase) {
                            $isPercentage = true;
                        }
                        $matchedRows[$personKey] = [
                            'age_type' => $adult['age_type'],
                            'acc_type' => $adult['acc_type'],
                            'row_age' => PriceInfoFormatter::toScalar($row['fAge'] ?? $row['IdAge'] ?? ''),
                            'code' => $rowCode,
                            'base' => $rowBase,
                            'raw_price' => (string)$rawPrice,
                            'is_percentage' => $isPercentage,
                            'room_price' => $row['RoomPrice'] ?? 'No',
                        ];
                    }
                } else {
                    $byPersonByNight[$personKey][$nightIdx] = 0;
                }
            }

            // Children
            foreach ($occupancy['children'] as $child) {
                $personKey = 'child_' . $child['index'];
                $row = null;
                if ($child['by_1_ad']) {
                    $ageTypeBy1Ad = $child['age_type'] . ' BY 1 AD';
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1Ad, $child['acc_type'], $nights);

                    if (!$row) {
                        $ageTypeBy1AdLower = $child['age_type'] . ' by 1 ad';
                        $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1AdLower, $child['acc_type'], $nights);
                    }
                }

                if (!$row) {
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $child['age_type'], $child['acc_type'], $nights);
                }

                if ($row) {
                    $childRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';
                    $isExtraBed = str_contains(strtolower($child['acc_type']), strtolower('EXTRA'));

                    // Same RoomPrice dedup logic: only skip regular-bed children
                    // (rare — a child occupying a regular bed in the room).
                    // Extra-bed children always have their own supplement.
                    if ($childRoomPrice && !$isExtraBed) {
                        if ($roomPriceCharged) {
                            $byPersonByNight[$personKey][$nightIdx] = 0;
                            continue;
                        }
                        $roomPriceCharged = true;
                    }

                    $price = $this->getPriceFromRow($row, $priceKey);
                    $nightTotal += $price;
                    $byPersonByNight[$personKey][$nightIdx] = $price;

                    if (!isset($byPerson[$personKey])) {
                        $byPerson[$personKey] = 0;
                    }
                    $byPerson[$personKey] += $price;

                    // Capture matched row info on first night
                    if (!isset($matchedRows[$personKey])) {
                        $rowCode = PriceInfoFormatter::toScalar($row['Code'] ?? '');
                        $rowBase = PriceInfoFormatter::toScalar($row['Base'] ?? '');
                        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? '';
                        $isPercentage = false;
                        if (is_string($rawPrice) && str_contains($rawPrice, '%')) {
                            $isPercentage = true;
                        } elseif ($rowCode !== '' && $rowBase !== '' && $rowCode !== $rowBase) {
                            $isPercentage = true;
                        }
                        $matchedRows[$personKey] = [
                            'age_type' => $child['age_type'],
                            'acc_type' => $child['acc_type'],
                            'row_age' => PriceInfoFormatter::toScalar($row['fAge'] ?? $row['IdAge'] ?? ''),
                            'code' => $rowCode,
                            'base' => $rowBase,
                            'raw_price' => (string)$rawPrice,
                            'is_percentage' => $isPercentage,
                            'room_price' => $row['RoomPrice'] ?? 'No',
                        ];
                    }
                } else {
                    $byPersonByNight[$personKey][$nightIdx] = 0;
                }
            }

            $byNight[$nightIdx] = [
                'date' => $nightInfo['date'],
                'season' => $seasonNum,
                'price' => $nightTotal
            ];

            $total += $nightTotal;
        }

        return [
            'total' => $total,
            'by_night' => $byNight,
            'by_person' => $byPerson,
            'by_person_by_night' => $byPersonByNight,
            'matched_rows' => $matchedRows,
        ];
    }

    /**
     * Find the base code row for percentage calculations
     * @param array<string, mixed> $seasonPrices
     * @return array<string, mixed>|null
     */
    public function findBaseCodeRow(array $seasonPrices, string $roomId, string $boardId): ?array
    {
        foreach ($seasonPrices as $row) {
            $rowRoom = is_string($row['IdRoom'] ?? '') ? ($row['IdRoom'] ?? '') : '';
            $rowBoard = is_string($row['IdBoard'] ?? '') ? ($row['IdBoard'] ?? '') : '';
            $code = is_string($row['Code'] ?? '') ? ($row['Code'] ?? '') : '';

            if (PriceInfoFormatter::matchRoom($rowRoom, $roomId) && PriceInfoFormatter::matchBoard($rowBoard, $boardId) && $code === 'Base') {
                return $row;
            }
        }
        return null;
    }

    /**
     * Find season_price row matching criteria.
     *
     * Uses exact matching for all fields (room, board, age type, acc type).
     * When multiple rows match, picks the most specific (largest FromDays).
     * @param array<string, mixed> $seasonPrices
     * @return array<string, mixed>|null
     */
    public function findSeasonPriceRow(array $seasonPrices, string $roomId, string $boardId, string $ageType, string $accType, int $nights): ?array
    {
        static $ageTypeMap = [
            '1' => 'ADULT',
            '2' => 'CHD 0-1.99',
            '3' => 'CHD 2-11.99',
            '4' => 'CHD 12-17.99',
        ];

        $candidates = [];

        foreach ($seasonPrices as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');
            $rowAcc = PriceInfoFormatter::toScalar($row['IdAcc'] ?? '');
            $rowAge = '';
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $rowAge = $row['fAge'];
            } else {
                $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
                $rowAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }

            if ($rowAcc === '') {
                $rowAcc = 'REGULAR';
            }

            $rawFromDays = PriceInfoFormatter::toScalar($row['FromDays'] ?? '');
            $rawToDays = PriceInfoFormatter::toScalar($row['ToDays'] ?? '');
            $fromDays = ($rawFromDays !== '') ? (int) (preg_replace('/\D+/', '', $rawFromDays) ?: '1') : 1;
            $toDays = ($rawToDays !== '') ? (int) (preg_replace('/\D+/', '', $rawToDays) ?: '9999') : 9999;
            if ($fromDays <= 0) $fromDays = 1;
            if ($toDays <= 0) $toDays = 9999;

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) continue;
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) continue;
            if (!PriceInfoFormatter::matchAgeType($rowAge, $ageType)) continue;
            if (!PriceInfoFormatter::matchAccType($rowAcc, $accType)) continue;

            if ($nights < $fromDays || $nights > $toDays) continue;

            $candidates[] = ['row' => $row, 'fromDays' => $fromDays];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            return $b['fromDays'] <=> $a['fromDays'];
        });

        return $candidates[0]['row'];
    }

    /**
     * Get price from row, handling percentages via recursive Code/Base resolution
     * @param array<string, mixed> $row
     */
    public function getPriceFromRow(array $row, string $priceKey): float
    {
        $visited = [];
        return $this->resolvePrice($row, $priceKey, $visited);
    }

    /**
     * Recursively resolve price from a season_price row
     */
    /**
     * Recursively resolve price from a season_price row.
     *
     * Pricing rule (Code / Base relationship):
     *   - Code == Base  → price values (Price1..Price20) are absolute amounts
     *   - Code != Base  → price values are PERCENTAGES of the base row's price,
     *                      where the base row is the one whose Code == this row's Base
     *
     * Percentage detection:
     *   1. Explicit '%' suffix in the price string (e.g. "20%")
     *   2. Implicit: Code != Base means the numeric value IS a percentage
     *
     * When multiple rows share the same Code, the lookup prefers the row
     * matching the current row's IdRoom and IdBoard.
     */
    private const MAX_RESOLVE_DEPTH = 10;

    /**
     * @param array<string, mixed> $row
     */
    private function resolvePrice(array $row, string $priceKey, array &$visited, int $depth = 0): float
    {
        if ($depth > self::MAX_RESOLVE_DEPTH) {
            $this->log("Price resolution depth exceeded ({$depth}): possible circular Code/Base chain");
            return 0.0;
        }

        $code = PriceInfoFormatter::toScalar($row['Code'] ?? '');
        $memoKey = $code . ':' . $priceKey;

        if (isset($visited[$memoKey])) return 0;
        $visited[$memoKey] = true;

        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? 0;

        if (is_array($rawPrice) || is_object($rawPrice)) {
            return 0;
        }

        // Determine if price is percentage-based
        $isPercentage = false;
        $percentValue = 0.0;

        if (is_string($rawPrice) && str_contains($rawPrice, '%')) {
            // Explicit percentage marker (e.g. "20%")
            $isPercentage = true;
            $percentValue = (float) str_replace('%', '', $rawPrice);
        } else {
            // Implicit percentage: Code != Base means numeric value is a percentage
            $base = PriceInfoFormatter::toScalar($row['Base'] ?? '');
            if ($code !== '' && $base !== '' && $code !== $base) {
                $isPercentage = true;
                $percentValue = (float) $rawPrice;
            }
        }

        if ($isPercentage) {
            $baseRef = PriceInfoFormatter::toScalar($row['Base'] ?? '');
            $codeIndex = $this->parser->getCodeIndex();

            if ($baseRef !== '' && isset($codeIndex[$baseRef])) {
                $baseRow = $this->findBestBaseRow(
                    $codeIndex[$baseRef],
                    PriceInfoFormatter::toScalar($row['IdRoom'] ?? ''),
                    PriceInfoFormatter::toScalar($row['IdBoard'] ?? '')
                );
                $basePrice = $this->resolvePrice($baseRow, $priceKey, $visited, $depth + 1);
                return round($basePrice * ($percentValue / 100), 4);
            }
            return 0;
        }

        return (float) $rawPrice;
    }

    /**
     * From a list of candidate base rows (all sharing the same Code),
     * pick the one that best matches the given room and board.
     *
     * Falls back to the first row if no room/board match is found.
     * @param array<string, mixed> $candidates
     * @return array<string, mixed>
     */
    private function findBestBaseRow(array $candidates, string $roomId, string $boardId): array
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        foreach ($candidates as $candidate) {
            $cRoom = PriceInfoFormatter::toScalar($candidate['IdRoom'] ?? '');
            $cBoard = PriceInfoFormatter::toScalar($candidate['IdBoard'] ?? '');
            if (PriceInfoFormatter::matchRoom($cRoom, $roomId) && PriceInfoFormatter::matchBoard($cBoard, $boardId)) {
                return $candidate;
            }
        }

        // Fallback: match room only
        foreach ($candidates as $candidate) {
            $cRoom = PriceInfoFormatter::toScalar($candidate['IdRoom'] ?? '');
            if (PriceInfoFormatter::matchRoom($cRoom, $roomId)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    // ── Fee and discount calculation delegated to FeeCalculator and DiscountCalculator ──
    // See PriceInfoCalculation orchestrator for wiring.

    /**
     * Apply commission to price
     */
    public function applyCommission(float $price): float
    {
        if ($this->commission <= 0) {
            return $price;
        }
        return $price * (1 + ($this->commission / 100));
    }

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
