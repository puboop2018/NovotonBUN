<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;

class AddProductsCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    public static function getModes(): array
    {
        return ['add_hotels_as_products'];
    }

    public static function getDescription(): string
    {
        return 'Add hotels with prices as CS-Cart products';
    }

    /**
     * @return array<string, mixed>
     */
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

        // Re-seed feature mappings idempotently. Previously called
        // fn_novoton_holidays_ensure_feature_mappings() and checked its return
        // for unconfigured/seeded counts, but that function had been a
        // stub-returning-empty since the travel_core migration, so both
        // branches were unreachable. Call the seeders directly.
        if (function_exists('fn_travel_core_seed_feature_map')) {
            fn_travel_core_seed_feature_map();
        }
        if (function_exists('fn_novoton_holidays_seed_travel_aliases')) {
            fn_novoton_holidays_seed_travel_aliases();
        }

        $this->output('Adding hotels as products...');
        $this->output('Countries: ' . implode(', ', $countries));
        $this->output('Limit per country: ' . ($limit > 0 ? $limit : 'No limit'));
        if (!empty($exclude_resorts)) {
            $this->output('Excluding resorts (' . count($exclude_resorts) . '): ' . implode(', ', $exclude_resorts));
        }
        $this->output('');

        $hotelRepo = Container::getInstance()->hotelRepository();
        $image_base_url = \Tygh\Addons\NovotonHolidays\Constants::IMAGE_BASE_URL;
        $grand_total = 0;
        $grand_added = 0;

        foreach ($countries as $country) {
            $this->output("=== {$country} ===");

            $hotels = $hotelRepo->findUnlinkedWithPrices($country, $exclude_resorts, $limit);
            $this->output('Found ' . count($hotels) . ' hotels to add.');

            if (empty($hotels)) {
                $this->output('');
                continue;
            }

            $category_id = ConfigProvider::getCategoryForCountry($country);
            if (!$category_id) {
                $category_path = str_replace('{country}', $country, \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CATEGORY_TEMPLATE);
                $category_id = fn_novoton_holidays_get_or_create_category($category_path);
            }

            if (!$category_id) {
                $this->output("ERROR: No category mapping for '{$country}' and auto-creation failed. Skipping.");
                $this->output('');
                $grand_total += count($hotels);
                continue;
            }

            $added = 0;

            foreach ($hotels as $hotel) {
                if (!is_array($hotel)) {
                    continue;
                }
                $hotel_id = PriceInfoFormatter::toScalar($hotel['hotel_id'] ?? '');
                $hotel_name = PriceInfoFormatter::toScalar($hotel['hotel_name'] ?? '');
                $hotel_city = PriceInfoFormatter::toScalar($hotel['city'] ?? '');
                $product_code = 'NVT' . $hotel_id;

                $this->output("[{$hotel_id}] {$hotel_name} ({$hotel_city}) ... ", false);

                // Check if CS-Cart product already exists (core products table)
                $existing = db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $product_code);
                if ($existing) {
                    $hotelRepo->linkToProduct($hotel_id, PriceInfoFormatter::toInt($existing));
                    $this->output('LINKED');
                    continue;
                }

                // Detect property type for this hotel
                $propertyDetector = new PropertyTypeDetector();
                $hotelData = fn_novoton_holidays_get_hotel_data($hotel_id);
                /** @var array<string, mixed> $hotelData */
                $hotelData = is_array($hotelData) ? $hotelData : [];
                $packageNames = [];
                $roomNames = [];
                $pkgs = is_array($hotelData['packages'] ?? null) ? $hotelData['packages'] : [];
                foreach ($pkgs as $pkg) {
                    $packageNames[] = is_array($pkg) ? PriceInfoFormatter::toScalar($pkg['PackageName'] ?? '') : PriceInfoFormatter::toScalar($pkg);
                }
                $rms = is_array($hotelData['rooms'] ?? null) ? $hotelData['rooms'] : [];
                foreach ($rms as $rm) {
                    $roomNames[] = is_array($rm) ? PriceInfoFormatter::toScalar($rm['Type'] ?? $rm['IdRoom'] ?? '') : PriceInfoFormatter::toScalar($rm);
                }
                $detectedType = $propertyDetector->detect($hotel_name, $packageNames, $roomNames);

                // Format hotel display name: Title Case + append property type for short names
                $display_name = fn_novoton_holidays_format_hotel_display_name($hotel_name, $detectedType);

                $description = '';
                try {
                    $desc = $this->api->hotels()->getHotelDescription($hotel_id, 'UK');
                    if ($desc && isset($desc->Description)) {
                        $description = (string) $desc->Description;
                    }
                } catch (\Exception $e) {
                    fn_log_event('general', 'runtime', ['message' => "Novoton: Failed to get description for hotel {$hotel_id}", 'error' => $e->getMessage()]);
                }

                // Apply SEO templates respecting overwrite mode + field toggles
                $placeholders = \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::buildNovotonPlaceholders($hotel, $display_name, $description);
                $seoFields = fn_travel_core_apply_seo_fields('novoton_holidays', $placeholders, 0, $hotel_id);

                $product_data = array_merge([
                    'product_code' => $product_code,
                    'price' => 0,
                    'amount' => ConfigProvider::getDefaultProductQuantity(),
                    'status' => 'D',
                    'company_id' => ConfigProvider::getCompanyId(),
                    'main_category' => $category_id,
                    'category_ids' => [$category_id],
                ], $seoFields);

                $product_id = fn_update_product($product_data, 0, CART_LANGUAGE);

                if ($product_id) {
                    $hotelRepo->linkToProduct($hotel_id, $product_id);

                    try {
                        $images = $this->api->hotels()->getHotelImages($hotel_id);
                        if ($images && isset($images->url)) {
                            $count = 0;
                            foreach ($images->url as $url) {
                                $image_url = $image_base_url . str_replace(' ', '%20', (string) $url);
                                fn_novoton_holidays_add_product_image($product_id, $image_url, $count === 0);
                                if (++$count >= 10) {
                                    break;
                                }
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
                    $this->output("FAILED (category_id={$category_id}, company_id=" . ConfigProvider::getCompanyId() . ')');
                }

                usleep(Constants::API_DELAY_NORMAL);
            }

            $this->output("{$country}: Added {$added} of " . count($hotels));
            $this->output('');
            $grand_total += count($hotels);
            $grand_added += $added;
        }

        // Clear FeatureMapper cache after batch import to free memory
        \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();

        $this->output('=== TOTAL ===');
        $this->output("Added: {$grand_added} of {$grand_total}");

        $stats = ['added' => $grand_added, 'total' => $grand_total, 'countries' => count($countries)];
        $this->sendReport('add_products', [
            'added' => $grand_added, 'total' => $grand_total, 'countries' => implode(', ', $countries), 'duration' => $this->getDuration() . 's',
        ]);

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Assign all mappable features to a newly created product.
     * @param array<string, mixed> $hotel
     */
    private function assignProductFeatures(int $productId, string $hotelId, array $hotel): void
    {
        $container = Container::getInstance();
        $featureMapper = $container->featureMapper();
        $normalizer = $container->novotonNormalizer();
        $facilityRepo = $container->facilityRepository();

        // Star rating — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        $starRating = PriceInfoFormatter::toInt($hotel['star_rating'] ?? 0);
        if ($starRating >= 1) {
            $code = $normalizer->normalizeStarRating((string) $starRating);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'stars', $code);
            }
        }

        // Board types — uses shared travel_core mapping (travel_feature_map + travel_api_alias)
        $hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
        /** @var array<string, mixed> $hotelData */
        $hotelData = is_array($hotelData) ? $hotelData : [];
        $boards = is_array($hotelData['boards'] ?? null) ? $hotelData['boards'] : [];
        if (!empty($boards)) {
            $boardCodes = [];
            foreach ($boards as $board) {
                $raw = is_array($board) ? PriceInfoFormatter::toScalar($board['IdBoard'] ?? $board['Board'] ?? '') : PriceInfoFormatter::toScalar($board);
                $code = $normalizer->normalizeBoardCode($raw);
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
                $featureMapper->assignFacilitiesViaCore($productId, array_unique($facilityCodes));
            }
        }

        // Travel Group — derived from facility codes + hotel flags (not from API)
        $resolvedFacilityCodes = [];
        foreach ($allFacilityIds as $fid) {
            $code = $normalizer->normalizeFacilityCode($fid);
            if ($code !== null) {
                $mapping = \Tygh\Addons\TravelCore\Services\FeatureMapper::resolveFacility('novoton', $code);
                if ($mapping && !empty($mapping['canonical_code'])) {
                    $resolvedFacilityCodes[] = $mapping['canonical_code'];
                }
            }
        }

        $travelGroups = TravelGroupResolver::derive(
            $resolvedFacilityCodes,
            ($hotel['is_adults_only'] ?? 'N') === 'Y',
        );
        if (!empty($travelGroups)) {
            $featureMapper->assignMultipleViaCore($productId, 'travel_group', $travelGroups);
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
     * @return list<string>
     */
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
