<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;

class AddProductsCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['add_hotels_as_products'];
    }

    public static function getDescription(): string
    {
        return 'Add hotels with prices as CS-Cart products';
    }

    public function execute(): array
    {
        $limit = (int) $this->getParam('limit', 0);
        $exclude_resorts = $this->getExcludedResorts();

        // Determine countries: explicit &country= param, or all selected in addon settings
        $countryParam = $this->getParam('country', '');
        if (!empty($countryParam)) {
            $countries = [strtoupper($countryParam)];
        } else {
            $countries = ConfigProvider::getSelectedCountries();
        }

        $this->output("Adding hotels as products...");
        $this->output("Countries: " . implode(', ', $countries));
        $this->output("Limit per country: " . ($limit > 0 ? $limit : "No limit"));
        if (!empty($exclude_resorts)) {
            $this->output("Excluding resorts (" . count($exclude_resorts) . "): " . implode(', ', $exclude_resorts));
        }
        $this->output("");

        $hotelRepo = new HotelRepository();
        $current_year = date('Y');
        $image_base_url = \Tygh\Addons\NovotonHolidays\Constants::IMAGE_BASE_URL;
        $grand_total = 0;
        $grand_added = 0;

        foreach ($countries as $country) {
            $this->output("=== {$country} ===");

            $hotels = $hotelRepo->findUnlinkedWithPrices($country, $exclude_resorts, $limit);
            $this->output("Found " . count($hotels) . " hotels to add.");

            if (empty($hotels)) {
                $this->output("");
                continue;
            }

            $category_path = "{$country}///Litoral {$country}";
            $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            $added = 0;

            foreach ($hotels as $hotel) {
                $hotel_id = $hotel['hotel_id'];
                $product_code = 'NVT' . $hotel_id;

                $this->output("[{$hotel_id}] {$hotel['hotel_name']} ({$hotel['city']}) ... ", false);

                // Check if CS-Cart product already exists (core products table)
                $existing = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
                if ($existing) {
                    $hotelRepo->linkToProduct($hotel_id, (int) $existing);
                    $this->output("LINKED");
                    continue;
                }

                $page_title = fn_novoton_holidays_build_hotel_title($hotel['hotel_name'], $hotel['city'], $hotel['country'], $current_year);

                $description = '';
                try {
                    $desc = $this->api->getHotelDescription($hotel_id, 'UK');
                    if ($desc && isset($desc->Description)) {
                        $description = (string) $desc->Description;
                    }
                } catch (\Exception $e) {
                    fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                }

                $product_data = [
                    'product' => $hotel['hotel_name'],
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

                    try {
                        $images = $this->api->getHotelImages($hotel_id);
                        if ($images && isset($images->url)) {
                            $count = 0;
                            foreach ($images->url as $url) {
                                $image_url = $image_base_url . str_replace(' ', '%20', (string) $url);
                                fn_novoton_holidays_add_product_image($product_id, $image_url, $count == 0);
                                if (++$count >= 10) break;
                            }
                        }
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to import images for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    try {
                        fn_novoton_holidays_sync_hotel_facilities($hotel_id);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to sync facilities for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    $added++;
                    $this->output("ADDED (ID: {$product_id})");
                } else {
                    $this->output("FAILED");
                }

                usleep(100000);
            }

            $this->output("{$country}: Added {$added} of " . count($hotels));
            $this->output("");
            $grand_total += count($hotels);
            $grand_added += $added;
        }

        $this->output("=== TOTAL ===");
        $this->output("Added: {$grand_added} of {$grand_total}");

        $stats = ['added' => $grand_added, 'total' => $grand_total, 'countries' => count($countries)];
        $this->sendReport('add_products', [
            'added' => $grand_added, 'total' => $grand_total, 'countries' => implode(', ', $countries), 'duration' => $this->getDuration() . 's'
        ]);

        return ['success' => true, 'stats' => $stats];
    }

    private function getExcludedResorts(): array
    {
        $paramVal = $this->getParam('exclude_resorts');
        if (!empty($paramVal)) {
            if (is_array($paramVal)) return array_filter($paramVal);
            return array_filter(array_map('trim', explode(',', $paramVal)));
        }

        return ConfigProvider::getExcludedResorts();
    }
}
