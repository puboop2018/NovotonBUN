<?php

declare(strict_types=1);
/**
 * eurosite_booking.offer_terms — AJAX JSON for the "Condiții de Anulare și
 * Plată" modal on the search results page (sphinx offer_terms shape).
 *
 * Eurosite search responses carry NO terms (unlike novoton, whose quote
 * includes them), so the modal fetches on open:
 *   - cancellation rules: live getItemFeesRequest for the snapshotted offer
 *     (placeholder pax names pre-booking — the client fills them);
 *   - payment terms: the admin-authored addon setting (the API exposes no
 *     payment schedule pre-booking).
 *
 * GET params: offer_key (from the search snapshot).
 */

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

header('Content-Type: application/json; charset=utf-8');

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;

if ($snapshot === null) {
    echo json_encode(['success' => false, 'error' => 'offer_expired']);
    exit;
}

$paymentLines = array_values(array_filter(array_map(
    'trim',
    explode("\n", ConfigProvider::getPaymentTermsText()),
), static fn (string $line): bool => $line !== ''));

try {
    $hotelRow = Container::hotels()->findByProductCode((string) $snapshot['product_code']);
    $tourop = $hotelRow !== null ? (string) ($hotelRow['tourop_code'] ?? '') : '';

    $roomCode = '';
    $rooms = is_array($snapshot['rooms'] ?? null) ? $snapshot['rooms'] : [];
    if ($rooms !== []) {
        $roomCode = (string) ($rooms[0]['code'] ?? '');
    }

    $fees = Container::getApi()->getItemFees([
        'currency'     => (string) $snapshot['currency'],
        'country_code' => (string) $snapshot['country_code'],
        'city_code'    => (string) $snapshot['city_code'],
        'product_code' => (string) $snapshot['product_code'],
        'variant_id'   => (string) $snapshot['variant_id'],
        'check_in'     => (string) $snapshot['check_in'],
        'check_out'    => (string) $snapshot['check_out'],
        'tourop_code'  => $tourop,
        'rooms'        => [[
            'code'     => $roomCode,
            'adults'   => (int) ($snapshot['adults'] ?? 2),
            'children' => (array) ($snapshot['children_ages'] ?? []),
        ]],
    ]);

    $feeRows = [];
    foreach ($fees as $fee) {
        $feeRows[] = [
            'from'  => (string) $fee['from_date'],
            'to'    => (string) $fee['to_date'],
            'value' => $fee['is_percent']
                ? rtrim(rtrim(number_format($fee['value'], 2), '0'), '.') . '%'
                : number_format($fee['value'], 2) . ' ' . (string) $snapshot['currency'],
        ];
    }

    echo json_encode([
        'success'       => true,
        'cancellation'  => $feeRows,
        'payment_lines' => $paymentLines,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite offer_terms failed: ' . $e->getMessage()]);
    echo json_encode([
        'success'       => true,
        'cancellation'  => [],
        'unavailable'   => true,
        'payment_lines' => $paymentLines,
    ], JSON_UNESCAPED_UNICODE);
}

exit;
