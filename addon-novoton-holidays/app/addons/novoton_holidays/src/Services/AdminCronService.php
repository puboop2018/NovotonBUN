<?php
declare(strict_types=1);
/**
 * Admin Cron Service — testable service layer for admin panel cron operations.
 *
 * Replaces the procedural fn_novoton_holidays_admin_* functions that lived
 * inside the controller and used raw echo for progress reporting.
 * All output is routed through OutputWriterTrait so callers can capture it
 * via setOutputCallback() instead of ob_start()/ob_get_clean().
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\NovotonHolidays\Helpers\OutputWriterTrait;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\CacheRepository;

class AdminCronService
{
    use OutputWriterTrait;

    private readonly NovotonApi $api;
    private readonly Container $container;

    public function __construct(NovotonApi $api)
    {
        $this->api = $api;
        $this->container = Container::getInstance();
    }

    /**
     * Sync hotel list from API for all configured countries.
     *
     * @return array{success: bool, message: string}
     */
    public function syncHotels(): array
    {
        $countries = fn_novoton_holidays_parse_countries();
        $hotelRepo = $this->container->hotelRepository();

        $total = 0;
        $synced = 0;

        foreach ($countries as $country) {
            $this->output("Fetching {$country}... ", false);
            $hotels = $this->api->getHotelList($country);

            if (!empty($hotels)) {
                $count = count($hotels);
                $total += $count;
                $this->output("{$count} hotels");

                foreach ($hotels as $hotel) {
                    $hotel_id = (string) ($hotel->IdHotel ?? '');
                    if (empty($hotel_id)) {
                        continue;
                    }

                    $data = [
                        'hotel_id'   => $hotel_id,
                        'hotel_name' => (string) ($hotel->Hotel ?? ''),
                        'city'       => (string) ($hotel->City ?? ''),
                        'country'    => $country,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];

                    $hotelRepo->upsert($data);
                    $synced++;
                }
            } else {
                $this->output("0 hotels");
            }
        }

        return ['success' => true, 'message' => "Total: {$total}, Synced: {$synced}"];
    }

    /**
     * Check room prices for hotels that haven't been checked recently.
     *
     * @return array{success: bool, message: string}
     */
    public function checkPrices(): array
    {
        $hotelRepo = $this->container->hotelRepository();
        $hotels = $hotelRepo->findNeedingPriceCheck();

        $checked = 0;
        $with_prices = 0;

        foreach ($hotels as $hotel) {
            $check_in  = date(TravelConstants::DATE_FORMAT, strtotime('+' . Constants::PRICE_CHECK_OFFSET_DAYS . ' days'));
            $check_out = date(TravelConstants::DATE_FORMAT, strtotime('+' . (Constants::PRICE_CHECK_OFFSET_DAYS + TravelConstants::DEFAULT_NIGHTS) . ' days'));

            $response = $this->api->getRoomPrice([
                'hotel_id'  => $hotel['hotel_id'],
                'check_in'  => $check_in,
                'check_out' => $check_out,
                'adults'    => TravelConstants::DEFAULT_ADULTS,
                'children'  => TravelConstants::DEFAULT_CHILDREN,
                'rooms'     => TravelConstants::DEFAULT_ROOMS,
            ]);
            $has_room_price = ($response && isset($response->hotel)) ? 'Y' : 'N';

            $hotelRepo->update($hotel['hotel_id'], [
                'has_room_price'   => $has_room_price,
                'last_price_check' => date('Y-m-d H:i:s'),
            ]);

            $checked++;
            if ($has_room_price === 'Y') {
                $with_prices++;
            }

            $this->output("[{$hotel['hotel_id']}] {$hotel['hotel_name']}: " . ($has_room_price === 'Y' ? 'HAS PRICES' : 'no prices'));
            usleep(Constants::API_DELAY_NORMAL);
        }

        return ['success' => true, 'message' => "Checked: {$checked}, With prices: {$with_prices}"];
    }

    /**
     * Sync facilities list from API.
     *
     * @return array{success: bool, message: string}
     */
    public function syncFacilities(): array
    {
        $response = $this->api->listFacilities();

        if (!$response || !isset($response->Facility)) {
            return ['success' => false, 'message' => 'No facilities returned from API'];
        }

        $facilityRepo = $this->container->facilityRepository();
        $facilities = is_array($response->Facility) ? $response->Facility : [$response->Facility];
        $count = 0;

        foreach ($facilities as $f) {
            $facility_id   = (int) ($f->IdFacility ?? 0);
            $facility_name = (string) ($f->Facility ?? '');

            if (empty($facility_id)) {
                continue;
            }

            $facilityRepo->save($facility_id, $facility_name);
            $count++;
        }

        return ['success' => true, 'message' => "Synced {$count} facilities"];
    }

    /**
     * Create CS-Cart products for hotels that don't have one yet.
     *
     * @param string[] $countries Country codes
     * @param int      $limit    Max hotels per country
     * @return array{success: bool, message: string}
     */
    public function addProducts(array $countries, int $limit): array
    {
        $hotelRepo   = $this->container->hotelRepository();
        $grand_added = 0;
        $grand_total = 0;

        foreach ($countries as $country) {
            $this->output("=== {$country} ===");

            $hotels = $hotelRepo->findUnlinkedWithPrices($country, ConfigProvider::getHiddenResorts(), $limit);

            if (empty($hotels)) {
                $this->output("No hotels to add.\n");
                continue;
            }

            $category_id = ConfigProvider::getCategoryForCountry($country);
            if (!$category_id) {
                $category_id = fn_novoton_holidays_get_or_create_category(str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE));
            }
            $added = 0;

            foreach ($hotels as $hotel) {
                $hotel_id     = $hotel['hotel_id'];
                $product_code = 'NVT' . $hotel_id;

                $this->output("[{$hotel_id}] {$hotel['hotel_name']} ... ", false);

                // Check if CS-Cart product already exists with this code
                $existing = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
                if ($existing) {
                    $hotelRepo->linkToProduct($hotel_id, (int) $existing);
                    $this->output("LINKED");
                    continue;
                }

                $product_data = [
                    'product'       => $hotel['hotel_name'],
                    'product_code'  => $product_code,
                    'price'         => 0,
                    'amount'        => ConfigProvider::getDefaultProductQuantity(),
                    'status'        => 'D',
                    'company_id'    => Registry::get('runtime.company_id') ?: 1,
                    'main_category' => $category_id,
                    'category_ids'  => [$category_id],
                ];

                $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);

                if ($product_id) {
                    $hotelRepo->linkToProduct($hotel_id, $product_id);
                    $added++;
                    $this->output("ADDED (ID: {$product_id})");
                } else {
                    $this->output("FAILED");
                }

                usleep(Constants::API_DELAY_LIGHT);
            }

            $this->output("{$country}: Added {$added} of " . count($hotels) . "\n");
            $grand_added += $added;
            $grand_total += count($hotels);
        }

        return [
            'success' => true,
            'message' => "Added: {$grand_added} products across " . count($countries)
                         . " countries (total candidates: {$grand_total})",
        ];
    }

    /**
     * Check for new offers since last sync.
     *
     * @return array{success: bool, message: string}
     */
    public function checkOffers(string $country): array
    {
        $syncLogRepo = $this->container->syncLogRepository();
        $last_check  = $syncLogRepo->getLastSyncDate('offers_update')
                       ?: $syncLogRepo->getLastSyncDate('product_import');
        if (empty($last_check)) {
            $last_check = date('Y-m-d\TH:i:s', strtotime('-7 days'));
        }

        $this->output("Checking offers since: {$last_check}");

        $response = $this->api->getOffersUpdate($last_check, $country);

        if (!$response || !isset($response->Offer)) {
            return ['success' => true, 'message' => 'No new offers found'];
        }

        $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
        $count  = count($offers);

        return ['success' => true, 'message' => "Found {$count} offers"];
    }

    /**
     * Check pending alternative requests or bookings.
     *
     * @param string $type "requests" or "bookings"
     * @return array{success: bool, message: string}
     */
    public function checkAlternatives(string $type): array
    {
        if ($type === 'requests') {
            $altRequestRepo = $this->container->alternativeRequestRepository();
            $items = $altRequestRepo->findPendingOlderThan(0, 50);
        } else {
            $bookingRepo = $this->container->bookingRepository();
            $items = $bookingRepo->findByNovotonStatus(
                Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING,
                [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED],
                50
            );
        }

        $checked = count($items);
        $this->output("Checking {$checked} {$type}...");

        return ['success' => true, 'message' => "Checked: {$checked}"];
    }

    /**
     * Notify users about found alternatives.
     *
     * @return array{success: bool, message: string}
     */
    public function notifyAlternatives(): array
    {
        $altRequestRepo = $this->container->alternativeRequestRepository();
        $requests = $altRequestRepo->findUnnotified(50);
        $requests = fn_novoton_holidays_decrypt_requests_pii($requests);

        $notified = 0;
        foreach ($requests as $request) {
            $altRequestRepo->markNotified($request['request_id']);
            $notified++;
        }

        return ['success' => true, 'message' => "Notified: {$notified}"];
    }

    /**
     * Clean up orphan bookings, old sync logs, and expired cache.
     *
     * @return array{success: bool, message: string}
     */
    public function cleanup(): array
    {
        $bookingRepo = $this->container->bookingRepository();
        $syncLogRepo = $this->container->syncLogRepository();

        $orphans = $bookingRepo->deleteOrphans(48);
        $this->output("Orphan bookings deleted: {$orphans}");

        $logs_deleted = $syncLogRepo->trimToLatest(100);
        $this->output("Sync logs deleted: {$logs_deleted}");

        $cache = (new CacheRepository())->deleteExpired();
        $this->output("Cache entries deleted: {$cache}");

        return ['success' => true, 'message' => 'Cleanup complete'];
    }

    /**
     * Expire old alternative requests.
     *
     * @param int $days Requests older than this many days are expired
     * @return array{success: bool, message: string}
     */
    public function expireRequests(int $days): array
    {
        $altRequestRepo = $this->container->alternativeRequestRepository();
        $expired = $altRequestRepo->expireOlderThan($days);

        return ['success' => true, 'message' => "Expired: {$expired} requests"];
    }
}
