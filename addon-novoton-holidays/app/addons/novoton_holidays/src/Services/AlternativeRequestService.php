<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Alternative Request Service
 *
 * Handles creation of alternative booking requests:
 * - Builds API request data
 * - Calls hotel_request API
 * - Stores request in database (with PII encryption)
 * - Sends confirmation email
 *
 * Extracted from novoton_booking.php request_alternatives mode.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface;
use Tygh\Addons\TravelCore\TravelConstants;

class AlternativeRequestService implements AlternativeRequestServiceInterface
{
    private ?SecurityServiceInterface $security;

    private ?NovotonApi $api;

    private AlternativeRequestRepositoryInterface $altRequestRepo;

    public function __construct(
        ?SecurityServiceInterface $security = null,
        ?NovotonApi $api = null,
        ?AlternativeRequestRepositoryInterface $altRequestRepo = null
    ) {
        $this->security = $security;
        $this->api = $api;
        $this->altRequestRepo = $altRequestRepo ?? new AlternativeRequestRepository();
    }

    /**
     * Get or lazy-create SecurityService.
     */
    private function getSecurity(): SecurityServiceInterface
    {
        if ($this->security === null) {
            $this->security = Container::getInstance()->securityService();
        }
        return $this->security;
    }

    /**
     * Get or lazy-create the API instance.
     */
    private function getApi(): NovotonApi
    {
        if ($this->api === null) {
            $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            }
            $this->api = new NovotonApi();
        }
        return $this->api;
    }

    /**
     * Create an alternative booking request.
     *
     * Calls the Novoton hotel_request API, stores the request in the database
     * (encrypting PII fields), and sends a confirmation email.
     *
     * @param array $params {
     *   hotel_id: string,
     *   hotel_name: string,
     *   check_in: string,
     *   check_out: string,
     *   nights: int,
     *   adults: int,
     *   children: int,
     *   num_rooms: int,
     *   contact_email: string,
     *   contact_phone: string,
     *   notes: string
     * }
     * @return array{success: bool, request_id: int, novoton_id: string, message: string, error: string}
     */
    public function submitAlternativeBookingRequest(array $params): array
    {
        $security = $this->getSecurity();

        $hotelId = $params['hotel_id'] ?? '';
        $hotelName = $params['hotel_name'] ?? '';
        $checkIn = $params['check_in'] ?? '';
        $checkOut = $params['check_out'] ?? '';
        $nights = (int) ($params['nights'] ?? 7);
        $adults = (int) ($params['adults'] ?? 2);
        $children = (int) ($params['children'] ?? 0);
        $numRooms = (int) ($params['num_rooms'] ?? 1);
        $contactEmail = trim($params['contact_email'] ?? '');
        $contactPhone = trim($params['contact_phone'] ?? '');
        $notes = strip_tags(mb_substr(trim($params['notes'] ?? ''), 0, 2000));

        // Validate required fields
        if (empty($hotelId) || empty($checkIn) || empty($contactEmail)) {
            return [
                'success' => false,
                'request_id' => 0,
                'novoton_id' => '',
                'message' => '',
                'error' => 'missing_required_fields',
            ];
        }

        // Verify hotel exists (FK constraint: novoton_alternative_requests.hotel_id -> novoton_hotels.hotel_id)
        $hotelExists = db_get_field("SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?s", $hotelId);
        if (!$hotelExists) {
            return [
                'success' => false,
                'request_id' => 0,
                'novoton_id' => '',
                'message' => '',
                'error' => 'invalid_hotel',
            ];
        }

        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'request_id' => 0,
                'novoton_id' => '',
                'message' => '',
                'error' => 'invalid_email',
            ];
        }

        // Validate check_out format
        if (!empty($checkOut) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            $checkOut = '';
        }

        try {
            $api = $this->getApi();

            // Build guest placeholders
            $guests = [];
            $roomGuests = [];
            for ($i = 1; $i <= $adults; $i++) {
                $guests[] = ['id' => $i, 'name' => 'Guest ' . $i, 'birthday' => '', 'age' => 30];
                $roomGuests[] = ['id' => $i, 'name' => 'Guest ' . $i];
            }

            $requestData = [
                'hotel_id' => $hotelId,
                'package_name' => $hotelName,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'room_id' => '',
                'board_id' => '',
                'holder' => 'Request from ' . $contactEmail,
                'remark' => $notes,
                'comment' => "Contact: {$contactEmail}" . ($contactPhone ? ", Phone: {$contactPhone}" : ''),
                'guests' => $guests,
                'room_guests' => $roomGuests,
            ];

            $apiResult = $api->reservations()->createHotelRequest($requestData, 'UK', true);

            // Store in DB — encrypt PII
            $requestRecord = $this->buildRequestRecord(
                $hotelId, $hotelName, $checkIn, $checkOut, $nights,
                $adults, $children, $numRooms,
                $contactEmail, $contactPhone, $notes,
                TravelConstants::STATUS_PENDING,
                $apiResult['xml_sent'] ?? '',
                $apiResult['xml_response'] ?? '',
                $apiResult['id_num'] ?? ''
            );

            $security->logSecurityEvent('alternative_request_created', [
                'hotel_id' => $hotelId,
                'novoton_request_id' => $apiResult['id_num'] ?? '',
            ]);

            $requestId = $this->altRequestRepo->create($requestRecord);

            // Send confirmation email
            $this->sendConfirmationEmail($contactEmail, [
                'hotel_name' => $hotelName,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $nights,
                'adults' => $adults,
                'children' => $children,
                'request_id' => $requestId,
                'novoton_id' => $apiResult['id_num'] ?? '',
            ]);

            return [
                'success' => true,
                'request_id' => $requestId,
                'novoton_id' => $apiResult['id_num'] ?? '',
                'message' => 'alternatives_request_sent',
                'error' => '',
            ];
        } catch (ApiException $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_request API error (HTTP ' . $e->getHttpCode() . ')',
                'api_function' => $e->getApiFunction(),
                'error' => $e->getMessage(),
            ]);

            // Still save request even if API fails
            $requestRecord = $this->buildRequestRecord(
                $hotelId, $hotelName, $checkIn, $checkOut, $nights,
                $adults, $children, $numRooms,
                $contactEmail, $contactPhone, $notes,
                'pending_manual',
                '',
                $e->getMessage(),
                ''
            );

            $requestId = $this->altRequestRepo->create($requestRecord);

            return [
                'success' => false,
                'request_id' => $requestId,
                'novoton_id' => '',
                'message' => 'alternatives_request_saved',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the database record for an alternative request, encrypting PII.
     */
    private function buildRequestRecord(
        string $hotelId, string $hotelName, string $checkIn, string $checkOut,
        int $nights, int $adults, int $children, int $numRooms,
        string $contactEmail, string $contactPhone, string $notes,
        string $status, string $apiRequestXml, string $apiResponse, string $novotonRequestId
    ): array {
        $security = $this->getSecurity();

        return [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'num_rooms' => $numRooms,
            'contact_email' => $security->encrypt($contactEmail),
            'contact_phone' => !empty($contactPhone) ? $security->encrypt($contactPhone) : '',
            'notes' => !empty($notes) ? $security->encrypt($notes) : '',
            'status' => $status,
            'api_request_xml' => $apiRequestXml,
            'api_response' => $apiResponse,
            'novoton_request_id' => $novotonRequestId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Send confirmation email to customer.
     */
    private function sendConfirmationEmail(string $email, array $mailData): void
    {
        try {
            $mailer = Tygh::$app['mailer'];
            $mailer->send([
                'to' => $email,
                'from' => 'default_company_orders_department',
                'data' => $mailData,
                'template_code' => 'novoton_alternatives_request_confirmation',
                'tpl' => 'addons/novoton_holidays/email/alternatives_request_confirmation.tpl',
            ], 'A');
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton: Failed to send alternative request confirmation email',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
