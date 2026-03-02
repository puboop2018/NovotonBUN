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

class PriceInfoParser
{
    /** @var array|null Priceinfo data */
    private $priceinfo;

    /** @var array|null Hotel info */
    private $hotelinfo;

    /** @var array Code index for Code/Base resolution */
    private $codeIndex = [];

    /** @var array Hotel-specific child age bands */
    private $childAgeBands = [];

    /** @var callable|null Logger function */
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    // -- Getters for parsed data ------------------------------------------

    public function getPriceinfo(): ?array { return $this->priceinfo; }
    public function getHotelinfo(): ?array { return $this->hotelinfo; }
    public function getCodeIndex(): array { return $this->codeIndex; }
    public function getChildAgeBands(): array { return $this->childAgeBands; }

    /**
     * Set priceinfo directly (used by debug tools that bypass loadPriceInfo)
     */
    public function setPriceinfo(array $priceinfo): void
    {
        $this->priceinfo = $priceinfo;
    }

    /**
     * Load priceinfo data from database
     */
    public function loadPriceInfo(string $hotelId, string $packageName): ?array
    {
        $json = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND package_name = ?s",
            $hotelId,
            $packageName
        );

        if (empty($json)) {
            return null;
        }

        $this->priceinfo = json_decode($json, true);
        return $this->priceinfo;
    }

    /**
     * Load hotel info for room capacities
     */
    public function loadHotelInfo(string $hotelId): ?array
    {
        $json = db_get_field(
            "SELECT hotel_data FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId
        );

        if (empty($json)) {
            return null;
        }

        $this->hotelinfo = json_decode($json, true);
        return $this->hotelinfo;
    }

    /**
     * Build code index for Code/Base resolution
     */
    public function buildCodeIndex(): void
    {
        $this->codeIndex = [];
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            $code = PriceInfoFormatter::toScalar($row['Code'] ?? '');
            if ($code === '') continue;
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

        if (isset($ages['age'])) {
            $ages = $ages['age'];
        }

        if (isset($ages['IdAge'])) {
            $ages = [$ages];
        }

        if (empty($ages) || !is_array($ages)) {
            return;
        }

        foreach ($ages as $age) {
            $fAge = (string)($age['fAge'] ?? '0');
            $isChild = $fAge === '1';
            if (!$isChild) {
                continue;
            }

            $fromYear = (float) ($age['FromYear'] ?? 0);
            $toYear = (float) ($age['ToYear'] ?? 0);
            if ($toYear <= 0) {
                continue;
            }

            $label = PriceInfoFormatter::formatAgeBandLabel($fromYear, $toYear);

            $this->childAgeBands[] = [
                'from' => $fromYear,
                'to' => $toYear,
                'label' => $label,
                'id_age' => $age['IdAge'] ?? ''
            ];
        }

        usort($this->childAgeBands, function ($a, $b) {
            return $a['from'] <=> $b['from'];
        });

        $this->log('Parsed hotel child age bands', $this->childAgeBands);
    }

    /**
     * Get room capacity (RB, EB, maxADT, maxCHD, minPAX)
     */
    public function getRoomCapacity(string $roomId): array
    {
        $default = [
            'RB' => 2,
            'EB' => 1,
            'maxADT' => 2,
            'maxCHD' => 2,
            'minPAX' => 1
        ];

        if (empty($this->hotelinfo) || !isset($this->hotelinfo['rooms'])) {
            return $default;
        }

        $rooms = $this->hotelinfo['rooms'];
        if (isset($rooms['IdRoom'])) {
            $rooms = [$rooms];
        }

        foreach ($rooms as $room) {
            $rid = $room['IdRoom'] ?? '';
            if ($rid === $roomId || rawurldecode($rid) === $roomId) {
                return [
                    'RB' => (int) ($room['RegularBeds'] ?? $room['RB'] ?? 2),
                    'EB' => (int) ($room['ExtraBeds'] ?? $room['EB'] ?? 1),
                    'maxADT' => (int) ($room['maxADT'] ?? $room['MaxAdults'] ?? 2),
                    'maxCHD' => (int) ($room['maxCHD'] ?? $room['MaxChildren'] ?? 2),
                    'minPAX' => (int) ($room['minPAX'] ?? $room['MinPax'] ?? 1)
                ];
            }
        }

        return $default;
    }

    /**
     * Validate occupancy against room capacity
     */
    public function validateOccupancy(int $adults, int $children, array $capacity): array
    {
        if ($adults < $capacity['minPAX']) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) less than minPAX ({$capacity['minPAX']})"];
        }

        if ($adults > $capacity['maxADT']) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) exceeds maxADT ({$capacity['maxADT']})"];
        }

        if ($children > $capacity['maxCHD']) {
            return ['valid' => false, 'reason' => "Children ({$children}) exceeds maxCHD ({$capacity['maxCHD']})"];
        }

        $totalPax = $adults + $children;
        $totalCapacity = $capacity['RB'] + $capacity['EB'];
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
     */
    public function buildOccupancyStructure(int $adults, array $childrenAges, array $capacity, string $roomId = '', string $boardId = ''): array
    {
        $occupancy = [
            'adults' => [],
            'children' => [],
            'children_as_adults' => [],
            'total_rb_used' => 0,
            'total_eb_used' => 0
        ];

        $rbAvailable = $capacity['RB'];
        $ebAvailable = $capacity['EB'];
        $rbUsed = 0;
        $ebUsed = 0;

        $availableBands = [];
        if (!empty($roomId) && !empty($boardId) && !empty($this->priceinfo)) {
            $availableBands = $this->getAvailableChildAgeBands($roomId, $boardId);
            $this->log('Available child age bands for room', [
                'room_id' => $roomId,
                'board_id' => $boardId,
                'bands' => $availableBands
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
                    'acc_type' => 'REGULAR'
                ];
                $rbUsed++;
            } else {
                $ordinal = PriceInfoFormatter::getOrdinal($i + 1);
                $occupancy['adults'][] = [
                    'index' => $i + 1,
                    'bed_type' => 'EXTRA BED',
                    'age_type' => $ordinal . ' ADULT',
                    'acc_type' => 'EXTRA BED'
                ];
                $ebUsed++;
            }
        }

        // Place children
        $childOrdinalCounter = 0;
        foreach ($childrenAges as $idx => $age) {
            $ageBand = $this->getAgeBand($age);

            $bandHasPricing = true;
            if (!empty($availableBands)) {
                $bandNorm = str_replace(',', '.', $ageBand);
                $hasBand = false;
                foreach ($availableBands as $ab) {
                    if (str_replace(',', '.', $ab) === $bandNorm) {
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
                    'reclassified_as' => $ordinal . ' ADULT'
                ]);

                if ($rbUsed < $rbAvailable) {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'REGULAR',
                        'age_type' => 'ADULT ',
                        'acc_type' => 'REGULAR',
                        'original_child_age' => $age,
                        'reclassified' => true
                    ];
                    $rbUsed++;
                } else {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'EXTRA BED',
                        'age_type' => $ordinal . ' ADULT',
                        'acc_type' => 'EXTRA BED',
                        'original_child_age' => $age,
                        'reclassified' => true
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
                    'by_1_ad' => ($adults == 1)
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
                    'by_1_ad' => ($adults == 1)
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
                    return $band['label'];
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
     */
    public function getAvailableChildAgeBands(string $roomId, string $boardId): array
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $bands = [];
        foreach ($seasonPrices as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) continue;
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) continue;

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
            if (strpos($rowAge, 'CHD') === false && strpos($rowAge, 'CHILD') === false) {
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
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) continue;
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) continue;

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

            if (preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $rowAge) &&
                ($rowAcc === 'EXTRA BED' || $rowAcc === 'EB' || $rowAcc === 'EXTRABED')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get season number for each night of the stay
     */
    public function getSeasonsByNight(string $checkIn, int $nights): array
    {
        $seasons = $this->priceinfo['seasons'] ?? [];

        if (isset($seasons['Season'])) {
            $seasons = [$seasons];
        } elseif (isset($seasons[0]['Season'])) {
            // Already an array
        } elseif (isset($seasons['season'])) {
            $seasons = $seasons['season'];
            if (isset($seasons['Season'])) {
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
            foreach ($seasons as $season) {
                $from = $season['FromDate'] ?? '';
                $to = $season['ToDate'] ?? '';
                $id = (int) ($season['Season'] ?? $season['IdSeason'] ?? 1);

                if ($dateStr >= $from && $dateStr <= $to) {
                    $seasonNum = $id;
                    break;
                }
            }

            $result[$night] = [
                'date' => $dateStr,
                'season' => $seasonNum
            ];
        }

        return $result;
    }

    private function log(string $message, $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
