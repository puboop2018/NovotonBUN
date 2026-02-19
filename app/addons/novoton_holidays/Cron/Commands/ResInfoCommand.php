<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;

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

        $repo = new BookingRepository();
        $ask_bookings = $repo->findByNovotonStatus('ASK', ['pending', 'ask']);

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
                    $repo->update($booking['booking_id'], [
                        'status'            => 'confirmed',
                        'novoton_status'    => 'OK',
                        'last_status_check' => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                    $this->output("  -> Updated to CONFIRMED");
                    $updated++;
                } elseif ($new_status === 'cancelled' || $new_status === 'rejected') {
                    $repo->update($booking['booking_id'], [
                        'status'            => 'cancelled',
                        'novoton_status'    => 'CX',
                        'last_status_check' => date('Y-m-d H:i:s'),
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                    $this->output("  -> Updated to CANCELLED");
                    $updated++;
                } else {
                    $repo->update($booking['booking_id'], [
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
