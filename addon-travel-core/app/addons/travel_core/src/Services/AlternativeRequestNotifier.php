<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Emails the store's orders department when a guest submits a
 * "no availability" request, so requests don't sit unnoticed in the admin
 * grid. Failures are logged and never break the customer flow.
 *
 * @since 1.5.0
 */
class AlternativeRequestNotifier
{
    /** @var callable(array<string, string>): bool */
    private $mailer;

    /**
     * @param callable(array<string, string>): bool|null $mailer Test seam;
     *                                                           defaults to fn_send_mail with the admin ('A') area.
     */
    public function __construct(?callable $mailer = null)
    {
        $this->mailer = $mailer ?? static fn (array $mail): bool => (bool) fn_send_mail($mail, 'A');
    }

    /**
     * @param array<string, mixed> $request provider, hotel_name, hotel_id,
     *                                      check_in, check_out, nights, adults, children, num_rooms,
     *                                      contact_email, contact_phone, notes
     */
    public function notifyAdmin(array $request): bool
    {
        $adminEmail = $this->getAdminEmail();
        if ($adminEmail === '') {
            fn_log_event('general', 'runtime', [
                'message' => '[TravelAlternatives] Cannot notify admin of new availability request: '
                    . 'the company_orders_department email is not configured.',
            ]);

            return false;
        }

        $provider = TypeCoerce::toString($request['provider'] ?? '');
        $hotelName = TypeCoerce::toString($request['hotel_name'] ?? '') ?: TypeCoerce::toString($request['hotel_id'] ?? '');
        $checkIn = TypeCoerce::toString($request['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($request['check_out'] ?? '');
        $party = TypeCoerce::toInt($request['adults'] ?? 0) . ' adults'
            . (TypeCoerce::toInt($request['children'] ?? 0) > 0 ? ', ' . TypeCoerce::toInt($request['children']) . ' children' : '')
            . ', ' . TypeCoerce::toInt($request['num_rooms'] ?? 1) . ' room(s)';
        $phone = TypeCoerce::toString($request['contact_phone'] ?? '');
        $notes = TypeCoerce::toString($request['notes'] ?? '');

        $subject = "[Travel] New availability request: {$hotelName} ({$checkIn} - {$checkOut})";
        $body = "A guest asked to be contacted about a hotel with no availability.\n\n"
            . "Provider: {$provider}\n"
            . "Hotel: {$hotelName} (#" . TypeCoerce::toString($request['hotel_id'] ?? '') . ")\n"
            . "Period: {$checkIn} - {$checkOut} (" . TypeCoerce::toInt($request['nights'] ?? 0) . " nights)\n"
            . "Party: {$party}\n"
            . 'Contact: ' . TypeCoerce::toString($request['contact_email'] ?? '')
            . ($phone !== '' ? " / {$phone}" : '') . "\n"
            . ($notes !== '' ? "Notes: {$notes}\n" : '')
            . "\nReview all requests: admin.php?dispatch=travel_alternatives.manage";

        try {
            return ($this->mailer)([
                'to' => $adminEmail,
                'from' => 'default_company_orders_department',
                'subject' => $subject,
                'body' => $body,
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => '[TravelAlternatives] Failed to send admin notification: ' . $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getAdminEmail(): string
    {
        return TypeCoerce::toString(db_get_field(
            "SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'",
        ));
    }
}
