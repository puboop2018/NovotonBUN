<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;

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

        $ask_bookings = db_get_array(
            "SELECT * FROM ?:novoton_bookings
             WHERE novoton_status = 'ASK' AND status IN ('pending', 'ask')
             ORDER BY created_at DESC LIMIT 50"
        );

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

                if ($new_status === 'confirmed' || $new_status === 'ok') {
                    db_query(
                        "UPDATE ?:novoton_bookings SET status = 'confirmed', novoton_status = 'OK', last_status_check = NOW(), updated_at = NOW() WHERE booking_id = ?i",
                        $booking['booking_id']
                    );
                    $this->output("  -> Updated to CONFIRMED");
                    $updated++;
                } elseif ($new_status === 'cancelled' || $new_status === 'rejected') {
                    db_query(
                        "UPDATE ?:novoton_bookings SET status = 'cancelled', novoton_status = 'CX', last_status_check = NOW(), updated_at = NOW() WHERE booking_id = ?i",
                        $booking['booking_id']
                    );
                    $this->output("  -> Updated to CANCELLED");
                    $updated++;
                } else {
                    db_query(
                        "UPDATE ?:novoton_bookings SET last_status_check = NOW() WHERE booking_id = ?i",
                        $booking['booking_id']
                    );
                    $this->output("  -> Status unchanged: " . (string)$response->Status);
                }
            }
        }

        $stats = ['checked' => $checked, 'changed' => $updated];
        $this->logComplete('resinfo', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}
