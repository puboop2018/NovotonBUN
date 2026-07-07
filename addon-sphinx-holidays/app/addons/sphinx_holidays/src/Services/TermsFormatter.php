<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Normalizes Sphinx payment-terms / cancellation-fees payloads into plain
 * display lines.
 *
 * The same terms travel through three shapes: raw arrays on the
 * verify/quote responses (`payment_terms`, `cancellation_fees`), JSON
 * strings persisted on `sphinx_bookings` (`payment_terms_json`,
 * `cancellation_fees_json` — written by OrderStatusSyncService), and the
 * cart-item extras rendered on order pages. This formatter accepts any of
 * them and is deliberately forgiving about entry keys: entries may be bare
 * strings or maps using description/text/label/name plus optional
 * amount+currency / percent and date/from/until markers.
 */
final class TermsFormatter
{
    /**
     * @return list<string> Human-readable lines; [] for empty/unparseable input
     */
    public static function lines(mixed $terms): array
    {
        if (is_string($terms)) {
            $trimmed = trim($terms);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                // A bare sentence is a valid single term.
                return [$trimmed];
            }
            $terms = $decoded;
        }

        if (!is_array($terms)) {
            return [];
        }

        $lines = [];
        foreach ($terms as $entry) {
            $line = self::entryLine($entry);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function entryLine(mixed $entry): string
    {
        if (is_string($entry) || is_numeric($entry)) {
            return trim((string) $entry);
        }
        if (!is_array($entry)) {
            return '';
        }

        $map = TypeCoerce::toStringMap($entry);

        $text = TypeCoerce::toString(
            $map['description'] ?? $map['text'] ?? $map['label'] ?? $map['name'] ?? $map['title'] ?? '',
        );

        $amount = '';
        if (isset($map['amount']) && is_numeric($map['amount'])) {
            $amount = rtrim(rtrim(number_format((float) $map['amount'], 2, '.', ''), '0'), '.');
            $currency = TypeCoerce::toString($map['currency'] ?? '');
            if ($currency !== '') {
                $amount .= ' ' . $currency;
            }
        } elseif (isset($map['percent']) && is_numeric($map['percent'])) {
            $amount = rtrim(rtrim(number_format((float) $map['percent'], 2, '.', ''), '0'), '.') . '%';
        }

        $date = TypeCoerce::toString(
            $map['date'] ?? $map['deadline'] ?? $map['due_date'] ?? $map['from'] ?? $map['until'] ?? '',
        );

        $parts = array_values(array_filter([$text, $amount], static fn (string $p): bool => $p !== ''));
        $line = implode(' — ', $parts);
        if ($date !== '') {
            $line = $line === '' ? $date : "{$line} ({$date})";
        }

        return $line;
    }
}
