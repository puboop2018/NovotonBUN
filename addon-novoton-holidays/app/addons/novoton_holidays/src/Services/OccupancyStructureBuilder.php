<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Builds the occupancy structure for a stay — who sits on Regular Beds vs Extra
 * Beds, with ordinal age types — given the room capacity and the per-room age
 * pricing. Children whose age band has no matching price row are reclassified as
 * additional adults on an extra bed.
 *
 * Extracted from PriceInfoParser, where this ~165-line routine was the single
 * heaviest method and tangled with the parser's state. The builder takes its
 * inputs (priceinfo, parsed child bands) explicitly and delegates the age-band
 * lookups to AgeBandResolver, so the bed-assignment / reclassification logic is
 * directly unit-testable. Behaviour is preserved verbatim.
 */
class OccupancyStructureBuilder
{
    private ?\Closure $logger;

    public function __construct(
        private readonly AgeBandResolver $ageBandResolver,
        ?callable $logger = null,
    ) {
        $this->logger = $logger !== null ? $logger(...) : null;
    }

    /**
     * Build occupancy structure - who uses Regular Beds vs Extra Beds.
     *
     * If a child's age band has no matching price row in this room, the child
     * is reclassified as an additional adult on EXTRA BED.
     *
     * @param list<int> $childrenAges
     * @param array<string, mixed> $capacity
     * @param array<string, mixed>|null $priceinfo
     * @param list<array<string, mixed>> $childAgeBands
     * @return array<string, mixed>
     */
    public function build(
        int $adults,
        array $childrenAges,
        array $capacity,
        ?array $priceinfo,
        array $childAgeBands,
        string $roomId = '',
        string $boardId = '',
    ): array {
        $occupancy = [
            'adults' => [],
            'children' => [],
            'children_as_adults' => [],
            'total_rb_used' => 0,
            'total_eb_used' => 0,
        ];

        $rbAvailable = PriceInfoFormatter::toInt($capacity['RB'] ?? 2);
        $rbUsed = 0;
        $ebUsed = 0;

        $availableBands = [];
        if (!empty($roomId) && !empty($boardId) && !empty($priceinfo)) {
            $availableBands = $this->ageBandResolver->getAvailableChildAgeBands($priceinfo, $roomId, $boardId);
            $this->log('Available child age bands for room', [
                'room_id' => $roomId,
                'board_id' => $boardId,
                'bands' => $availableBands,
            ]);
        }

        // Check if this room has separate pricing for 3rd+ adults on extra beds.
        // When it does NOT (e.g., FAM 4+1 DELUXE where all adults are "ADULT"),
        // extra-bed adults are classified as plain "ADULT" with "REGULAR" acc_type
        // so they match the same season_price row as regular-bed adults.
        $hasExtraBedAdultPricing = false;
        if (!empty($roomId) && !empty($boardId) && !empty($priceinfo)) {
            $hasExtraBedAdultPricing = $this->ageBandResolver->hasAdultExtraBedPricing($priceinfo, $roomId, $boardId);
            $this->log('Adult extra bed pricing check', [
                'room_id' => $roomId,
                'board_id' => $boardId,
                'has_extra_bed_adult_pricing' => $hasExtraBedAdultPricing,
            ]);
        }

        // Place adults
        $adultCount = $adults;
        for ($i = 0; $i < $adults; $i++) {
            if ($rbUsed < $rbAvailable) {
                $occupancy['adults'][] = [
                    'index' => $i + 1,
                    'bed_type' => 'REGULAR',
                    'age_type' => 'ADULT ',
                    'acc_type' => 'REGULAR',
                ];
                $rbUsed++;
            } else {
                if ($hasExtraBedAdultPricing) {
                    // Room has separate pricing for 3rd+ adults (e.g., "3 RD ADULT" / "EXTRA BED")
                    $ordinal = PriceInfoFormatter::getOrdinal($i + 1);
                    $occupancy['adults'][] = [
                        'index' => $i + 1,
                        'bed_type' => 'EXTRA BED',
                        'age_type' => $ordinal . ' ADULT',
                        'acc_type' => 'EXTRA BED',
                    ];
                } else {
                    // No separate pricing — all adults use plain "ADULT" / "REGULAR"
                    // so they match the generic adult season_price row
                    $occupancy['adults'][] = [
                        'index' => $i + 1,
                        'bed_type' => 'EXTRA BED',
                        'age_type' => 'ADULT ',
                        'acc_type' => 'REGULAR',
                    ];
                }
                $ebUsed++;
            }
        }

        // Sort children by age descending (oldest first) before assigning ordinals.
        // The API uses ordinal-based child pricing (1 ST CHD = highest %, 2 ND CHD = lower %)
        // and expects the oldest child to be the 1st child.
        $sortedChildrenAges = $childrenAges;
        rsort($sortedChildrenAges);

        // Place children
        $childOrdinalCounter = 0;
        foreach ($sortedChildrenAges as $age) {
            $ageBand = $this->ageBandResolver->getAgeBand($age, $childAgeBands);

            $bandHasPricing = true;
            if (!empty($availableBands)) {
                $bandNorm = str_replace(',', '.', $ageBand);
                $hasBand = false;
                foreach ($availableBands as $ab) {
                    if (str_replace(',', '.', PriceInfoFormatter::toScalar($ab)) === $bandNorm) {
                        $hasBand = true;
                        break;
                    }
                }
                $bandHasPricing = $hasBand;
            }

            if (!$bandHasPricing) {
                $adultCount++;
                $ordinal = PriceInfoFormatter::getOrdinal($adultCount);

                $this->log('Child reclassified as adult (no pricing for age band)', [
                    'child_age' => $age,
                    'age_band' => $ageBand,
                    'reclassified_as' => $ordinal . ' ADULT',
                ]);

                if ($rbUsed < $rbAvailable) {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'REGULAR',
                        'age_type' => 'ADULT ',
                        'acc_type' => 'REGULAR',
                        'original_child_age' => $age,
                        'reclassified' => true,
                    ];
                    $rbUsed++;
                } else {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'EXTRA BED',
                        'age_type' => $ordinal . ' ADULT',
                        'acc_type' => 'EXTRA BED',
                        'original_child_age' => $age,
                        'reclassified' => true,
                    ];
                    $ebUsed++;
                }

                $occupancy['adults'][] = $entry;
                $occupancy['children_as_adults'][] = $entry;
                continue;
            }

            $childOrdinalCounter++;
            $ordinal = PriceInfoFormatter::getChildOrdinal($childOrdinalCounter);

            if ($rbUsed < $rbAvailable) {
                $occupancy['children'][] = [
                    'index' => $childOrdinalCounter,
                    'age' => $age,
                    'age_band' => $ageBand,
                    'bed_type' => 'REGULAR',
                    'age_type' => $ordinal . ' CHD ' . $ageBand,
                    'acc_type' => 'REGULAR',
                    'by_1_ad' => ($adults === 1),
                ];
                $rbUsed++;
            } else {
                $occupancy['children'][] = [
                    'index' => $childOrdinalCounter,
                    'age' => $age,
                    'age_band' => $ageBand,
                    'bed_type' => 'EXTRA BED',
                    'age_type' => $ordinal . ' CHD ' . $ageBand,
                    'acc_type' => 'EXTRA BED',
                    'by_1_ad' => ($adults === 1),
                ];
                $ebUsed++;
            }
        }

        $occupancy['total_rb_used'] = $rbUsed;
        $occupancy['total_eb_used'] = $ebUsed;

        return $occupancy;
    }

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $data);
        }
    }
}
