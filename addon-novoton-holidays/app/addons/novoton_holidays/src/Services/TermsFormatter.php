<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Terms Formatter
 *
 * OOP replacement for the procedural fn_novoton_holidays_format_payment_terms()
 * and fn_novoton_holidays_format_cancellation_terms() functions.
 *
 * Parses Novoton API XML terms and formats them for display.
 *
 * @package NovotonHolidays
 * @since 3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class TermsFormatter
{
    /**
     * Format payment terms XML for display.
     *
     * @param string $xmlString Raw XML string from Novoton API
     * @return string Formatted payment terms (newline-separated)
     */
    public static function formatPaymentTerms(string $xmlString): string
    {
        $terms = self::parsePaymentTerms($xmlString);

        if (empty($terms)) {
            return '';
        }

        $lines = [];

        foreach ($terms as $term) {
            $percent = isset($term['percent']) ? number_format(TypeCoerce::toFloat($term['percent']), 0) : '0';
            $date = $term['date'] ?? '';

            if (!empty($date)) {
                $formattedDate = self::formatDate(TypeCoerce::toString($date));
                $lines[] = __('novoton_holidays.payment_percent_until', [
                    '[percent]' => $percent,
                    '[date]' => $formattedDate,
                ]);
            } elseif (!empty($term['is_on_booking'])) {
                $lines[] = __('novoton_holidays.payment_percent_on_booking', ['[percent]' => $percent]);
            } else {
                $lines[] = "{$percent}%";
            }
        }

        return implode("\n", TypeCoerce::toStringList($lines));
    }

    /**
     * Format cancellation terms XML for display.
     *
     * @param string $xmlString Raw XML string from Novoton API
     * @param string $checkIn Check-in date for relative calculations
     * @return string Formatted cancellation terms (newline-separated)
     */
    public static function formatCancellationTerms(string $xmlString, string $checkIn = ''): string
    {
        $terms = self::parseCancellationTerms($xmlString, $checkIn);

        if (empty($terms)) {
            return '';
        }

        $lines = [];
        $prevTillDate = null;

        foreach ($terms as $idx => $term) {
            $value = $term['value'] ?? 0;
            $type = $term['type'] ?? 'Percent';
            $tillDate = $term['till_date'] ?? '';
            $isLast = ($idx === count($terms) - 1);

            if ($value === 'FREE' || TypeCoerce::toFloat($value) === 0.0) {
                if (!empty($tillDate)) {
                    $lines[] = __('novoton_holidays.cancel_free_before', ['[date]' => self::formatDate(TypeCoerce::toString($tillDate))]);
                } else {
                    $lines[] = __('novoton_holidays.cancel_free');
                }
                $prevTillDate = $tillDate;
            } elseif ($isLast && $type === 'Percent' && TypeCoerce::toFloat($value) >= 100) {
                $lines[] = __('novoton_holidays.cancel_no_show');
            } else {
                if ($type === 'Over Nights' || $type === 'Overnights') {
                    $nights = TypeCoerce::toInt($value);
                    $penaltyStr = __('novoton_holidays.cancel_nights_penalty', ['[nights]' => $nights]);
                } else {
                    $percent = number_format(TypeCoerce::toFloat($value), 0);
                    $penaltyStr = __('novoton_holidays.cancel_percent_penalty', ['[percent]' => $percent]);
                }

                if (!empty($prevTillDate) && !empty($tillDate)) {
                    $fromStr = self::formatDate((int) strtotime(TypeCoerce::toString($prevTillDate) . ' +1 day'));
                    $toStr = self::formatDate(TypeCoerce::toString($tillDate));
                    $lines[] = __('novoton_holidays.cancel_between_dates', [
                        '[from]' => $fromStr,
                        '[to]' => $toStr,
                        '[penalty]' => $penaltyStr,
                    ]);
                } elseif (!empty($tillDate)) {
                    $lines[] = __('novoton_holidays.cancel_until_date', [
                        '[date]' => self::formatDate(TypeCoerce::toString($tillDate)),
                        '[penalty]' => $penaltyStr,
                    ]);
                } else {
                    $lines[] = ucfirst(TypeCoerce::toString($penaltyStr));
                }

                $prevTillDate = $tillDate;
            }
        }

        return implode("\n", TypeCoerce::toStringList($lines));
    }

    /**
     * Format a date using CS-Cart's configured date format.
     *
     * @param string|int $date Date string or timestamp
     * @return string Formatted date
     */
    public static function formatDate($date): string
    {
        if (empty($date)) {
            return '';
        }

        $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
        if (empty($timestamp)) {
            return (string) $date;
        }

        $dateFormat = ConfigProvider::getDateFormat();
        // ConfigProvider default is '%d %b %Y'; for terms display we prefer
        // the numeric form when the admin setting is empty.
        if ($dateFormat === '%d %b %Y') {
            $dateFormat = '%d.%m.%Y';
        }

        $phpFormat = str_replace(
            ['%d', '%m', '%Y', '%y', '%B', '%b', '%A', '%a'],
            ['d', 'm', 'Y', 'y', 'F', 'M', 'l', 'D'],
            $dateFormat,
        );

        return date($phpFormat, $timestamp);
    }

    /**
     * Parse payment terms from XML string.
     *
     * @param string $xmlString XML terms string
     * @return list<array<string, mixed>> Parsed terms data
     */
    public static function parsePaymentTerms(string $xmlString): array
    {
        if (empty($xmlString)) {
            return [];
        }

        $terms = [];

        try {
            $xml = self::parseXmlString($xmlString);
            if ($xml === null) {
                return [];
            }

            $percentRules = $xml->xpath('//Percent') ?: [];

            if (!empty($percentRules)) {
                foreach ($percentRules as $rule) {
                    $percent = (int) round((float) (string) $rule);
                    $tillDate = (string) ($rule['tillDate'] ?? $rule['TillDate'] ?? '');

                    if ($percent > 0) {
                        $terms[] = [
                            'percent' => $percent,
                            'date' => $tillDate,
                            'date_formatted' => !empty($tillDate) ? self::formatDate($tillDate) : '',
                            'is_on_booking' => empty($tillDate),
                        ];
                    }
                }
            } else {
                $paymentRules = $xml->xpath('//PaymentRule') ?: $xml->xpath('//paymentRule') ?: [];

                foreach ($paymentRules as $rule) {
                    $rawDate = (string) ($rule['DateTo'] ?? $rule['tillDate'] ?? $rule['to'] ?? '');
                    $term = [
                        'percent' => (int) round((float) ($rule['PerCent'] ?? $rule['percent'] ?? (string) $rule)),
                        'date' => $rawDate,
                        'date_formatted' => !empty($rawDate) ? self::formatDate($rawDate) : '',
                        'is_on_booking' => false,
                    ];

                    if ($term['percent'] > 0) {
                        $terms[] = $term;
                    }
                }
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => 'Novoton: payment terms parse error: ' . $e->getMessage()]);
        }

        return $terms;
    }

    /**
     * Parse cancellation terms from XML string.
     *
     * @param string $xmlString XML terms string
     * @param string $checkIn Check-in date for relative calculations
     * @return list<array<string, mixed>> Parsed cancellation terms
     */
    public static function parseCancellationTerms(string $xmlString, string $checkIn = ''): array
    {
        if (empty($xmlString)) {
            return [];
        }

        $terms = [];

        try {
            $xml = self::parseXmlString($xmlString);
            if ($xml === null) {
                return [];
            }

            $penaltyRules = $xml->xpath('//Penalty') ?: [];

            if (!empty($penaltyRules)) {
                $checkInTs = !empty($checkIn) ? strtotime($checkIn) : 0;

                foreach ($penaltyRules as $rule) {
                    $value = (float) (string) $rule;
                    $tillDate = (string) ($rule['tillDate'] ?? $rule['TillDate'] ?? '');
                    $type = (string) ($rule['Type'] ?? $rule['type'] ?? 'Percent');

                    $daysBefore = 0;
                    if (!empty($tillDate) && !empty($checkInTs)) {
                        $tillTs = strtotime($tillDate);
                        if (!empty($tillTs)) {
                            $daysBefore = max(0, ($checkInTs - $tillTs) / 86400);
                        }
                    }

                    $term = [
                        'value' => $value,
                        'type' => $type,
                        'till_date' => $tillDate,
                        'days_before' => (int) $daysBefore,
                        'is_penalty' => ($value > 0),
                    ];

                    if ($value === 0.0) {
                        $term['value'] = 'FREE';
                        $term['is_penalty'] = false;
                    }

                    $terms[] = $term;
                }

                usort($terms, fn ($a, $b): int => strcmp($a['till_date'], $b['till_date']));
            } else {
                $cancelRules = $xml->xpath('//CancelRule') ?: $xml->xpath('//cancelRule') ?: [];

                foreach ($cancelRules as $rule) {
                    $term = [
                        'days_before' => (int) ($rule['DaysBefore'] ?? $rule['daysBefore'] ?? $rule['Days'] ?? 0),
                        'value' => (float) ($rule['PerCent'] ?? $rule['percent'] ?? $rule['Penalty'] ?? 0),
                        'type' => (string) ($rule['Type'] ?? $rule['type'] ?? 'Percent'),
                        'is_penalty' => true,
                    ];

                    if (!empty($checkIn) && $term['days_before'] > 0) {
                        $checkInTs = strtotime($checkIn);
                        if (!empty($checkInTs)) {
                            $term['till_date'] = date('Y-m-d', (int) strtotime("-{$term['days_before']} days", $checkInTs));
                        }
                    }

                    if ($term['days_before'] > 0 || $term['value'] > 0) {
                        $terms[] = $term;
                    }
                }

                usort($terms, fn ($a, $b): int => $b['days_before'] - $a['days_before']);
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => 'Novoton: cancellation terms parse error: ' . $e->getMessage()]);
        }

        return $terms;
    }

    /**
     * Parse an XML string, handling CDATA and wrapping in root if needed.
     *
     * @param string $xmlString Raw XML
     */
    private static function parseXmlString(string $xmlString): ?\SimpleXMLElement
    {
        if (empty($xmlString)) {
            return null;
        }

        $xmlString = trim($xmlString);

        if (!str_starts_with($xmlString, '<')) {
            if (preg_match('/<!\[CDATA\[(.*?)]]>/s', $xmlString, $matches) === 1) {
                $xmlString = $matches[1];
            }
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);

        if ($xml === false) {
            libxml_clear_errors();
            $xml = simplexml_load_string('<root>' . $xmlString . '</root>', 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        }

        libxml_clear_errors();

        return $xml ?: null;
    }
}
