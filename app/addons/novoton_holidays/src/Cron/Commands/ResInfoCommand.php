<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\Container;

class ResInfoCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['resinfo'];
    }

    public static function getDescription(): string
    {
        return 'Check ASK booking statuses via resinfo API';
    }

    public function execute(): array
    {
        $this->output("Checking ASK bookings status...");
        $this->output("");

        $repo = Container::getInstance()->bookingRepository();
        $ask_bookings = $repo->findByNovotonStatus(Constants::NOVOTON_STATUS_ON_REQUEST, [Constants::STATUS_PENDING, Constants::STATUS_ASK]);

        $checked = count($ask_bookings);
        $updated = 0;

        if (empty($ask_bookings)) {
            $this->output("No pending ASK bookings found.");
        } else {
            $this->output("Found {$checked} pending ASK bookings.");
            $this->output("");

            foreach ($ask_bookings as $booking) {
                $this->output("Booking #{$booking['booking_id']} (Order #{$booking['order_id']})...");

                $reservation_id = $booking['novoton_confirm_id'] ?: ($booking['novoton_res_num'] ?? '');

                if (empty($reservation_id)) {
                    $this->output("  No reservation ID - skipping");
                    continue;
                }

                $response = $this->api->getReservationInfo($reservation_id);

                if (!$response || !isset($response->Status)) {
                    $this->output("  No response from API");
                    continue;
                }

                $new_status = strtolower((string)$response->Status);

                if ($new_status === Constants::STATUS_CONFIRMED || $new_status === strtolower(Constants::NOVOTON_STATUS_CONFIRMED)) {
                    $repo->update((int) $booking['booking_id'], [
                        'status'            => Constants::STATUS_CONFIRMED,
                        'novoton_status'    => Constants::NOVOTON_STATUS_CONFIRMED,
                        'last_status_check' => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                    $this->output("  -> Updated to CONFIRMED");
                    $updated++;
                } elseif ($new_status === Constants::STATUS_CANCELLED || $new_status === strtolower(Constants::NOVOTON_STATUS_CANCELLED) || $new_status === 'rejected') {
                    $repo->update((int) $booking['booking_id'], [
                        'status'            => Constants::STATUS_CANCELLED,
                        'novoton_status'    => Constants::NOVOTON_STATUS_CANCELLED,
                        'last_status_check' => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                    $this->output("  -> Updated to CANCELLED");
                    $updated++;
                } else {
                    $repo->update((int) $booking['booking_id'], [
                        'last_status_check' => date('Y-m-d H:i:s'),
                    ]);
                    $this->output("  -> Status unchanged: " . (string)$response->Status);
                }
            }
        }

        $stats = ['checked' => $checked, 'changed' => $updated];
        $this->logComplete('resinfo', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}
