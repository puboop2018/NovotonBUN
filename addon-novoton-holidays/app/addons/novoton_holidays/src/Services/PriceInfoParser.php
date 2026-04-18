<?php

declare(strict_types=1);

/**
 * Novoton PriceInfo Parser
 *
 * Loads and parses raw price data: database loading, index building,
 * occupancy structure, room capacity, season mapping, and age band resolution.
 *
 * Extracted from PriceInfoCalculation to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;

class PriceInfoParser
{
    /** @var array<string, mixed>|null */
    private ?array $priceinfo;

    /** @var array<string, mixed>|null */
    private ?array $hotelinfo;

    /** @var array<string, mixed> */
    private array $codeIndex = [];

    /** @var list<array<string, mixed>> */
    private array $childAgeBands = [];

    private ?\Closure $logger;

    private HotelPackageRepositoryInterface $packageRepo;

    private HotelRepositoryInterface $hotelRepo;

    public function __construct(
        ?callable $logger = null,
        ?HotelPackageRepositoryInterface $packageRepo = null,
        ?HotelRepositoryInterface $hotelRepo = null,
    ) {
        $this->logger = $logger !== null ? $logger(...) : null;
        $this->packageRepo = $packageRepo ?? new HotelPackageRepository();
        $this->hotelRepo = $hotelRepo ?? new HotelRepository();
    }

    // -- Getters for parsed data ------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function getPriceinfo(): ?array
    {
        return $this->priceinfo;
    }
    /**
     * @return array<string, mixed>|null
     */
    public function getHotelinfo(): ?array
    {
        return $this->hotelinfo;
    }
    /**
     * @return array<string, mixed>
     */
    public function getCodeIndex(): array
    {
        return $this->codeIndex;
    }
    /**
     * @return list<array<string, mixed>>
     */
    public function getChildAgeBands(): array
    {
        return $this->childAgeBands;
    }

    /**
     * Set priceinfo directly (used by debug tools that bypass loadPriceInfo)
     * @param array<string, mixed> $priceinfo
     */
    public function setPriceinfo(array $priceinfo): void
    {
        $this->priceinfo = $priceinfo;
    }

    /**
     * Load priceinfo data from database
     * @return array<string, mixed>|null
     */
    public function loadPriceInfo(string $hotelId, string $packageName): ?array
    {
        $row = $this->packageRepo->findByHotelAndPackageName($hotelId, $packageName);
        $json = $row['priceinfo_data'] ?? null;

        if (empty($json)) {
            return null;
        }

        $json = PriceInfoFormatter::toScalar($json);
        $decoded = json_decode($json, true);
        /** @var array<string, mixed>|null $decoded */
        $this->priceinfo = is_array($decoded) ? $decoded : null;
        return $this->priceinfo;
    }

    /**
     * Load hotel info for room capacities
     * @return array<string, mixed>|null
     */
    public function loadHotelInfo(string $hotelId): ?array
    {
        $hotel = $this->hotelRepo->findByIdAsDto($hotelId);
        $this->hotelinfo = $hotel?->rawHotelData;
        return $this->hotelinfo;
    }

    /**
     * Build code index for Code/Base resolution
     */
    public function buildCodeIndex(): void
    {
        $this->codeIndex = [];
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            return;
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = PriceInfoFormatter::toScalar($row['Code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($this->codeIndex[$code])) {
                $this->codeIndex[$code] = [];
            }
            $this->codeIndex[$code][] = $row;
        }
    }

    /**
     * Parse child age bands from hotelinfo ages data.
     */
    public function parseChildAgeBands(): void
    {
        $this->childAgeBands = [];

        if (empty($this->hotelinfo)) {
            return;
        }

        $ages = $this->hotelinfo['ages'] ?? [];

        if (is_array($ages) && isset($ages['age'])) {
            $ages = $ages['age'];
        }

        if (is_array($ages) && isset($ages['IdAge'])) {
            $ages = [$ages];
        }

        if (empty($ages) || !is_array($ages)) {
            return;
        }

        foreach ($ages as $age) {
            if (!is_array($age)) {
                continue;
            }
            $fAge = PriceInfoFormatter::toScalar($age['fAge'] ?? '0');
            $isChild = $fAge === '1';
            if (!$isChild) {
                continue;
            }

            $fromYear = PriceInfoFormatter::toFloat($age['FromYear'] ?? 0);
            $toYear = PriceInfoFormatter::toFloat($age['ToYear'] ?? 0);
            if ($toYear <= 0) {
                continue;
            }

            $label = PriceInfoFormatter::formatAgeBandLabel($fromYear, $toYear);

            $this->childAgeBands[] = [
                'from' => $fromYear,
                'to' => $toYear,
                'label' => $label,
                'id_age' => $age['IdAge'] ?? '',
            ];
        }

        usort($this->childAgeBands, fn ($a, $b) => $a['from'] <=> $b['from']);

        $this->log('Parsed hotel child age bands', $this->childAgeBands);
    }

    /**
     * Get room capacity (RB, EB, maxADT, maxCHD, minPAX)
     * @return array<string, mixed>
     */
    public function getRoomCapacity(string $roomId): array
    {
        $default = [
            'RB' => 2,
            'EB' => 1,
            'maxADT' => 2,
            'maxCHD' => 2,
            'minPAX' => 1,
        ];

        if (empty($this->hotelinfo) || !isset($this->hotelinfo['rooms'])) {
            return $default;
        }

        $rooms = $this->hotelinfo['rooms'];
        if (!is_array($rooms)) {
            return $default;
        }
        if (isset($rooms['IdRoom'])) {
            $rooms = [$rooms];
        }

        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $rid = PriceInfoFormatter::toScalar($room['IdRoom'] ?? '');
            if ($rid === $roomId || rawurldecode($rid) === $roomId) {
                return [
                    'RB' => PriceInfoFormatter::toInt($room['RegularBeds'] ?? $room['RB'] ?? 2),
                    'EB' => PriceInfoFormatter::toInt($room['ExtraBeds'] ?? $room['EB'] ?? 1),
                    'maxADT' => PriceInfoFormatter::toInt($room['maxADT'] ?? $room['MaxAdults'] ?? 2),
                    'maxCHD' => PriceInfoFormatter::toInt($room['maxCHD'] ?? $room['MaxChildren'] ?? 2),
                    'minPAX' => PriceInfoFormatter::toInt($room['minPAX'] ?? $room['MinPax'] ?? 1),
                ];
            }
        }

        return $default;
    }

    /**
     * Validate occupancy against room capacity
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function validateOccupancy(int $adults, int $children, array $capacity): array
    {
        $minPAX = PriceInfoFormatter::toInt($capacity['minPAX'] ?? 1);
        $maxADT = PriceInfoFormatter::toInt($capacity['maxADT'] ?? 2);
        $maxCHD = PriceInfoFormatter::toInt($capacity['maxCHD'] ?? 2);
        $rb = PriceInfoFormatter::toInt($capacity['RB'] ?? 2);
        $eb = PriceInfoFormatter::toInt($capacity['EB'] ?? 1);

        if ($adults < $minPAX) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) less than minPAX ({$minPAX})"];
        }

        if ($adults > $maxADT) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) exceeds maxADT ({$maxADT})"];
        }

        if ($children > $maxCHD) {
            return ['valid' => false, 'reason' => "Children ({$children}) exceeds maxCHD ({$maxCHD})"];
        }

        $totalPax = $adults + $children;
        $totalCapacity = $rb + $eb;
        if ($totalPax > $totalCapacity) {
            return ['valid' => false, 'reason' => "Total pax ({$totalPax}) exceeds capacity ({$totalCapacity})"];
        }

        return ['valid' => true];
    }

    /**
     * Build occupancy structure - who uses Regular Beds vs Extra Beds
     *
     * If a child's age band has no matching price row in this room,
     * the child is reclassified as an additional adult on EXTRA BED.
     * @param list<int> $childrenAges
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function buildOccupancyStructure(int $adults, array $childrenAges, array $capacity, string $roomId = '', string $boardId = ''): array
    {
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
        if (!empty($roomId) && !empty($boardId) && !empty($this->priceinfo)) {
            $availableBands = $this->getAvailableChildAgeBands($roomId, $boardId);
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
        if (!empty($roomId) && !empty($boardId) && !empty($this->priceinfo)) {
            $hasExtraBedAdultPricing = $this->hasAdultExtraBedPricing($roomId, $boardId);
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
            $ageBand = $this->getAgeBand($age);

            $bandHasPricing = true;
            if (!empty($availableBands)) {
                $bandNorm = str_replace(',', '.', $ageBand);
                $hasBand = false;
                foreach ($availableBands as $ab) {
                    if (str_replace(',', '.', (string) $ab) === $bandNorm) {
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

    /**
     * Get age band string for a child's age.
     */
    public function getAgeBand(float $age): string
    {
        if (!empty($this->childAgeBands)) {
            foreach ($this->childAgeBands as $band) {
                if ($age >= $band['from'] && $age <= $band['to']) {
                    return PriceInfoFormatter::toScalar($band['label']);
                }
            }
            return PriceInfoFormatter::formatAgeBandLabel(floor($age), 17.99);
        }

        if ($age < 2.0) {
            return '0-1,99';
        }
        if ($age < 12.0) {
            return '2-11,99';
        }
        return '12-17,99';
    }

    /**
     * Get available child age bands for a specific room+board from season_price data.
     * @return list<string|null>
     */
    public function getAvailableChildAgeBands(string $roomId, string $boardId): array
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            return [];
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $bands = [];
        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) {
                continue;
            }
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) {
                continue;
            }

            $rowAge = '';
            $fAgeVal = $row['fAge'] ?? null;
            if (!empty($fAgeVal) && is_string($fAgeVal)) {
                $rowAge = $fAgeVal;
            } else {
                $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
                static $ageTypeMap = ['1' => 'ADULT', '2' => 'CHD 0-1.99', '3' => 'CHD 2-11.99', '4' => 'CHD 12-17.99'];
                $rowAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }

            $rowAge = strtoupper(trim($rowAge));
            if (!str_contains($rowAge, 'CHD') && !str_contains($rowAge, 'CHILD')) {
                continue;
            }

            if (preg_match('/(\d+[\-\.]\d+[,\.]?\d*)/', $rowAge, $m)) {
                $band = str_replace('.', ',', $m[1]);
                $band = preg_replace('/(\d+)-(\d+),(\d+)/', '$1-$2,$3', $band);
                if (!in_array($band, $bands)) {
                    $bands[] = $band;
                }
            }
        }

        return $bands;
    }

    /**
     * Check if a room has adult extra bed pricing (3RD+ ADULT on EXTRA BED)
     */
    public function hasAdultExtraBedPricing(string $roomId, string $boardId): bool
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            return false;
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) {
                continue;
            }
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) {
                continue;
            }

            $rowAge = '';
            $fAgeVal2 = $row['fAge'] ?? null;
            if (!empty($fAgeVal2) && is_string($fAgeVal2)) {
                $rowAge = $fAgeVal2;
            } else {
                $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
                static $ageMap = ['1' => 'ADULT', '2' => 'CHD 0-1.99', '3' => 'CHD 2-11.99', '4' => 'CHD 12-17.99'];
                $rowAge = $ageMap[$rawIdAge] ?? $rawIdAge;
            }

            $rowAge = strtoupper(trim($rowAge));
            $rowAcc = strtoupper(trim(PriceInfoFormatter::toScalar($row['IdAcc'] ?? '')));

            if (
                preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $rowAge) &&
                ($rowAcc === 'EXTRA BED' || $rowAcc === 'EB' || $rowAcc === 'EXTRABED')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get season number for each night of the stay
     * @return list<array<string, int|string>>
     */
    public function getSeasonsByNight(string $checkIn, int $nights): array
    {
        $seasons = $this->priceinfo['seasons'] ?? [];

        if (!is_array($seasons)) {
            $seasons = [];
        } elseif (isset($seasons['Season'])) {
            $seasons = [$seasons];
        } elseif (isset($seasons[0]) && is_array($seasons[0]) && isset($seasons[0]['Season'])) {
            // Already an array
        } elseif (isset($seasons['season'])) {
            $seasons = $seasons['season'];
            if (is_array($seasons) && isset($seasons['Season'])) {
                $seasons = [$seasons];
            }
        }

        $result = [];
        $checkInDate = new \DateTime($checkIn);

        for ($night = 0; $night < $nights; $night++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$night} days");
            $dateStr = $currentDate->format('Y-m-d');

            $seasonNum = 1;
            if (is_array($seasons)) {
                foreach ($seasons as $season) {
                    if (!is_array($season)) {
                        continue;
                    }
                    $from = PriceInfoFormatter::toScalar($season['FromDate'] ?? $season['DateFrom'] ?? '');
                    $to = PriceInfoFormatter::toScalar($season['ToDate'] ?? $season['DateTo'] ?? '');
                    $id = PriceInfoFormatter::toInt($season['Season'] ?? $season['IdSeason'] ?? 1);

                    if ($dateStr >= $from && $dateStr <= $to) {
                        $seasonNum = $id;
                        break;
                    }
                }
            }

            $result[$night] = [
                'date' => $dateStr,
                'season' => $seasonNum,
            ];
        }

        return array_values($result);
    }

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
