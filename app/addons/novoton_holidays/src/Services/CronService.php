<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Cron Service
 *
 * Centralized service for cron job operations.
 * Extracted from novoton_cron.php for better maintainability.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;

class CronService implements CronServiceInterface
{
    private $api;
    private $countries;
    private $output = [];

    public function __construct()
    {
        $this->api = new NovotonApi();
        $this->countries = fn_novoton_holidays_parse_countries(ConfigProvider::get('selected_countries', ''));
    }

    /**
     * Check ASK status bookings
     * Polls API for status updates on pending ASK bookings
     *
     * @return array Results with updated/unchanged/errors counts
     */
    public function checkAskBookings(): array
    {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'details' => []
        ];

        $bookings = db_get_array(
            "SELECT booking_id, novoton_confirm_id, novoton_invoice_id, hotel_name, novoton_status
             FROM ?:novoton_bookings
             WHERE novoton_status = ?s AND status IN (?a)
             ORDER BY created_at DESC LIMIT 50",
            Constants::NOVOTON_STATUS_ON_REQUEST,
            [Constants::STATUS_PENDING, Constants::STATUS_ASK]
        );

        foreach ($bookings as $booking) {
            $results['processed']++;

            try {
                $idNum = ($booking['novoton_invoice_id'] ?? '') ?: ($booking['novoton_confirm_id'] ?? '');
                if (empty($idNum)) {
                    $results['errors']++;
                    continue;
                }

                $resInfo = $this->api->getReservationInfo($idNum);

                if (!$resInfo) {
                    $results['unchanged']++;
                    continue;
                }

                $newStatus = (string)($resInfo->Status ?? '');

                if (!empty($newStatus) && $newStatus !== $booking['novoton_status']) {
                    // Status changed
                    $csStatus = $this->mapNovotonStatus($newStatus);

                    db_query(
                        "UPDATE ?:novoton_bookings SET
                         novoton_status = ?s,
                         status = ?s,
                         last_status_check = NOW()
                         WHERE booking_id = ?i",
                        $newStatus, $csStatus, $booking['booking_id']
                    );

                    $results['updated']++;
                    $results['details'][] = [
                        'booking_id' => $booking['booking_id'],
                        'hotel' => $booking['hotel_name'],
                        'old_status' => $booking['novoton_status'],
                        'new_status' => $newStatus
                    ];
                } else {
                    db_query(
                        "UPDATE ?:novoton_bookings SET last_status_check = NOW() WHERE booking_id = ?i",
                        $booking['booking_id']
                    );
                    $results['unchanged']++;
                }

            } catch (\Exception $e) {
                $results['errors']++;
                fn_log_event('general', 'runtime', [
                    'message' => 'Cron: Error checking ASK booking',
                    'booking_id' => $booking['booking_id'],
                    'error' => $e->getMessage()
                ]);
            }

            usleep(200000); // 200ms delay
        }

        return $results;
    }

    /**
     * Check pending alternative requests
     * Polls API for alternatives on pending requests
     *
     * @return array Results
     */
    public function checkAlternatives(): array
    {
        $results = [
            'processed' => 0,
            'found' => 0,
            'notified' => 0,
            'errors' => 0
        ];

        $pending = db_get_array(
            "SELECT request_id, novoton_request_id, hotel_name, contact_email
             FROM ?:novoton_alternative_requests
             WHERE status = ?s
               AND novoton_request_id != ''
               AND novoton_request_id IS NOT NULL",
            Constants::STATUS_PENDING
        );
        // Decrypt encrypted PII (contact_email) for email sending
        $pending = fn_novoton_holidays_decrypt_requests_pii($pending);

        foreach ($pending as $request) {
            $results['processed']++;

            try {
                $response = $this->api->getAlternatives($request['novoton_request_id']);

                if ($response && !empty($response->alternative)) {
                    // Found alternatives
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->Hotel ?? ''),
                            'price' => (string)($alt->Price ?? ''),
                            'room' => (string)($alt->Room ?? ''),
                            'board' => (string)($alt->Board ?? '')
                        ];
                    }

                    db_query(
                        "UPDATE ?:novoton_alternative_requests SET
                         status = 'alternatives_found',
                         alternatives_data = ?s
                         WHERE request_id = ?i",
                        json_encode($alternatives), $request['request_id']
                    );

                    $results['found']++;

                    // Send notification email
                    if (!empty($request['contact_email'])) {
                        $this->sendAlternativesEmail($request, $alternatives);
                        $results['notified']++;
                    }
                }

            } catch (\Exception $e) {
                $results['errors']++;
                fn_log_event('general', 'runtime', [
                    'message' => 'Cron: Error checking alternatives',
                    'request_id' => $request['request_id'],
                    'error' => $e->getMessage()
                ]);
            }

            usleep(300000); // 300ms delay
        }

        return $results;
    }

    /**
     * Map Novoton status to CS-Cart booking status
     *
     * @param string $novotonStatus Status from API
     * @return string CS-Cart status
     */
    private function mapNovotonStatus(string $novotonStatus): string
    {
        return Constants::NOVOTON_STATUS_TO_INTERNAL[$novotonStatus] ?? Constants::STATUS_PENDING;
    }

    /**
     * Send alternatives notification email
     *
     * @param array $request Original request
     * @param array $alternatives Found alternatives
     */
    private function sendAlternativesEmail(array $request, array $alternatives): void
    {
        // Use CS-Cart mailer
        if (function_exists('fn_send_mail')) {
            fn_send_mail([
                'to' => $request['contact_email'],
                'from' => 'default_company_orders_department',
                'subj' => 'Alternative Hotels Available - ' . $request['hotel_name'],
                'body' => $this->formatAlternativesEmail($request, $alternatives)
            ]);

            db_query(
                "UPDATE ?:novoton_alternative_requests SET
                 status = 'notified',
                 notified_at = NOW()
                 WHERE request_id = ?i",
                $request['request_id']
            );
        }
    }

    /**
     * Format alternatives email body
     *
     * @param array $request Original request
     * @param array $alternatives Found alternatives
     * @return string Email body
     */
    private function formatAlternativesEmail(array $request, array $alternatives): string
    {
        $body = "We found alternative hotels for your request:\n\n";
        $body .= "Original hotel: {$request['hotel_name']}\n\n";
        $body .= "Available alternatives:\n";

        foreach ($alternatives as $i => $alt) {
            $body .= ($i + 1) . ". {$alt['hotel_name']}\n";
            $body .= "   Room: {$alt['room']}\n";
            $body .= "   Board: {$alt['board']}\n";
            $body .= "   Price: {$alt['price']}\n\n";
        }

        return $body;
    }

    /**
     * Get countries configured for sync
     *
     * @return array
     */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /**
     * Get API instance
     *
     * @return NovotonApi
     */
    public function getApi(): NovotonApi
    {
        return $this->api;
    }
}
