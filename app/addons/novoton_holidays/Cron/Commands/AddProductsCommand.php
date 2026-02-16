<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;

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
        $country = strtoupper($this->getParam('country', 'BULGARIA'));
        $limit = (int)$this->getParam('limit', 0);

        // Get excluded resorts
        $exclude_resorts = $this->getExcludedResorts();

        $this->output("Adding hotels as products...");
        $this->output("Country: {$country}");
        $this->output("Limit: " . ($limit > 0 ? $limit : "No limit"));
        if (!empty($exclude_resorts)) {
            $this->output("Excluding resorts (" . count($exclude_resorts) . "): " . implode(', ', $exclude_resorts));
        }
        $this->output("");

        // Build query
        $query = "SELECT * FROM ?:novoton_hotels
                  WHERE has_prices = 'Y'
                  AND country = ?s
                  AND (product_id IS NULL OR product_id = 0)";
        $params = [$country];

        if (!empty($exclude_resorts)) {
            $query .= " AND (city NOT IN (?a) OR city IS NULL)";
            $params[] = $exclude_resorts;
        }

        $query .= " ORDER BY hotel_name";
        if ($limit > 0) {
            $query .= " LIMIT ?i";
            $params[] = $limit;
        }

        $hotels = db_get_array($query, ...$params);
        $this->output("Found " . count($hotels) . " hotels to add.");
        $this->output("");

        if (empty($hotels)) {
            return ['success' => true, 'stats' => ['added' => 0]];
        }

        $category_path = "{$country}///Litoral {$country}";
        $category_id = fn_novoton_get_or_create_category($category_path);
        $current_year = date('Y');
        $image_base_url = 'https://booking.allinclusive.bg';
        $added = 0;

        foreach ($hotels as $hotel) {
            $hotel_id = $hotel['hotel_id'];
            $product_code = 'NVT' . $hotel_id;

            $this->output("[{$hotel_id}] {$hotel['hotel_name']} ({$hotel['city']}) ... ", false);

            $existing = db_get_field("SELECT product_id FROM ?:products WHERE product_code = ?s", $product_code);
            if ($existing) {
                db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $existing, $hotel_id);
                $this->output("LINKED");
                continue;
            }

            $page_title = fn_novoton_build_hotel_title($hotel['hotel_name'], $hotel['city'], $hotel['country'], $current_year);

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
                db_query("UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s", $product_id, $hotel_id);

                try {
                    $images = $this->api->getHotelImages($hotel_id);
                    if ($images && isset($images->url)) {
                        $count = 0;
                        foreach ($images->url as $url) {
                            $image_url = $image_base_url . str_replace(' ', '%20', (string)$url);
                            fn_novoton_add_product_image($product_id, $image_url, $count == 0);
                            if (++$count >= 10) break;
                        }
                    }
                } catch (\Exception $e) {
                    fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to import images for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                }

                try {
                    fn_novoton_sync_hotel_facilities($hotel_id);
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

        $this->output("");
        $this->output("Added: {$added}");

        $stats = ['added' => $added, 'total' => count($hotels)];
        $this->sendReport('add_products', [
            'added' => $added, 'skipped' => count($hotels) - $added, 'duration' => $this->getDuration() . 's'
        ], $country);

        return ['success' => true, 'stats' => $stats];
    }

    private function getExcludedResorts(): array
    {
        if (!empty($_REQUEST['exclude_resorts'])) {
            $val = $_REQUEST['exclude_resorts'];
            if (is_array($val)) return array_filter($val);
            return array_filter(array_map('trim', explode(',', $val)));
        }

        $setting = Registry::get('addons.novoton_holidays.excluded_resorts') ?? '';
        if (empty($setting)) return [];

        $decoded = json_decode($setting, true);
        if (is_array($decoded)) return array_filter($decoded);

        return array_filter(array_map('trim', explode(',', $setting)));
    }
}
