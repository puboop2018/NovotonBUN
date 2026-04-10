<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Diagnostics Service
 *
 * Encapsulates test/diagnostic logic extracted from novoton_tools.php.
 * Each method returns a result array — the controller handles presentation.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;

class DiagnosticsService implements DiagnosticsServiceInterface
{
    private ?NovotonApi $api;

    private array $settings;

    public function __construct(?NovotonApi $api = null)
    {
        $this->settings = ConfigProvider::all();
        $this->api = $api;
    }

    /**
     * Get or lazy-create the API instance.
     */
    private function getApi(): NovotonApi
    {
        if ($this->api === null) {
            $this->api = new NovotonApi();
        }
        return $this->api;
    }

    /**
     * Test API connection and credentials.
     *
     * @return array{success: bool, config: array, message: string, hotels_count: int, sample_hotel: array|null, error: string}
     */
    public function testApiConnection(): array
    {
        $config = [
            'api_url' => $this->settings['api_url'] ?? 'NOT SET',
            'api_user' => $this->settings['api_user'] ?? 'NOT SET',
            'api_password_set' => !empty($this->settings['api_password']),
            'api_key_set' => !empty($this->settings['api_key']),
            'selected_countries' => $this->resolveCountriesDisplay(),
        ];

        try {
            $api = $this->getApi();
            $result = $api->hotels()->getHotelList(Constants::DEFAULT_COUNTRY, '%', '%', '%');

            if (empty($result)) {
                return [
                    'success' => false,
                    'config' => $config,
                    'message' => 'No hotels returned or invalid response.',
                    'hotels_count' => 0,
                    'sample_hotel' => null,
                    'error' => $api->getLastError(),
                    'last_request' => $api->getLastRequestFormatted(),
                    'last_http_code' => $api->getLastHttpCode(),
                    'raw_response_preview' => substr($api->getLastResponseRaw(), 0, 500),
                ];
            }

            $count = 0;
            $sample = null;
            foreach ($result as $hotel) {
                if (!$sample) {
                    $sample = [
                        'id' => (string)($hotel->IdHotel ?? ''),
                        'name' => (string)($hotel->Hotel ?? ''),
                        'city' => (string)($hotel->City ?? ''),
                    ];
                }
                $count++;
            }

            return [
                'success' => $count > 0,
                'config' => $config,
                'message' => $count > 0 ? "Retrieved {$count} hotels." : 'Response received but no hotels found.',
                'hotels_count' => $count,
                'sample_hotel' => $sample,
                'error' => '',
            ];
        } catch (ApiException $e) {
            return [
                'success' => false,
                'config' => $config,
                'message' => 'API Error (HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
                'hotels_count' => 0,
                'sample_hotel' => null,
                'error' => $e->getMessage(),
            ];
        } catch (XmlParsingException $e) {
            return [
                'success' => false,
                'config' => $config,
                'message' => 'XML Parsing Error: ' . $e->getMessage(),
                'hotels_count' => 0,
                'sample_hotel' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'config' => $config,
                'message' => 'Unexpected error: ' . $e->getMessage(),
                'hotels_count' => 0,
                'sample_hotel' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test hotel list API call.
     *
     * @param string $country Country name
     * @param int $limit Max hotels to return
     * @return array{success: bool, total: int, hotels: array, error: string}
     */
    public function testHotelList(string $country = Constants::DEFAULT_COUNTRY, int $limit = 10): array
    {
        try {
            $api = $this->getApi();
            $result = $api->hotels()->getHotelList($country);

            if (!$result || !isset($result->Hotel)) {
                return [
                    'success' => false,
                    'total' => 0,
                    'hotels' => [],
                    'error' => $api->getLastError(),
                ];
            }

            $hotels = $result->Hotel;
            $total = count($hotels);
            $items = [];
            $count = 0;

            foreach ($hotels as $hotel) {
                if ($count >= $limit) {
                    break;
                }
                $items[] = [
                    'id' => (string)($hotel['HotelId'] ?? ''),
                    'name' => (string)($hotel['HotelName'] ?? ''),
                    'city' => (string)($hotel['City'] ?? ''),
                    'stars' => (string)($hotel['Stars'] ?? ''),
                    'type' => (string)($hotel['HotelType'] ?? ''),
                ];
                $count++;
            }

            return [
                'success' => true,
                'total' => $total,
                'hotels' => $items,
                'error' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'total' => 0,
                'hotels' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test room price API call.
     *
     * @param array $params {hotel_id, room_id, board_id, check_in, check_out, adults}
     * @return array{success: bool, result: mixed, price: float, price_with_commission: float, raw_response: string, error: string}
     */
    public function testRoomPrice(array $params): array
    {
        if (empty($params['hotel_id'])) {
            return [
                'success' => false,
                'result' => null,
                'price' => 0,
                'price_with_commission' => 0,
                'raw_response' => '',
                'error' => 'hotel_id is required',
            ];
        }

        try {
            $api = $this->getApi();

            $priceParams = [
                'hotel_id' => $params['hotel_id'],
                'room_id' => $params['room_id'] ?? '',
                'board_id' => $params['board_id'] ?? 'AI',
                'check_in' => $params['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')),
                'check_out' => $params['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')),
                'adults' => (int) ($params['adults'] ?? 2),
                'children' => 0,
            ];

            $result = $api->pricing()->getRoomPrice($priceParams);

            $price = 0;
            $priceWithCommission = 0;
            if ($result && isset($result->Price)) {
                $price = (float) $result->Price;
                $priceWithCommission = $api->applyCommission($price);
            }

            return [
                'success' => !empty($result),
                'result' => $result,
                'params' => $priceParams,
                'price' => $price,
                'price_with_commission' => $priceWithCommission,
                'raw_response' => $api->getLastResponse(),
                'error' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'result' => null,
                'params' => $params,
                'price' => 0,
                'price_with_commission' => 0,
                'raw_response' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test availability search API call.
     *
     * @param array $params {hotel_id?, check_in, check_out, adults, children}
     * @return array{success: bool, results: array, count: int, error: string}
     */
    public function testSearch(array $params): array
    {
        try {
            $api = $this->getApi();

            $searchParams = [
                'check_in' => $params['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')),
                'check_out' => $params['check_out'] ?? date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')),
                'adults' => (int) ($params['adults'] ?? 2),
                'children' => (int) ($params['children'] ?? 0),
            ];

            if (!empty($params['hotel_id'])) {
                $searchParams['hotel_id'] = $params['hotel_id'];
            }

            $results = $api->availability()->searchAvailability($searchParams);

            if (empty($results)) {
                return [
                    'success' => true,
                    'results' => [],
                    'count' => 0,
                    'error' => '',
                ];
            }

            return [
                'success' => true,
                'results' => $results,
                'count' => count($results),
                'error' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'results' => [],
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test facilities sync.
     *
     * @return array{success: bool, result: array, facilities: array, error: string}
     */
    public function testFacilities(): array
    {
        try {
            $result = fn_novoton_holidays_sync_facilities_list();

            $facilities = array_slice((new FacilityRepository())->findAll(), 0, 50);

            return [
                'success' => !empty($result['success']),
                'result' => $result,
                'facilities' => $facilities,
                'error' => $result['error'] ?? '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'result' => [],
                'facilities' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test single product data retrieval.
     *
     * @param string $productCode Product code (e.g. NVT1603)
     * @return array{success: bool, product: array|null, hotel_id: string, hotel_info: mixed, packages_db: array, error: string}
     */
    public function testProduct(string $productCode): array
    {
        $product = db_get_row(
            "SELECT p.product_id, p.product_code, pd.product
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE p.product_code = ?s",
            CART_LANGUAGE,
            $productCode
        );

        if (!$product) {
            return [
                'success' => false,
                'product' => null,
                'hotel_id' => '',
                'hotel_info' => null,
                'packages_db' => [],
                'error' => "Product not found: {$productCode}",
            ];
        }

        preg_match('/\d+/', $product['product_code'], $matches);
        $hotelId = $matches[0] ?? '';

        if (empty($hotelId)) {
            return [
                'success' => false,
                'product' => $product,
                'hotel_id' => '',
                'hotel_info' => null,
                'packages_db' => [],
                'error' => 'Could not extract hotel ID from product code',
            ];
        }

        try {
            $api = $this->getApi();
            $hotelInfo = $api->hotels()->getHotelInfo($hotelId);

            if (!$hotelInfo) {
                return [
                    'success' => false,
                    'product' => $product,
                    'hotel_id' => $hotelId,
                    'hotel_info' => null,
                    'packages_db' => [],
                    'error' => 'getHotelInfo() returned FALSE — check var/logs/main.log',
                ];
            }

            $hotelData = json_decode(json_encode($hotelInfo), true);

            // Get DB packages
            $packagesDb = (new HotelPackageRepository())->findByHotelId(
                $hotelId
            );

            // Extract API packages/rooms/boards
            $apiPackages = [];
            if (isset($hotelData['packages'])) {
                $pkgs = isset($hotelData['packages']['IdCont']) ? [$hotelData['packages']] : $hotelData['packages'];
                foreach ($pkgs as $pkg) {
                    $apiPackages[] = $pkg['PackageName'] ?? 'N/A';
                }
            }

            $apiRooms = [];
            if (isset($hotelData['rooms'])) {
                $rms = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
                foreach (array_slice($rms, 0, 5) as $room) {
                    $apiRooms[] = [
                        'id' => $room['IdRoom'] ?? 'N/A',
                        'max_adults' => $room['maxADT'] ?? '?',
                    ];
                }
            }

            $apiBoards = [];
            if (isset($hotelData['board'])) {
                $bds = isset($hotelData['board']['IdBoard']) ? [$hotelData['board']] : $hotelData['board'];
                foreach (array_slice($bds, 0, 5) as $board) {
                    $apiBoards[] = is_array($board) ? ($board['IdBoard'] ?? '') : (string)$board;
                }
            }

            return [
                'success' => true,
                'product' => $product,
                'hotel_id' => $hotelId,
                'hotel_info' => [
                    'packages' => $apiPackages,
                    'rooms' => $apiRooms,
                    'boards' => $apiBoards,
                ],
                'packages_db' => $packagesDb,
                'error' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'product' => $product,
                'hotel_id' => $hotelId,
                'hotel_info' => null,
                'packages_db' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve countries display string from settings.
     */
    private function resolveCountriesDisplay(): string
    {
        $countries = $this->settings['selected_countries'] ?? Constants::DEFAULT_COUNTRY;

        if (is_array($countries)) {
            $names = [];
            foreach ($countries as $key => $value) {
                if ($value === 'Y' || $value === '1') {
                    $names[] = $key;
                } elseif (is_string($value) && strlen($value) > 2) {
                    $names[] = $value;
                }
            }
            return !empty($names) ? implode(', ', $names) : Constants::DEFAULT_COUNTRY;
        }

        return (string)$countries;
    }
}
