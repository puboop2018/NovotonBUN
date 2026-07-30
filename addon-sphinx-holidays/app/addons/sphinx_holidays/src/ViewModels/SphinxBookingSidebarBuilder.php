<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\ViewModels;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\TermsFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel;

/**
 * Builds the booking page's summary sidebar for sphinx.
 *
 * Two controller modes render the same page — `booking_form` (create) and
 * `edit_booking` (change the guest names on a cart line) — but only the first
 * ever built the sidebar. Because components/booking_sidebar.tpl is wrapped in
 * `{if $tbs}`, the edit page's entire left column rendered as nothing: an
 * isolated bare form with no hotel, no dates, no price, and an empty "What are
 * my booking conditions?" modal (which reads the same variable). One builder
 * for both modes is what stops them drifting apart again.
 *
 * Edit mode reads the STORED cart line, never a live offer: SphinxEditBooking
 * pins that edit_booking must not call verifyHotelOffer, and re-pricing while
 * a guest fixes a misspelled name would be wrong regardless.
 */
final class SphinxBookingSidebarBuilder
{
    /**
     * Every value arrives at the shared template pre-formatted, because that
     * template carries no provider branches.
     *
     * @param array<string, mixed> $bookingData the `sphinx_booking_data` view
     *                                          variable both modes already assemble
     * @param array<string, mixed>|null $hotelRow a ?:sphinx_hotels row, for the
     *                                            fallback image and the facility labels
     * @param mixed $cancellationFees the raw cancellation payload — the free-until
     *                                date can only be derived from the rules, not from the
     *                                already-formatted lines
     */
    public static function build(
        array $bookingData,
        HotelHeaderViewModel $header,
        ?array $hotelRow,
        string $formattedTotal,
        mixed $cancellationFees = null,
        bool $available = true,
        string $lang = 'en',
    ): BookingSidebarViewModel {
        $productId = TypeCoerce::toInt($bookingData['product_id'] ?? 0);
        $roomsData = TypeCoerce::toRowList($bookingData['rooms_data'] ?? []);
        $roomName = TypeCoerce::toString($bookingData['room_name'] ?? '');
        $checkIn = TypeCoerce::toString($bookingData['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($bookingData['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);

        // Already-formatted display lines, as the cart line stores them.
        $cancelLines = TypeCoerce::toStringList($bookingData['cancellation_fees'] ?? []);
        $paymentLines = TypeCoerce::toStringList($bookingData['payment_terms'] ?? []);

        return new BookingSidebarViewModel(
            imagePair: function_exists('fn_travel_core_product_main_pair')
                ? fn_travel_core_product_main_pair($productId)
                : [],
            imageUrl: $hotelRow !== null ? TypeCoerce::toString($hotelRow['image_url'] ?? '') : '',
            name: $header->name,
            stars: $header->stars,
            available: $available,
            locationLine: $header->locationLine,
            mapUrl: $header->mapUrl,
            features: $hotelRow !== null
                ? Container::getFeatureAssigner()->getHotelFacilityLabels($hotelRow, $lang)
                : [],
            // Store-configured format (Settings -> Appearance), NOT a hardcoded
            // one — the cancellation lines in the same sidebar follow it too.
            checkIn: $checkInTs > 0 ? DateHelper::formatStoreDate($checkInTs) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatStoreWeekday($checkInTs) : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatStoreDate($checkOutTs) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatStoreWeekday($checkOutTs) : '',
            nights: TypeCoerce::toInt($bookingData['nights'] ?? 0),
            rooms: max(1, TypeCoerce::toInt($bookingData['num_rooms'] ?? 1)),
            adults: TypeCoerce::toInt($bookingData['adults'] ?? 0),
            children: TypeCoerce::toInt($bookingData['children'] ?? 0),
            roomLines: BookingSidebarFactory::roomLines($roomsData, $roomName),
            boardName: TypeCoerce::toString($bookingData['board_name'] ?? ''),
            changeUrl: BookingSidebarFactory::changeSelectionUrl($productId, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 0),
                'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($bookingData['children_ages'] ?? ''),
                'rooms' => max(1, TypeCoerce::toInt($bookingData['num_rooms'] ?? 1)),
            ]),
            productId: $productId,
            total: $formattedTotal,
            cancelLines: $cancelLines,
            cancelFullAmount: BookingSidebarFactory::fullChargeAmount($cancelLines, $formattedTotal),
            cancelFreeUntil: TypeCoerce::toString(TermsFormatter::freeCancellationUntil($cancellationFees)),
            paymentLines: $paymentLines,
            roomLabel: $roomName,
        );
    }
}
