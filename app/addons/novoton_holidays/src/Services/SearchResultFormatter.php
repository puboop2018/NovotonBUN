<?php
declare(strict_types=1);
/**
 * Prepares the complete set of Smarty template variables for the search page.
 *
 * Loads hotel display info, extracts terms / early-booking details, handles
 * currency, and sets page meta / SEO / breadcrumbs.  Also provides a
 * safe-defaults method for error/empty-state rendering.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Tygh;

class SearchResultFormatter
{
    /**
     * Assign all search-result template variables.
     *
     * @param array $results        Primary result rows
     * @param array $novotonParams  Template params (from normalizer)
     * @param array $searchResult   Output from HotelAvailabilitySearcher::search()
     * @param array $altResult      Output from AlternativeDateSearcher::search()
     * @param array $searchParams   Raw (sanitized) request params
     * @param array $debugLog       Debug lines (empty when debug is off)
     */
    public function assignToView(
        array $results,
        array $novotonParams,
        array $searchResult,
        array $altResult,
        array $searchParams,
        array $debugLog
    ): void {
        $view = Tygh::$app['view'];

        // ── Core results ─────────────────────────────────────────────
        $view->assign('novoton_results', $results);
        $view->assign('novoton_params', $novotonParams);

        // ── Currency display ─────────────────────────────────────────
        $this->assignCurrency($view);

        // ── Multi-room data ──────────────────────────────────────────
        if ($searchResult['is_multi_room']) {
            $view->assign('all_room_results', $searchResult['all_room_results']);
            $view->assign('is_multi_room_search', true);
            $view->assign('multi_room_total_options', $searchResult['multi_room_total_options']);
        }

        $view->assign('max_room_capacity', $searchResult['max_room_capacity'] ?? [
            'adults' => 2, 'children' => 2, 'total' => 4,
        ]);

        // ── Early-booking discounts ──────────────────────────────────
        $view->assign('early_booking_discounts', $searchResult['early_booking_discounts'] ?? []);
        $view->assign('early_booking_range', $searchResult['early_booking_range'] ?? []);

        // ── Alternative dates ────────────────────────────────────────
        $noAvailability = $searchResult['no_availability'] ?? empty($results);
        $view->assign('alternative_results', $altResult['results'] ?? []);
        $view->assign('alternative_check_in', $altResult['check_in'] ?? '');
        $view->assign('alternative_check_out', $altResult['check_out'] ?? '');
        $view->assign('no_availability_message', $noAvailability);
        $view->assign('flex_days', $novotonParams['flex_days'] ?? 0);
        $view->assign('flex_dates_searched',
            (($novotonParams['flex_days'] ?? 0) > 0 && !empty($altResult['check_in']))
        );

        // ── Hotel display info ───────────────────────────────────────
        $hotelId   = $novotonParams['hotel_id'] ?? '';
        $productId = $novotonParams['product_id'] ?? 0;
        $this->assignHotelDisplay($view, $hotelId, $productId);

        // ── Terms & early-booking tooltip ────────────────────────────
        $this->assignTerms($view, $results, $searchParams, $hotelId);

        // ── Hotel URL ────────────────────────────────────────────────
        $view->assign('hotel_url', !empty($productId)
            ? fn_url("products.view?product_id={$productId}")
            : ''
        );

        // ── Debug ────────────────────────────────────────────────────
        if (!empty($debugLog)) {
            $view->assign('novoton_debug', $debugLog);
        }

        // ── Page meta / SEO ──────────────────────────────────────────
        $this->assignMeta($view);
    }

    /**
     * Assign safe defaults so the template renders without secondary errors.
     * Used on early-return (no check-in) and in the error boundary.
     */
    public function assignDefaults(?string $warningLangKey = null): void
    {
        $view      = Tygh::$app['view'];
        $pageTitle = __('novoton_holidays.search_results') ?: 'Search Results';

        $view->assign('novoton_results', []);
        $view->assign('novoton_params', [
            'check_in' => '', 'check_out' => '', 'nights' => 7,
            'adults' => 2, 'children' => [], 'children_count' => 0,
            'children_ages' => '', 'children_ages_str' => '',
            'children_ages_array' => [], 'num_rooms' => 1,
            'rooms_data' => [], 'rooms_data_json' => '[]',
            'flex_days' => 0,
            'meal_plan' => __('novoton_holidays.all_boards') ?: 'All Boards',
            'hotel_id' => '', 'product_id' => 0,
        ]);
        $view->assign('alternative_results', []);
        $view->assign('alternative_check_in', '');
        $view->assign('alternative_check_out', '');
        $view->assign('no_availability_message', true);
        $view->assign('hotel_name', '');
        $view->assign('hotel_city', '');
        $view->assign('hotel_country', '');
        $view->assign('hotel_region', '');
        $view->assign('hotel_package_name', '');
        $view->assign('hotel_url', '');
        $view->assign('terms_of_payment', '');
        $view->assign('terms_of_cancellation', '');
        $view->assign('early_booking_details', '');
        $view->assign('page_title', $pageTitle);

        $this->assignMeta($view);

        if ($warningLangKey) {
            fn_set_notification('W', __('warning'),
                __($warningLangKey) ?: 'Please fill in required search fields'
            );
        }
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    private function assignCurrency($view): void
    {
        $currency    = defined('CART_SECONDARY_CURRENCY') ? CART_SECONDARY_CURRENCY : 'EUR';
        $currencies  = Registry::get('currencies');
        $coefficient = (float) ($currencies[$currency]['coefficient'] ?? 1.0);
        $symbol      = $currencies[$currency]['symbol'] ?? $currency;

        $view->assign('novoton_display_currency', $currency);
        $view->assign('novoton_display_coefficient', $coefficient);
        $view->assign('novoton_display_symbol', $symbol);
        $view->assign('novoton_round_prices', ConfigProvider::isRoundPrices());
    }

    private function assignHotelDisplay($view, string $hotelId, int $productId): void
    {
        $hotelName    = '';
        $hotelCity    = '';
        $hotelRegion  = '';
        $hotelCountry = '';

        if (!empty($hotelId)) {
            $hotelRepo = Container::getInstance()->hotelRepository();
            $hotelInfo = $hotelRepo->findBasicById($hotelId);

            if ($hotelInfo) {
                $hotelName    = $hotelInfo['hotel_name'] ?? '';
                $hotelCity    = $hotelInfo['city'] ?? '';
                $hotelRegion  = $hotelInfo['region'] ?? '';
                $hotelCountry = $hotelInfo['country'] ?? '';

                // Fetch packages once, reuse across sub-methods
                $packageRepo = Container::getInstance()->hotelPackageRepository();
                $packages    = $packageRepo->findByHotelId($hotelId);

                $this->assignPackages($view, $packages);
                $this->assignActiveEarlyBooking($view, $packages);
                $this->assignSeasonPeriod($view, $packages);
            } else {
                $view->assign('hotel_package_name', '');
            }

            // Room-level facilities
            $lang_code       = CART_LANGUAGE;
            $roomFacilities  = fn_novoton_holidays_get_hotel_facilities_by_type($hotelId, 'room', $lang_code);
            $view->assign('novoton_room_facilities', $roomFacilities);
        }

        // Fallback: product name
        if (empty($hotelName) && !empty($productId)) {
            $hotelName = (string) db_get_field(
                "SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
                $productId, CART_LANGUAGE
            );
        }
        if (empty($hotelName)) {
            $hotelName = !empty($hotelId) ? 'Hotel #' . $hotelId : '';
        }

        $view->assign('hotel_name', $hotelName);
        $view->assign('hotel_city', $hotelCity);
        $view->assign('hotel_region', $hotelRegion);
        $view->assign('hotel_country', $hotelCountry);
    }

    private function assignPackages($view, array $packages): void
    {
        if (empty($packages)) {
            $view->assign('hotel_package_name', '');
            $view->assign('hotel_all_packages', []);
            return;
        }

        // Use first non-bracketed package name, or just the first
        $packageName = '';
        foreach ($packages as $pkg) {
            $pname = $pkg['package_name'] ?? '';
            if (!empty($pname) && substr($pname, -1) !== ']') {
                $packageName = $pname;
                break;
            }
        }
        if (empty($packageName) && !empty($packages[0])) {
            $packageName = $packages[0]['package_name'] ?? '';
        }

        $view->assign('hotel_package_name', $packageName);
        $view->assign('hotel_all_packages', $packages);
    }

    private function assignActiveEarlyBooking($view, array $packages): void
    {
        $currentDate = date('Y-m-d');
        $activeEb    = null;

        foreach ($packages as $pkg) {
            if (($pkg['has_early_booking'] ?? 'N') !== 'Y' || empty($pkg['priceinfo_data'])) {
                continue;
            }

            $priceinfo = json_decode($pkg['priceinfo_data'], true);
            if (empty($priceinfo['early_booking'])) {
                continue;
            }

            $ebData = $priceinfo['early_booking'];
            if (isset($ebData['Reduction'])) {
                $ebData = [$ebData];
            }

            foreach ($ebData as $eb) {
                $bookFrom = $eb['BookFrom'] ?? '';
                $bookTo   = $eb['BookTo'] ?? '';
                if ($bookFrom <= $currentDate && $bookTo >= $currentDate) {
                    $activeEb = [
                        'reduction'       => $eb['Reduction'] ?? 0,
                        'booking_from'    => $bookFrom,
                        'booking_to'      => $bookTo,
                        'stay_from'       => $eb['StayFrom'] ?? '',
                        'stay_to'         => $eb['StayTo'] ?? '',
                        'payment_date'    => $eb['PaymentDate'] ?? '',
                        'payment_percent' => $eb['PaymentPercent'] ?? 0,
                        'room_types'      => $eb['RoomTypes'] ?? 'all',
                        'min_stay'        => $eb['MinStay'] ?? 0,
                    ];
                    break 2;
                }
            }
        }

        if ($activeEb) {
            $view->assign('active_early_booking', $activeEb);
        }
    }

    private function assignSeasonPeriod($view, array $packages): void
    {
        $seasonFrom  = '';
        $seasonTo    = '';

        foreach ($packages as $pkg) {
            if (empty($pkg['priceinfo_data'])) {
                continue;
            }
            $pi = json_decode($pkg['priceinfo_data'], true);
            if (empty($pi['seasons']['season'])) {
                continue;
            }

            $seasons = $pi['seasons']['season'];
            if (isset($seasons['IdSeason']) || isset($seasons['DateFrom'])) {
                $seasons = [$seasons];
            }

            if (!empty($seasons)) {
                $first      = reset($seasons);
                $last       = end($seasons);
                $seasonFrom = $first['DateFrom'] ?? $first['FromDate'] ?? '';
                $seasonTo   = $last['DateTo'] ?? $last['ToDate'] ?? '';
            }
            break;
        }

        $view->assign('hotel_season_from', $seasonFrom);
        $view->assign('hotel_season_to', $seasonTo);
    }

    private function assignTerms($view, array $results, array $searchParams, string $hotelId): void
    {
        $termsPaymentRaw      = '';
        $termsCancellationRaw = '';

        foreach ($results as $r) {
            if (!empty($r['terms_of_payment']) && empty($termsPaymentRaw)) {
                $termsPaymentRaw = $r['terms_of_payment'];
            }
            if (!empty($r['terms_of_cancellation']) && empty($termsCancellationRaw)) {
                $termsCancellationRaw = $r['terms_of_cancellation'];
            }
        }

        $checkInForTerms = $searchParams['check_in'] ?? '';

        // Ensure func.php is loaded
        if (!function_exists('fn_novoton_holidays_parse_payment_terms')) {
            require_once(Registry::get('config.dir.addons') . 'novoton_holidays/func.php');
        }

        $view->assign('terms_of_payment', fn_novoton_holidays_format_payment_terms($termsPaymentRaw));
        $view->assign('terms_of_cancellation', fn_novoton_holidays_format_cancellation_terms($termsCancellationRaw, $checkInForTerms));
        $view->assign('terms_of_payment_raw', $termsPaymentRaw);
        $view->assign('terms_of_cancellation_raw', $termsCancellationRaw);
        $view->assign('parsed_payment_terms', fn_novoton_holidays_parse_payment_terms($termsPaymentRaw));
        $view->assign('parsed_cancellation_terms', fn_novoton_holidays_parse_cancellation_terms($termsCancellationRaw, $checkInForTerms));

        // Early-booking tooltip details
        $ebDetails = '';
        if (!empty($hotelId)) {
            $packageRepo = Container::getInstance()->hotelPackageRepository();
            $ebPackage   = $packageRepo->findEarlyBookingPackage($hotelId);

            if (!empty($ebPackage['priceinfo_data'])) {
                $priceinfo = json_decode($ebPackage['priceinfo_data'], true);
                if (!empty($priceinfo['early_booking'])) {
                    $ebData = $priceinfo['early_booking'];
                    if (isset($ebData['Reduction'])) {
                        $ebData = [$ebData];
                    }

                    $lines = [];
                    foreach ($ebData as $eb) {
                        $reduction   = $eb['Reduction'] ?? 0;
                        $bookTo      = $eb['BookTo'] ?? '';
                        $stayFrom    = $eb['StayFrom'] ?? '';
                        $stayTo      = $eb['StayTo'] ?? '';
                        $paymentDate = !empty($bookTo)
                            ? date('d.m.Y', strtotime($bookTo . ' +5 days'))
                            : 'N/A';
                        $lines[] = "-{$reduction}% Early Booking discount till {$bookTo}"
                            . " -- PAYMENT till {$paymentDate}"
                            . " -- STAY in {$stayFrom} - {$stayTo}";
                    }
                    $ebDetails = implode("\n", $lines);
                }
            }
        }
        $view->assign('early_booking_details', $ebDetails);
    }

    private function assignMeta($view): void
    {
        $pageTitle = __('novoton_holidays.search_results') ?: 'Search Results';

        $view->assign('page_title', $pageTitle);
        Registry::set('navigation.dynamic.page_title', $pageTitle);
        Registry::set('navigation.dynamic.meta_description', '');
        Registry::set('navigation.dynamic.meta_keywords', '');
        Registry::set('runtime.page_title', $pageTitle);
        $view->assign('meta_description', '');
        $view->assign('meta_keywords', '');
        $view->assign('canonical_url', '');
        $view->assign('og_image', '');
        $view->assign('og_title', $pageTitle);
        $view->assign('og_description', '');
        $view->assign('og_type', 'website');
        $view->assign('twitter_card', '');
        $view->assign('twitter_title', $pageTitle);
        $view->assign('twitter_description', '');

        fn_add_breadcrumb($pageTitle);
    }
}
