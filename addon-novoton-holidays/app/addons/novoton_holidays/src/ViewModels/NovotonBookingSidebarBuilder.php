<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\ViewModels;

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel;

/**
 * Builds the booking page's hotel header + summary sidebar for novoton.
 *
 * Lives here rather than inside booking_form.php because TWO controller modes
 * render the same page: `booking_form` (create) and `edit_booking` (change the
 * guest names on a cart line). Only the first one ever built the sidebar, and
 * because components/booking_sidebar.tpl is wrapped in `{if $tbs}`, the whole
 * left column of the edit page silently rendered as nothing — an isolated bare
 * form with no hotel, no dates, no price, and an empty "What are my booking
 * conditions?" modal. One builder, called by both modes, is what stops those
 * two pages drifting apart again.
 *
 * Edit mode shows the BOOKED figures and never re-quotes: a guest correcting a
 * misspelled name must not have the price move under them.
 */
final class NovotonBookingSidebarBuilder
{
    /**
     * The shared hotel identity (name, stars, sanitized location line,
     * always-present map URL) both modes assign.
     *
     * @param array<string, mixed> $hotelInfo a ?:novoton_hotels row, or [] when unknown
     * @param string $starsGlyphs '★★★★' from star_rating, or legacy '*'s parsed
     *                            out of the hotel name — either form is counted
     */
    public static function header(
        string $hotelId,
        array $hotelInfo,
        string $starsGlyphs,
        int $productId,
        string $fallbackName = '',
    ): HotelHeaderViewModel {
        $seo = new HotelSeoData(
            hotelId: $hotelId,
            providerName: 'novoton',
            name: TypeCoerce::toString($hotelInfo['hotel_name'] ?? $fallbackName),
            city: TypeCoerce::toString($hotelInfo['city'] ?? ''),
            region: TypeCoerce::toString($hotelInfo['region'] ?? ''),
            country: TypeCoerce::toString($hotelInfo['country'] ?? ''),
            latitude: TypeCoerce::toFloat($hotelInfo['latitude'] ?? 0),
            longitude: TypeCoerce::toFloat($hotelInfo['longitude'] ?? 0),
            address: TypeCoerce::toString($hotelInfo['street_address'] ?? ''),
        );

        return HotelHeaderFactory::fromSeo(
            $seo,
            mb_substr_count($starsGlyphs, '★') + substr_count($starsGlyphs, '*'),
            $productId,
            implode(', ', array_filter([
                TypeCoerce::toString($hotelInfo['city'] ?? ''),
                TypeCoerce::toString($hotelInfo['region'] ?? ''),
                TypeCoerce::toString($hotelInfo['country'] ?? ''),
            ], static fn (string $part): bool => $part !== '')),
        );
    }

    /**
     * The summary sidebar, from the same `$booking` array both modes assemble.
     *
     * Every value is formatted here, because the shared template carries no
     * provider branches — novoton derives its summary from URL params + the
     * hotels table, sphinx from a verified live offer.
     *
     * Cancellation terms are deliberately absent: novoton only returns them
     * with a price quote, and the page already re-verifies the price on load
     * (booking-form.js). The card ships empty and is filled by that existing
     * round-trip — no second API call just to render a policy.
     *
     * @param array<string, mixed> $booking the `booking_data` view variable
     */
    public static function sidebar(
        array $booking,
        HotelHeaderViewModel $header,
        int $productId,
        string $packageName,
        float $displayCoefficient,
        string $displaySymbol,
        bool $available = true,
        string $lang = 'en',
    ): BookingSidebarViewModel {
        $roomsData = TypeCoerce::toRowList($booking['rooms_data'] ?? []);
        $occupancy = BookingSidebarFactory::occupancy($roomsData);
        $checkIn = TypeCoerce::toString($booking['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($booking['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);

        $package = $packageName !== '' ? $packageName : TypeCoerce::toString($booking['package_name'] ?? '');

        return new BookingSidebarViewModel(
            imagePair: function_exists('fn_travel_core_product_main_pair')
                ? fn_travel_core_product_main_pair($productId)
                : [],
            name: $header->name,
            stars: $header->stars,
            available: $available,
            locationLine: $header->locationLine,
            mapUrl: $header->mapUrl,
            features: Container::getInstance()->facilityRepository()->getLabelsForHotel(
                TypeCoerce::toString($booking['hotel_id'] ?? ''),
                $lang,
            ),
            packageName: $package !== $header->name ? $package : '',
            // Store-configured format (Settings -> Appearance), NOT a hardcoded
            // one: the cancellation lines below the summary already follow it,
            // and a card showing "Check-in 07.09.2026" beside "free until
            // 08/28/2026" is the bug this replaced.
            checkIn: $checkInTs > 0 ? DateHelper::formatStoreDate($checkInTs) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatStoreWeekday($checkInTs) : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatStoreDate($checkOutTs) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatStoreWeekday($checkOutTs) : '',
            nights: TypeCoerce::toInt($booking['nights'] ?? 0),
            rooms: max(1, TypeCoerce::toInt($booking['num_rooms'] ?? 1)),
            adults: $occupancy['adults'] > 0 ? $occupancy['adults'] : TypeCoerce::toInt($booking['adults'] ?? 0),
            children: $occupancy['children'] > 0 ? $occupancy['children'] : TypeCoerce::toInt($booking['children'] ?? 0),
            roomLines: BookingSidebarFactory::roomLines($roomsData),
            boardName: TypeCoerce::toString($roomsData[0]['board_name'] ?? ''),
            changeUrl: BookingSidebarFactory::changeSelectionUrl($productId, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => TypeCoerce::toInt($booking['adults'] ?? 0),
                'children' => TypeCoerce::toInt($booking['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($booking['children_ages'] ?? ''),
                'rooms' => TypeCoerce::toInt($booking['num_rooms'] ?? 1),
            ]),
            productId: $productId,
            total: fn_novoton_holidays_format_price(
                PriceInfoFormatter::toFloat($booking['total_price'] ?? 0),
                $displayCoefficient,
                $displaySymbol,
            ),
        );
    }
}
