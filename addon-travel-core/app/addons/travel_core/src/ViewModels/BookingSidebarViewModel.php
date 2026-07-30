<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

/**
 * The booking-form summary sidebar shared by both providers.
 *
 * One 2-column layout, one sidebar: hotel identity (hero image, stars above
 * the name with the availability badge beside them, location, features), the
 * booking summary, the price box and the cancellation card. Rendered by
 * design/themes/.../addons/travel_core/components/booking_sidebar.tpl from the
 * `travel_booking_sidebar` view variable.
 *
 * Deliberately a dumb value object: every field arrives already localized and
 * already formatted by the provider controller (dates, prices, room names),
 * because the two providers derive them from completely different payloads —
 * novoton from URL params + its hotels table, sphinx from a verified live
 * offer. Keeping the formatting on their side is what lets ONE template serve
 * both without a single `{if $provider}` branch in the markup.
 *
 * Sibling of HotelHeaderViewModel, which stays in use on the search-results
 * pages; the booking form needs a different stacking order (stars ABOVE the
 * name) plus an image, features and money, so it gets its own model rather
 * than five optional parameters bolted onto the header.
 */
final class BookingSidebarViewModel
{
    /**
     * @param array<string, mixed> $imagePair CS-Cart image pair (main_pair) —
     *                                        rendered through common/image.tpl so the store's
     *                                        Settings → Thumbnails sizes apply. Empty when the product has no
     *                                        image; $imageUrl is then the fallback.
     * @param list<string> $features Localized hotel-feature labels.
     * @param list<array{qty: int, name: string}> $roomLines One entry per
     *                                                       distinct booked room type ("1x Camera Dubla").
     * @param list<string> $cancelLines Formatted cancellation-policy lines.
     * @param list<string> $paymentLines Formatted payment-terms lines. Shown in
     *                                   the "What are my booking conditions?" modal beside the
     *                                   cancellation policy.
     * @param string $cancelFreeUntil Already-formatted date up to which
     *                                cancelling is free — rendered in green, the same
     *                                treatment the search-results card gives it.
     */
    public function __construct(
        public readonly array $imagePair = [],
        public readonly string $imageUrl = '',
        public readonly string $name = '',
        public readonly int $stars = 0,
        public readonly bool $available = true,
        public readonly string $locationLine = '',
        public readonly string $mapUrl = '',
        public readonly array $features = [],
        public readonly string $packageName = '',
        public readonly string $checkIn = '',
        public readonly string $checkInWeekday = '',
        public readonly string $checkOut = '',
        public readonly string $checkOutWeekday = '',
        public readonly int $nights = 0,
        public readonly int $rooms = 1,
        public readonly int $adults = 0,
        public readonly int $children = 0,
        public readonly array $roomLines = [],
        public readonly string $boardName = '',
        public readonly string $changeUrl = '',
        public readonly int $productId = 0,
        public readonly string $total = '',
        public readonly string $oldTotal = '',
        public readonly array $cancelLines = [],
        public readonly string $cancelFullAmount = '',
        public readonly string $cancelFreeUntil = '',
        public readonly array $paymentLines = [],
        public readonly string $roomLabel = '',
    ) {
    }

    /**
     * The Smarty-facing shape read by components/booking_sidebar.tpl.
     *
     * @return array<string, mixed>
     */
    public function toViewArray(): array
    {
        return [
            'image_pair' => $this->imagePair,
            'image_url' => $this->imageUrl,
            'name' => $this->name,
            'stars' => min(5, max(0, $this->stars)),
            'available' => $this->available,
            'location_line' => $this->locationLine,
            'map_url' => $this->mapUrl,
            'features' => $this->features,
            'package_name' => $this->packageName,
            'check_in' => $this->checkIn,
            'check_in_weekday' => $this->checkInWeekday,
            'check_out' => $this->checkOut,
            'check_out_weekday' => $this->checkOutWeekday,
            'nights' => max(0, $this->nights),
            'rooms' => max(1, $this->rooms),
            'adults' => max(0, $this->adults),
            'children' => max(0, $this->children),
            'room_lines' => $this->roomLines,
            'board_name' => $this->boardName,
            'change_url' => $this->changeUrl,
            'product_id' => max(0, $this->productId),
            'total' => $this->total,
            'old_total' => $this->oldTotal,
            'cancel_lines' => $this->cancelLines,
            'cancel_full_amount' => $this->cancelFullAmount,
            'cancel_free_until' => $this->cancelFreeUntil,
            'payment_lines' => $this->paymentLines,
            'room_label' => $this->roomLabel,
        ];
    }
}
