<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;

class OffersUpdateCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['offers_update'];
    }

    public static function getDescription(): string
    {
        return 'Check offers_update API for new/changed hotels and add as products';
    }

    public function execute(): array
    {
        $this->output("Checking for new/updated offers (offers_update API)...");
        $this->output("");

        $country = strtoupper($this->getParam('country', 'BULGARIA'));

        $syncRepo = new SyncLogRepository();
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

        $response = $this->api->getOffersUpdate($last_import, $country);

        if (!$response || !isset($response->Offer)) {
            $this->output("No new offers found.");
            return ['success' => true, 'stats' => ['new_hotels' => 0, 'added_to_cart' => 0]];
        }

        $offers = is_array($response->Offer) ? $response->Offer : [$response->Offer];
        $this->output("Found " . count($offers) . " offers to check.");
        $this->output("");

        $hotelRepo = new HotelRepository();
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
                $hotel_info = $this->api->getHotelInfo($hotel_id);
                if ($hotel_info) {
                    $hotel_data = [
                        'hotel_id' => $hotel_id,
                        'hotel_name' => (string)($hotel_info->Hotel ?? $hotel_name),
                        'city' => (string)($hotel_info->City ?? ''),
                        'region' => (string)($hotel_info->Region ?? ''),
                        'country' => (string)($hotel_info->Country ?? $country),
                        'hotel_type' => (string)($hotel_info->HotelType ?? $hotel_info->Stars ?? ''),
                        'has_prices' => 'N',
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
            if (($existing['has_prices'] ?? '') != 'Y') {
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

            $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
            $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            $page_title = fn_novoton_holidays_build_hotel_title(
                $existing['hotel_name'] ?? $hotel_name,
                $existing['city'] ?? '',
                $existing['country'] ?? $country,
                $current_year
            );

            $description = '';
            try {
                $desc = $this->api->getHotelDescription($hotel_id, 'UK');
                if ($desc && isset($desc->Description)) {
                    $description = (string)$desc->Description;
                }
            } catch (\Exception $e) {
                fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
            }

            $product_data = [
                'product' => $existing['hotel_name'] ?? $hotel_name,
                'product_code' => $product_code,
                'price' => 0,
                'status' => 'D',
                'company_id' => Registry::get('runtime.company_id') ?: 1,
                'main_category' => $category_id,
                'category_ids' => [$category_id],
                'full_description' => $description,
                'page_title' => $page_title,
                'meta_description' => $page_title,
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

            usleep(100000);
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
            $images = $this->api->getHotelImages($hotelId);
            if ($images && isset($images->url)) {
                $count = 0;
                foreach ($images->url as $url) {
                    $image_url = $baseUrl . str_replace(' ', '%20', (string)$url);
                    fn_novoton_holidays_add_product_image($productId, $image_url, $count == 0);
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
}
