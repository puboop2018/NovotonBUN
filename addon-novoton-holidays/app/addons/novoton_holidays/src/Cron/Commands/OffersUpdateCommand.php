<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

class OffersUpdateCommand extends AbstractCronCommand
{
    /**
     * @return array<string, mixed>
     */
    public static function getModes(): array
    {
        return ['offers_update'];
    }

    public static function getDescription(): string
    {
        return 'Check offers_update API for new/changed hotels and add as products';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output("Checking for new/updated offers (offers_update API)...");
        $this->output("");

        $country = strtoupper($this->getParam('country', Constants::DEFAULT_COUNTRY));

        $syncRepo = Container::getInstance()->syncLogRepository();
        $last_import = $syncRepo->getLastSyncDate('product_import');

        if (empty($last_import)) {
            $this->output("ERROR: No previous product import found!");
            $this->output("Run 'Add Hotels as Products' first to establish the baseline timestamp.");
            return ['success' => false, 'error' => 'No baseline import'];
        }

        $this->output("Country: {$country}");
        $this->output("Last product import: {$last_import}");
        $this->output("Checking offers added/modified after this time...");
        $this->output("");

        $response = $this->api->destinations()->getOffersUpdate($last_import, $country);

        if (!$response || !isset($response->Offer)) {
            $this->output("No new offers found.");
            return ['success' => true, 'stats' => ['new_hotels' => 0, 'added_to_cart' => 0]];
        }

        $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
        $this->output("Found " . count($offers) . " offers to check.");
        $this->output("");

        $hotelRepo = Container::getInstance()->hotelRepository();
        $new_hotels = 0;
        $added_to_cart = 0;
        $current_year = date('Y');
        $image_base_url = \Tygh\Addons\NovotonHolidays\Constants::IMAGE_BASE_URL;

        foreach ($offers as $offer) {
            $hotel_id = (string)($offer->IdHotel ?? '');
            $hotel_name = (string)($offer->PackageName ?? $offer->Hotel ?? '');
            if (empty($hotel_id)) continue;

            $this->output("[{$hotel_id}] {$hotel_name} ... ", false);

            $existing = $hotelRepo->findById($hotel_id);

            if (!$existing) {
                $this->output("NEW HOTEL - ", false);
                $hotel_info = $this->api->hotels()->getHotelInfo($hotel_id);
                if ($hotel_info) {
                    $hotel_data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => (string)($hotel_info->Hotel ?? $hotel_name),
                        'city' => (string)($hotel_info->City ?? ''),
                        'region' => (string)($hotel_info->Region ?? ''),
                        'country' => (string)($hotel_info->Country ?? $country),
                        'hotel_type' => (string)($hotel_info->HotelType ?? $hotel_info->Stars ?? ''),
                        'has_room_price' => 'N',
                        'hotel_list_synced_at' => date('Y-m-d H:i:s')
                    ];
                    $hotelRepo->upsert($hotel_data);
                    $new_hotels++;
                    $existing = $hotel_data;
                    $this->output("synced - ", false);
                }
            }

            if (!$existing) {
                $this->output("skip");
                continue;
            }

            // Check if should add to CS-Cart
            if (($existing['has_room_price'] ?? '') != 'Y') {
                $this->output("no prices");
                continue;
            }

            $product_code = 'NVT' . $hotel_id;
            // Check CS-Cart core products table
            $existing_product = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
            if ($existing_product) {
                $hotelRepo->linkToProduct($hotel_id, (int)$existing_product);
                $this->output("linked");
                continue;
            }

            $category_id = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getCategoryForCountry($country);
            if (!$category_id) {
                $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
                $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            }
            $raw_name = $existing['hotel_name'] ?? $hotel_name;
            $display_name = fn_novoton_holidays_format_hotel_display_name($raw_name);

            $description = '';
            try {
                $desc = $this->api->hotels()->getHotelDescription($hotel_id, 'UK');
                if ($desc && isset($desc->Description)) {
                    $description = (string)$desc->Description;
                }
            } catch (\Exception $e) {
                fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
            }

            // Build placeholder map for SEO templates
            $hotel_data_for_seo = array_merge($existing, [
                'hotel_name' => $raw_name,
                'country'    => $existing['country'] ?? $country,
            ]);
            $placeholders = $this->buildPlaceholders($hotel_data_for_seo, $display_name, $description);

            // Resolve full description
            $descTemplate = ConfigProvider::getSeoFullDescription();
            $full_description = $descTemplate !== ''
                ? fn_travel_core_render_seo_template($descTemplate, $placeholders)
                : $description;

            $product_data = [
                'product'          => fn_travel_core_render_seo_template(ConfigProvider::getSeoProductName(), $placeholders),
                'product_code'     => $product_code,
                'price'            => 0,
                'amount'           => ConfigProvider::getDefaultProductQuantity(),
                'status'           => 'D',
                'company_id'       => Registry::get('runtime.company_id') ?: 1,
                'main_category'    => $category_id,
                'category_ids'     => [$category_id],
                'full_description' => $full_description,
                'page_title'       => fn_travel_core_render_seo_template(ConfigProvider::getSeoPageTitle(), $placeholders),
                'meta_description' => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaDescription(), $placeholders),
                'meta_keywords'    => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaKeywords(), $placeholders),
                'seo_name'         => fn_travel_core_render_seo_slug(ConfigProvider::getSeoNameSlug(), $placeholders),
            ];

            $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);
            if ($product_id) {
                $hotelRepo->linkToProduct($hotel_id, $product_id);
                $this->attachImages($hotel_id, $product_id, $image_base_url);
                $added_to_cart++;
                $this->output("ADDED (ID: {$product_id})");
            } else {
                $this->output("FAILED");
            }

            usleep(Constants::API_DELAY_NORMAL);
        }

        $this->output("");
        $this->output("New hotels synced: {$new_hotels}");
        $this->output("Added to CS-Cart: {$added_to_cart}");

        $this->logToSyncTable('offers_update', $added_to_cart);

        // Save sync timestamp
        $syncRepo->create('product_import', [
            'updated' => $added_to_cart,
            'status'  => 'completed',
        ]);

        $stats = ['new_hotels' => $new_hotels, 'added_to_cart' => $added_to_cart];
        $this->sendReport('offers_update', [
            'added' => $added_to_cart, 'updated' => $new_hotels, 'duration' => $this->getDuration() . 's'
        ], $country);

        return ['success' => true, 'stats' => $stats];
    }

    private function attachImages(string $hotelId, int $productId, string $baseUrl): void
    {
        try {
            $images = $this->api->hotels()->getHotelImages($hotelId);
            if ($images && isset($images->url)) {
                $count = 0;
                foreach ($images->url as $url) {
                    $image_url = $baseUrl . str_replace(' ', '%20', (string)$url);
                    fn_novoton_holidays_add_product_image($productId, $image_url, $count === 0);
                    if (++$count >= 10) break;
                }
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to import images for hotel {$hotelId}", 'error' => $e->getMessage()]);
        }

        try {
            fn_novoton_holidays_sync_hotel_facilities($hotelId);
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to sync facilities for hotel {$hotelId}", 'error' => $e->getMessage()]);
        }
    }

    /**
     * Build the placeholder map for SEO template rendering.
     * @param array<string, mixed> $hotel
     * @return array<string, mixed>
     */
    private function buildPlaceholders(array $hotel, string $displayName, string $description = ''): array
    {
        $facilities = [];
        if (!empty($hotel['hotel_id'])) {
            $facilities = db_get_fields(
                "SELECT f.facility_name_en FROM ?:novoton_hotel_facilities hf
                 JOIN ?:novoton_facilities f ON f.facility_id = hf.facility_id
                 WHERE hf.hotel_id = ?s AND f.facility_name_en != ''
                 LIMIT 5",
                $hotel['hotel_id']
            ) ?: [];
        }

        // Min price from packages table
        $min_price = '';
        if (!empty($hotel['hotel_id'])) {
            $min_price = db_get_field(
                "SELECT MIN(min_price) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND min_price > 0",
                $hotel['hotel_id']
            ) ?: '';
        }

        return [
            'name'          => $displayName,
            'raw_name'      => $hotel['hotel_name'] ?? '',
            'city'          => $hotel['city'] ?? '',
            'country'       => $hotel['country'] ?? '',
            'region'        => $hotel['region'] ?? '',
            'star_rating'   => $hotel['star_rating'] ?? '',
            'stars_emoji'   => fn_travel_core_build_star_emoji((int) ($hotel['star_rating'] ?? 0)),
            'hotel_type'    => $hotel['hotel_type'] ?? '',
            'property_type' => $hotel['property_type'] ?? 'hotel',
            'year'          => date('Y'),
            'description'   => $description,
            'facilities'    => $facilities,
            'latitude'      => $hotel['latitude'] ?? '',
            'longitude'     => $hotel['longitude'] ?? '',
            'min_price'     => $min_price,
        ];
    }
}
