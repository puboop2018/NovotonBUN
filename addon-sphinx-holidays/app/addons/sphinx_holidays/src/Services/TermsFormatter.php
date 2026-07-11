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
     * Turn a payment-terms / cancellation-fees payload into human lines.
     *
     * Handles four shapes: a bare sentence, a JSON string, a LIST of entries
     * (strings or maps — the verify/quote arrays), and the API OBJECT wrapper
     * `{is_loaded, text, rules:[{until|since, value}]}` (the live hotels
     * search/verify shape). For the object wrapper a non-empty supplier `text`
     * wins; otherwise each rule is rendered value + currency + friendly date.
     *
     * $untilLabel / $sinceLabel are the connective words for rule dates
     * (payment `until`, cancellation `since`); the storefront passes localized
     * strings, tests use the English defaults.
     *
     * @return list<string> Human-readable lines; [] for empty/unparseable input
     */
    public static function lines(
        mixed $terms,
        string $currency = '',
        string $untilLabel = 'until',
        string $sinceLabel = 'from',
    ): array {
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

        // API object wrapper {is_loaded, text, rules:[...]} — a MAP carrying
        // rules/text/is_free/is_loaded, distinct from a LIST of entries.
        if (self::isTermsObject($terms)) {
            return self::objectLines($terms, $currency, $untilLabel, $sinceLabel);
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

    /**
     * The date until which cancellation is free — the earliest `since` boundary
     * across the cancellation rules (before it, cancellation is free), formatted
     * dd.mm.yyyy. Returns null when there are no dated rules (e.g. is_free with
     * no schedule, or an unparseable payload).
     */
    public static function freeCancellationUntil(mixed $cancellationFees): ?string
    {
        if (is_string($cancellationFees)) {
            $decoded = json_decode(trim($cancellationFees), true);
            $cancellationFees = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($cancellationFees)) {
            return null;
        }

        $rules = $cancellationFees['rules'] ?? null;
        if (!is_array($rules)) {
            return null;
        }

        $earliest = null;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $since = trim(TypeCoerce::toString($rule['since'] ?? $rule['from'] ?? $rule['until'] ?? ''));
            if ($since === '') {
                continue;
            }
            if ($earliest === null || $since < $earliest) {
                $earliest = $since;
            }
        }

        return $earliest === null ? null : self::friendlyDate($earliest);
    }

    /** @param array<int|string, mixed> $terms */
    private static function isTermsObject(array $terms): bool
    {
        if (array_is_list($terms)) {
            return false;
        }

        return array_key_exists('rules', $terms)
            || array_key_exists('text', $terms)
            || array_key_exists('is_free', $terms)
            || array_key_exists('is_loaded', $terms);
    }

    /**
     * @param array<int|string, mixed> $obj
     * @return list<string>
     */
    private static function objectLines(array $obj, string $currency, string $untilLabel, string $sinceLabel): array
    {
        // Supplier-provided prose wins — split on newlines into lines.
        $text = trim(TypeCoerce::toString($obj['text'] ?? ''));
        if ($text !== '') {
            $parts = preg_split('/\r\n|\r|\n/', $text) ?: [];
            return array_values(array_filter(
                array_map(static fn (string $l): string => trim($l), $parts),
                static fn (string $l): bool => $l !== '',
            ));
        }

        $rules = $obj['rules'] ?? null;
        if (!is_array($rules)) {
            return [];
        }

        $lines = [];
        foreach ($rules as $rule) {
            $line = self::ruleLine($rule, $currency, $untilLabel, $sinceLabel);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function ruleLine(mixed $rule, string $currency, string $untilLabel, string $sinceLabel): string
    {
        if (!is_array($rule)) {
            return '';
        }
        $map = TypeCoerce::toStringMap($rule);

        $value = self::formatValue($map, $currency);
        $until = trim(TypeCoerce::toString($map['until'] ?? ''));
        $since = trim(TypeCoerce::toString($map['since'] ?? ''));

        // Cancellation rule: "<sinceLabel> <date>: <value>".
        if ($since !== '') {
            $date = self::friendlyDate($since);
            return $value === '' ? "{$sinceLabel} {$date}" : "{$sinceLabel} {$date}: {$value}";
        }

        // Payment rule: "<value> — <untilLabel> <date>".
        if ($until !== '') {
            $date = self::friendlyDate($until);
            return $value === '' ? "{$untilLabel} {$date}" : "{$value} — {$untilLabel} {$date}";
        }

        return $value;
    }

    /** @param array<string, mixed> $map */
    private static function formatValue(array $map, string $currency): string
    {
        if (isset($map['value']) && is_numeric($map['value'])) {
            $num = rtrim(rtrim(number_format((float) $map['value'], 2, '.', ''), '0'), '.');
            $cur = trim(TypeCoerce::toString($map['currency'] ?? $currency));
            return $cur === '' ? $num : "{$num} {$cur}";
        }
        if (isset($map['percent']) && is_numeric($map['percent'])) {
            return rtrim(rtrim(number_format((float) $map['percent'], 2, '.', ''), '0'), '.') . '%';
        }

        return '';
    }

    /** ISO `YYYY-MM-DD` → `dd.mm.yyyy`; anything else is returned trimmed as-is. */
    private static function friendlyDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) === 1) {
            return "{$m[3]}.{$m[2]}.{$m[1]}";
        }

        return $date;
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
