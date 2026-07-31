<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The provider's own terms payload, verbatim, for the terms modal's debug panel.
 *
 * WHY: `payment_terms` and `cancellation_fees` arrive from Sphinx as objects
 * that are almost always EMPTY on search —
 *
 *     "payment_terms":     { "is_loaded": false, "text": null, "rules": [] }
 *     "cancellation_fees": { "is_loaded": false, "is_free": false,
 *                            "text": null, "rules": [] }
 *
 * — and are only populated by the verify endpoint. When the modal shows
 * nothing, the question is always the same: did the API send empty blocks, did
 * verify fail, or did we mangle them on the way to the screen? The formatted
 * view cannot answer that, because every one of those cases renders the same.
 * This carries the untouched blocks plus the transport facts, so the answer is
 * on screen instead of inferred.
 *
 * ALWAYS built from the RAW offer, before TermsFormatter touches anything.
 *
 * Gated on the addon's existing "Enable debug logging" setting: this is
 * operator diagnostics on a customer-facing modal, so it must be off by
 * default and never reach a shopper.
 */
final class TermsDebug
{
    /** Cap on the echoed upstream body — a 5xx stack trace runs to many KB. */
    private const int RAW_LIMIT = 4000;

    /**
     * @param bool $enabled ConfigProvider::isDebugLogging()
     * @param array<array-key, mixed>|null $offer unwrapped verify offer, or the
     *                                            search snapshot, or null when neither is available
     * @param string $source where the blocks below came from
     * @return array<string, mixed>|null null when debug is off
     */
    public static function build(
        bool $enabled,
        string $source,
        int $httpCode,
        string $transportError,
        string $rawResponse,
        ?array $offer,
    ): ?array {
        if (!$enabled) {
            return null;
        }

        $truncated = strlen($rawResponse) > self::RAW_LIMIT;

        return [
            'source' => $source,
            'http_code' => $httpCode,
            'transport_error' => $transportError !== '' ? $transportError : null,
            // Verbatim. Not coerced, not defaulted, not reshaped — an absent
            // key and an empty object are different findings and must stay
            // distinguishable.
            'payment_terms' => $offer !== null && array_key_exists('payment_terms', $offer)
                ? $offer['payment_terms']
                : null,
            'cancellation_fees' => $offer !== null && array_key_exists('cancellation_fees', $offer)
                ? $offer['cancellation_fees']
                : null,
            'must_verify' => $offer !== null && array_key_exists('must_verify', $offer)
                ? $offer['must_verify']
                : null,
            'confirmation' => $offer !== null ? TypeCoerce::toString($offer['confirmation'] ?? '') : null,
            'raw_response' => $truncated ? substr($rawResponse, 0, self::RAW_LIMIT) : $rawResponse,
            'raw_response_truncated' => $truncated,
            'raw_response_bytes' => strlen($rawResponse),
        ];
    }
}
