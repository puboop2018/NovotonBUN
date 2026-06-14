<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Calculates the percentage-based reductions (additional promo and marketing)
 * from the parser's priceinfo.
 *
 * Extracted from DiscountCalculator, where these two routines made up a third
 * of the class. The additional reduction is a flat percentage off the subtotal;
 * the marketing reduction supports booking/travel date windows, room filtering,
 * and a minimum stay, picking the best (largest) positive discount. Behaviour is
 * preserved verbatim; DiscountCalculator delegates here from its public methods.
 */
class ReductionPercentageCalculator
{
    private ?\Closure $logger;

    public function __construct(
        private readonly PriceInfoParser $parser,
        ?callable $logger = null,
    ) {
        $this->logger = $logger !== null ? $logger(...) : null;
    }

    /**
     * Calculate reduction_perc_additional (percentage promo discount).
     *
     * Applied as a flat percentage off the subtotal (base + fees - EB/reduction).
     *
     * @return array<string, mixed>
     */
    public function calculateReductionPercAdditional(float $subtotal): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_additional'] ?? [];
        if (empty($entries) || !is_array($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'name' => ''];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $totalPercent = 0.0;
        $names = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $perc = PriceInfoFormatter::toFloat($entry['Perc'] ?? 0);
            $name = PriceInfoFormatter::toScalar($entry['Name'] ?? '');

            if ($perc <= 0) {
                continue;
            }

            $totalPercent += $perc;
            if (!empty($name)) {
                $names[] = $name;
            }
        }

        if ($totalPercent <= 0) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'name' => ''];
        }

        $discount = $subtotal * ($totalPercent / 100);

        $this->log('reduction_perc_additional', [
            'percent' => $totalPercent,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'names' => $names,
        ]);

        return [
            'applicable' => true,
            'discount' => $discount,
            'percent' => $totalPercent,
            'name' => implode(', ', $names),
        ];
    }

    /**
     * Calculate reduction_perc_marketing (marketing percentage discount).
     *
     * Supports date restrictions, room type filtering, minimum stay, and Type.
     *
     * @return array<string, mixed>
     */
    public function calculateReductionPercMarketing(
        string $bookingDate,
        string $checkIn,
        int $nights,
        string $roomId,
        float $subtotal,
    ): array {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_marketing'] ?? [];
        if (empty($entries) || !is_array($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'is_surcharge' => false, 'name' => '', 'details' => []];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $bestPercent = 0.0;
        $bestName = '';
        $applicable = false;
        $details = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $perc = PriceInfoFormatter::toFloat($entry['Perc'] ?? 0);
            $name = PriceInfoFormatter::toScalar($entry['Name'] ?? '');
            $bookFrom = PriceInfoFormatter::toScalar($entry['BookingFrom'] ?? '');
            $bookTo = PriceInfoFormatter::toScalar($entry['BookingTo'] ?? '');
            $travelFrom = PriceInfoFormatter::toScalar($entry['TravelTimeFrom'] ?? '');
            $travelTo = PriceInfoFormatter::toScalar($entry['TravelTimeTo'] ?? '');
            $roomTypes = PriceInfoFormatter::toScalar($entry['RoomTypes'] ?? '');
            $minStay = PriceInfoFormatter::toInt($entry['MinimumStay'] ?? 0);
            $type = PriceInfoFormatter::toScalar($entry['Type'] ?? '');

            // Business rule: negative commission (a surcharge / markup) is not
            // allowed. Ignore any non-positive Perc so a marketing entry can
            // only ever reduce the price, never increase it.
            if ($perc <= 0.0) {
                continue;
            }

            if (!empty($bookFrom) && $bookingDate < $bookFrom) {
                continue;
            }
            if (!empty($bookTo) && $bookingDate > $bookTo) {
                continue;
            }

            if (!empty($travelFrom) && $checkIn < $travelFrom) {
                continue;
            }
            if (!empty($travelTo) && $checkIn > $travelTo) {
                continue;
            }

            if (!empty($roomTypes) && !empty($roomId)) {
                $allowedRooms = array_map('trim', explode(',', $roomTypes));
                $roomMatch = false;
                foreach ($allowedRooms as $allowed) {
                    if (PriceInfoFormatter::matchRoom($allowed, $roomId)) {
                        $roomMatch = true;
                        break;
                    }
                }
                if (!$roomMatch) {
                    continue;
                }
            }

            if ($minStay > 0 && $nights < $minStay) {
                continue;
            }

            $applicable = true;

            // Only positive discounts reach here (negatives skipped above);
            // pick the largest, i.e. the best deal for the customer.
            if ($perc > $bestPercent) {
                $bestPercent = $perc;
                $bestName = $name;
            }

            $details[] = [
                'name' => $name,
                'percent' => $perc,
                'booking_range' => $bookFrom . ' - ' . $bookTo,
                'travel_range' => $travelFrom . ' - ' . $travelTo,
                'room_types' => $roomTypes,
                'min_stay' => $minStay,
                'type' => $type,
                'matched' => true,
            ];
        }

        if (!$applicable) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'is_surcharge' => false, 'name' => '', 'details' => $details];
        }

        $discount = $subtotal * ($bestPercent / 100);
        // Surcharges are never applied (negative Perc is skipped above), so a
        // marketing reduction is always a discount. Kept in the contract for
        // callers/tests that branch on it.
        $isSurcharge = false;

        $this->log('reduction_perc_marketing', [
            'best_percent' => $bestPercent,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'is_surcharge' => $isSurcharge,
            'name' => $bestName,
            'details' => $details,
        ]);

        return [
            'applicable' => $applicable,
            'discount' => $discount,
            'percent' => $bestPercent,
            'is_surcharge' => $isSurcharge,
            'name' => $bestName,
            'details' => $details,
        ];
    }

    /**
     * Forward a debug message to the injected logger, if any.
     */
    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $data);
        }
    }
}
