<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\Container;

class AlternativesCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['alternative_rs', 'alternative_rs_bookings', 'notify_alternatives', 'expire_requests'];
    }

    public static function getDescription(): string
    {
        return 'Alternative request management (check, notify, expire)';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'alternative_rs';

        switch ($mode) {
            case 'alternative_rs':
                return $this->checkAlternatives();
            case 'alternative_rs_bookings':
                return $this->checkBookingAlternatives();
            case 'notify_alternatives':
                return $this->notifyAlternatives();
            case 'expire_requests':
                return $this->expireRequests();
        }

        return ['success' => false, 'error' => 'Unknown sub-mode'];
    }

    private function checkAlternatives(): array
    {
        $this->output("Checking alternative_RS for pending requests...");
        $this->output("");

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $pending = $altRepo->findPendingOlderThan(24);
        $pending = fn_novoton_holidays_decrypt_requests_pii($pending);

        if (empty($pending)) {
            $this->output("No pending requests older than 24 hours found.");
            return ['success' => true, 'stats' => ['checked' => 0]];
        }

        $this->output("Found " . count($pending) . " pending requests to check.");
        $this->output("");

        $found = 0;
        $emailed = 0;

        foreach ($pending as $request) {
            $this->output("Checking request #{$request['request_id']} (IdNum: {$request['novoton_request_id']})... ", false);

            $response = $this->api->getAlternatives($request['novoton_request_id']);

            if (!$response || !isset($response->alternative)) {
                $this->output("no response");
                usleep(200000);
                continue;
            }

            $alternatives = [];
            foreach ($response->alternative as $alt) {
                $alternatives[] = [
                    'res_num' => (string)($alt->ResNum ?? ''),
                    'hotel_id' => (string)($alt->IdHotel ?? ''),
                    'package_name' => (string)($alt->PackageName ?? ''),
                    'room_id' => (string)($alt->IdRoom ?? ''),
                    'check_in' => (string)($alt->CheckIn ?? ''),
                    'check_out' => (string)($alt->CheckOut ?? ''),
                    'board_id' => (string)($alt->IdBoard ?? ''),
                    'quota' => (string)($alt->Quota ?? ''),
                    'total' => (string)($alt->Total ?? '')
                ];
            }

            if (empty($alternatives)) {
                $this->output("no alternatives yet");
                usleep(200000);
                continue;
            }

            $altRepo->markAlternativesFound($request['request_id'], json_encode($alternatives));
            $found++;
            $this->output("FOUND " . count($alternatives) . " alternatives", false);

            // Send email notification
            if (!empty($request['contact_email'])) {
                $sent = $this->sendAlternativeEmail($request, $alternatives);
                if ($sent) {
                    $emailed++;
                    $altRepo->markNotified($request['request_id']);
                    $this->output(" -> Email sent");
                } else {
                    $this->output(" -> Email FAILED");
                }
            } else {
                $this->output("");
            }

            usleep(200000);
        }

        $this->output("");
        $this->output("Checked: " . count($pending));
        $this->output("Found alternatives: {$found}");
        $this->output("Emails sent: {$emailed}");

        return ['success' => true, 'stats' => ['checked' => count($pending), 'found' => $found, 'emailed' => $emailed]];
    }

    private function checkBookingAlternatives(): array
    {
        $this->output("Checking alternatives for RQ status bookings...");
        $this->output("");

        $bookingRepo = Container::getInstance()->bookingRepository();
        $bookings = $bookingRepo->findRqWithoutAlternatives();

        if (empty($bookings)) {
            $this->output("No RQ bookings to check.");
            return ['success' => true, 'stats' => ['checked' => 0]];
        }

        $this->output("Found " . count($bookings) . " RQ bookings to check.");
        $this->output("");

        foreach ($bookings as $booking) {
            $this->output("Booking #{$booking['booking_id']}... ", false);

            if (!empty($booking['novoton_reservation_id'])) {
                $this->api->getAlternatives($booking['novoton_reservation_id']);
                $bookingRepo->update($booking['booking_id'], ['alternatives_requested' => 1]);
                $this->output("checked");
            } else {
                $this->output("no reservation ID");
            }
            usleep(200000);
        }

        return ['success' => true, 'stats' => ['checked' => count($bookings)]];
    }

    private function notifyAlternatives(): array
    {
        $this->output("Sending notifications for found alternatives...");
        $this->output("");

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $requests = $altRepo->findUnnotified();
        $requests = fn_novoton_holidays_decrypt_requests_pii($requests);

        if (empty($requests)) {
            $this->output("No requests with alternatives to notify.");
            return ['success' => true, 'stats' => ['notified' => 0]];
        }

        $this->output("Found " . count($requests) . " requests to notify.");
        $this->output("");

        $notified = 0;
        foreach ($requests as $request) {
            $this->output("Request #{$request['request_id']} ({$request['contact_email']})... ", false);

            $alternatives = json_decode($request['alternatives_data'], true);
            if (empty($alternatives)) {
                $this->output("no alternatives data");
                continue;
            }

            $sent = $this->sendAlternativeEmail($request, $alternatives);
            if ($sent) {
                $altRepo->markNotified($request['request_id']);
                $notified++;
                $this->output("SENT");
            } else {
                $this->output("FAILED");
            }
        }

        $this->output("");
        $this->output("Notified: {$notified}");
        return ['success' => true, 'stats' => ['notified' => $notified]];
    }

    private function expireRequests(): array
    {
        $days = (int)$this->getParam('days', 30);
        $this->output("Expiring requests older than {$days} days...");

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $result = $altRepo->expireOlderThan($days);

        $this->output("Expired {$result} requests.");
        return ['success' => true, 'stats' => ['expired' => $result]];
    }

    private function sendAlternativeEmail(array $request, array $alternatives): bool
    {
        try {
            $company_data = fn_get_company_data(fn_get_default_company_id());
            $mail_data = [
                'hotel_name' => $request['hotel_name'],
                'check_in' => $request['check_in'],
                'check_out' => $request['check_out'],
                'nights' => $request['nights'] ?? '',
                'adults' => $request['adults'] ?? '',
                'children' => $request['children'] ?? '',
                'alternatives' => $alternatives,
                'request_id' => $request['request_id'],
                'company_data' => $company_data,
                'request' => $request,
            ];

            $mailer = \Tygh\Tygh::$app['mailer'];
            return (bool)$mailer->send([
                'to' => $request['contact_email'],
                'from' => 'default_company_orders_department',
                'data' => $mail_data,
                'template_code' => 'novoton_alternatives_available',
                'tpl' => 'addons/novoton_holidays/email/alternatives_available.tpl'
            ], 'A');
        } catch (\Exception $e) {
            return false;
        }
    }
}
