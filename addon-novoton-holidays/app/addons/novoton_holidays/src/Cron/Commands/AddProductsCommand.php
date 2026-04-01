<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;

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

        // Ensure feature mapping table is populated (auto-seeds if settings are configured but mappings missing)
        $mappingCheck = fn_novoton_holidays_ensure_feature_mappings();
        if (!empty($mappingCheck['unconfigured'])) {
            $this->output("WARNING: Feature IDs not configured in addon settings for: " . implode(', ', $mappingCheck['unconfigured']));
            $this->output("Go to Addons > Novoton Holidays > Feature Mapping and set the CS-Cart feature IDs.");
        }
        if ($mappingCheck['seeded'] > 0) {
            $this->output("Auto-seeded {$mappingCheck['seeded']} feature mappings.");
        }

        $this->output("Adding hotels as products...");
        $this->output("Countries: " . implode(', ', $countries));
        $this->output("Limit per country: " . ($limit > 0 ? $limit : "No limit"));
        if (!empty($exclude_resorts)) {
            $this->output("Excluding resorts (" . count($exclude_resorts) . "): " . implode(', ', $exclude_resorts));
        }
        $this->output("");

        $hotelRepo = Container::getInstance()->hotelRepository();
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

            $category_id = ConfigProvider::getCategoryForCountry($country);
            if (!$category_id) {
                $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
                $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            }

            if (!$category_id) {
                $this->output("ERROR: No category mapping for '{$country}' and auto-creation failed. Skipping.");
                $this->output("");
                $grand_total += count($hotels);
                continue;
            }

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

                // Detect property type for this hotel
                $propertyDetector = new PropertyTypeDetector();
                $hotelData = fn_novoton_holidays_get_hotel_data($hotel_id);
                $packageNames = [];
                $roomNames = [];
                if (!empty($hotelData['packages'])) {
                    foreach ($hotelData['packages'] as $pkg) {
                        $packageNames[] = is_array($pkg) ? ($pkg['PackageName'] ?? '') : (string) $pkg;
                    }
                }
                if (!empty($hotelData['rooms'])) {
                    foreach ($hotelData['rooms'] as $rm) {
                        $roomNames[] = is_array($rm) ? ($rm['Type'] ?? $rm['IdRoom'] ?? '') : (string) $rm;
                    }
                }
                $detectedType = $propertyDetector->detect($hotel['hotel_name'], $packageNames, $roomNames);

                // Format hotel display name: Title Case + append property type for short names
                $display_name = fn_novoton_holidays_format_hotel_display_name($hotel['hotel_name'], $detectedType);

                $description = '';
                try {
                    $desc = $this->api->getHotelDescription($hotel_id, 'UK');
                    if ($desc && isset($desc->Description)) {
                        $description = (string) $desc->Description;
                    }
                } catch (\Exception $e) {
                    fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                }

                // Build placeholder map for SEO templates
                $placeholders = $this->buildPlaceholders($hotel, $display_name, $description);

                // Resolve full description: use template if configured, otherwise raw API description
                $descTemplate = ConfigProvider::getSeoFullDescription();
                $full_description = $descTemplate !== ''
                    ? fn_travel_core_render_seo_template($descTemplate, $placeholders)
                    : $description;

                $product_data = [
                    'product'          => fn_travel_core_render_seo_template(ConfigProvider::getSeoProductName(), $placeholders),
                    'product_code'     => $product_code,
                    'price'            => 0,
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

                    // Assign CS-Cart product features via FeatureMapper
                    try {
                        $this->assignProductFeatures($product_id, $hotel_id, $hotel);
                    } catch (\Exception $e) {
                        fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to assign features for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                    }

                    $added++;
                    $this->output("ADDED (ID: {$product_id})");
                } else {
                    $this->output("FAILED (category_id={$category_id}, company_id=" . (Registry::get('runtime.company_id') ?: 1) . ")");
                }

                usleep(Constants::API_DELAY_NORMAL);
            }

            $this->output("{$country}: Added {$added} of " . count($hotels));
            $this->output("");
            $grand_total += count($hotels);
            $grand_added += $added;
        }

        // Clear FeatureMapper cache after batch import to free memory
        \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();

        $this->output("=== TOTAL ===");
        $this->output("Added: {$grand_added} of {$grand_total}");

        $stats = ['added' => $grand_added, 'total' => $grand_total, 'countries' => count($countries)];
        $this->sendReport('add_products', [
            'added' => $grand_added, 'total' => $grand_total, 'countries' => implode(', ', $countries), 'duration' => $this->getDuration() . 's'
        ]);

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Assign all mappable features to a newly created product.
     */
    private function assignProductFeatures(int $productId, string $hotelId, array $hotel): void
    {
        $container = Container::getInstance();
        $featureMapper = $container->featureMapper();
        $normalizer = $container->novotonNormalizer();
        $facilityRepo = $container->facilityRepository();

        // Star rating — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        if (!empty($hotel['star_rating']) && (int) $hotel['star_rating'] >= 1) {
            $code = $normalizer->normalizeStarRating((string) $hotel['star_rating']);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'stars', $code);
            }
        }

        // Board types — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        $hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
        if (!empty($hotelData['boards'])) {
            $boardCodes = [];
            foreach ($hotelData['boards'] as $board) {
                $raw = is_array($board) ? ($board['IdBoard'] ?? $board['Board'] ?? '') : (string) $board;
                $code = $normalizer->normalizeBoardCode((string) $raw);
                if ($code !== null) {
                    $boardCodes[] = $code;
                }
            }
            if (!empty($boardCodes)) {
                $featureMapper->assignMultipleViaCore($productId, 'board', array_unique($boardCodes));
            }
        }

        // Facilities — resolved via travel_core canonical mapping (travel_feature_map + travel_api_alias)
        $allFacilityIds = $facilityRepo->getIdsForHotel($hotelId);
        if (!empty($allFacilityIds)) {
            $facilityCodes = [];
            foreach ($allFacilityIds as $fid) {
                $code = $normalizer->normalizeFacilityCode($fid);
                if ($code !== null) {
                    $facilityCodes[] = $code;
                }
            }
            if (!empty($facilityCodes)) {
                $featureMapper->assignMultipleViaCore($productId, 'facility', array_unique($facilityCodes));
            }
        }

        // Travel Group: adults-only detection — via travel_core mapping
        if (($hotel['is_adults_only'] ?? 'N') === 'Y') {
            $featureMapper->assignFeatureViaCore($productId, 'travel_group', 'adults_only');
        }

        // Resort / City — via travel_core mapping (dynamic: auto-registers unknown values)
        if (!empty($hotel['city'])) {
            $resortCode = $normalizer->normalizeResort($hotel['city']);
            if ($resortCode !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'resort', $resortCode);
            }
        }

        // Property type — uses shared travel_core mapping
        if (!empty($hotel['property_type'])) {
            $code = $normalizer->normalizePropertyType($hotel['property_type']);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'property_type', $code);
            }
        }
    }

    /**
     * Build the placeholder map for SEO template rendering from Novoton hotel data.
     */
    private function buildPlaceholders(array $hotel, string $displayName, string $description = ''): array
    {
        // Load facility names from DB
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

        return [
            'name'          => $displayName,
            'raw_name'      => $hotel['hotel_name'] ?? '',
            'city'          => $hotel['city'] ?? '',
            'country'       => $hotel['country'] ?? '',
            'region'        => $hotel['region'] ?? '',
            'star_rating'   => $hotel['star_rating'] ?? '',
            'hotel_type'    => $hotel['hotel_type'] ?? '',
            'property_type' => $hotel['property_type'] ?? 'hotel',
            'year'          => date('Y'),
            'description'   => $description,
            'facilities'    => $facilities,
            'latitude'      => $hotel['latitude'] ?? '',
            'longitude'     => $hotel['longitude'] ?? '',
        ];
    }

    private function getExcludedResorts(): array
    {
        $paramVal = $this->getParam('exclude_resorts');
        if (!empty($paramVal)) {
            if (is_array($paramVal)) {
                $excluded = array_filter($paramVal);
            } else {
                $excluded = array_filter(array_map('trim', explode(',', $paramVal)));
            }
        } else {
            $excluded = ConfigProvider::getExcludedResorts();
        }

        // Always include hidden/internal resorts in the exclusion list
        return array_values(array_unique(array_merge($excluded, ConfigProvider::getHiddenResorts())));
    }
}
