<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Resolves child age bands from hotelinfo + season_price data: parses the
 * hotel's configured child bands, maps a concrete age to its band label, and
 * inspects season_price rows to discover which child bands (and whether
 * extra-bed adult pricing) exist for a given room/board.
 *
 * Extracted from PriceInfoParser, where this age-band logic was tangled with
 * the stateful loading/occupancy code. The resolver is stateless — every input
 * (hotelinfo, priceinfo, the parsed bands) is passed explicitly — so it is
 * directly unit-testable. PriceInfoParser keeps thin public wrappers that
 * supply its state. Behaviour is preserved; the only adjustment is replacing
 * the numeric-keyed IdAge lookup (which both static analysers mistype) with an
 * equivalent match, and consolidating the duplicated row-age resolution.
 */
class AgeBandResolver
{
    /**
     * Parse child age bands from hotelinfo ages data.
     *
     * @param array<string, mixed>|null $hotelinfo
     * @return list<array<string, mixed>>
     */
    public function parseChildAgeBands(?array $hotelinfo): array
    {
        $bands = [];

        if (empty($hotelinfo)) {
            return $bands;
        }

        $ages = $hotelinfo['ages'] ?? [];

        if (is_array($ages) && isset($ages['age'])) {
            $ages = $ages['age'];
        }

        if (is_array($ages) && isset($ages['IdAge'])) {
            $ages = [$ages];
        }

        if (empty($ages) || !is_array($ages)) {
            return $bands;
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

            $bands[] = [
                'from' => $fromYear,
                'to' => $toYear,
                'label' => $label,
                'id_age' => $age['IdAge'] ?? '',
            ];
        }

        usort($bands, fn ($a, $b): int => $a['from'] <=> $b['from']);

        return $bands;
    }

    /**
     * Get age band string for a child's age.
     *
     * @param list<array<string, mixed>> $childAgeBands
     */
    public function getAgeBand(float $age, array $childAgeBands): string
    {
        if (!empty($childAgeBands)) {
            foreach ($childAgeBands as $band) {
                $from = PriceInfoFormatter::toFloat($band['from'] ?? 0);
                $to = PriceInfoFormatter::toFloat($band['to'] ?? 0);
                if ($age >= $from && $age <= $to) {
                    return PriceInfoFormatter::toScalar($band['label'] ?? '');
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
     *
     * @param array<string, mixed>|null $priceinfo
     * @return list<string|null>
     */
    public function getAvailableChildAgeBands(?array $priceinfo, string $roomId, string $boardId): array
    {
        $bands = [];
        foreach ($this->seasonPriceRows($priceinfo) as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) {
                continue;
            }
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) {
                continue;
            }

            $rowAge = strtoupper(trim($this->resolveRowAge($row)));
            if (!str_contains($rowAge, 'CHD') && !str_contains($rowAge, 'CHILD')) {
                continue;
            }

            if (preg_match('/(\d+[-.]\d+[,.]?\d*)/', $rowAge, $m) === 1) {
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
     * Check if a room has adult extra bed pricing (3RD+ ADULT on EXTRA BED).
     *
     * @param array<string, mixed>|null $priceinfo
     */
    public function hasAdultExtraBedPricing(?array $priceinfo, string $roomId, string $boardId): bool
    {
        foreach ($this->seasonPriceRows($priceinfo) as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) {
                continue;
            }
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) {
                continue;
            }

            $rowAge = strtoupper(trim($this->resolveRowAge($row)));
            $rowAcc = strtoupper(trim(PriceInfoFormatter::toScalar($row['IdAcc'] ?? '')));

            if (
                preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $rowAge) === 1 &&
                ($rowAcc === 'EXTRA BED' || $rowAcc === 'EB' || $rowAcc === 'EXTRABED')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a season_price row's age label: prefer the explicit fAge string,
     * else map the numeric IdAge code (1-4) to its canonical label.
     *
     * @param array<int|string, mixed> $row
     */
    private function resolveRowAge(array $row): string
    {
        $fAgeVal = $row['fAge'] ?? null;
        if (!empty($fAgeVal) && is_string($fAgeVal)) {
            return $fAgeVal;
        }

        $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
        return match ($rawIdAge) {
            '1' => 'ADULT',
            '2' => 'CHD 0-1.99',
            '3' => 'CHD 2-11.99',
            '4' => 'CHD 12-17.99',
            default => $rawIdAge,
        };
    }

    /**
     * Normalise the season_price block into a list of row arrays.
     *
     * @param array<string, mixed>|null $priceinfo
     * @return list<array<int|string, mixed>>
     */
    private function seasonPriceRows(?array $priceinfo): array
    {
        $seasonPrices = is_array($priceinfo) ? ($priceinfo['season_price'] ?? []) : [];
        if (!is_array($seasonPrices)) {
            return [];
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $rows = [];
        foreach ($seasonPrices as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
