<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Parsers for the novoton provider's XML payloads — CDATA-tolerant loading,
 * generic XML→array conversion, and the TermsOfPayment / TermsOfCancellation
 * rule extraction the terms display + booking pipeline consume. Bodies
 * extracted from functions/formatting.php; the fn_* shells delegate here.
 * The CS-Cart date format arrives as a parameter (Registry stays at the
 * boundary), so every method is unit-testable without CS-Cart.
 */
final class ProviderXmlParser
{
    /**
     * Parse an XML string that may be wrapped in CDATA — handles raw XML,
     * CDATA-wrapped XML, and fragments that need a root wrapper.
     */
    public static function parseXmlString(string $xml_string): ?\SimpleXMLElement
    {
        if ($xml_string === '') {
            return null;
        }

        $xml_string = trim($xml_string);

        // Extract from CDATA if needed
        if (!str_starts_with($xml_string, '<')) {
            if (preg_match('/<!\[CDATA\[(.*?)]]>/s', $xml_string, $matches) === 1) {
                $xml_string = $matches[1];
            }
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);

        if ($xml === false) {
            libxml_clear_errors();
            $xml = simplexml_load_string('<root>' . $xml_string . '</root>', 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        }

        libxml_clear_errors();

        return $xml === false ? null : $xml;
    }

    /**
     * Parse payment terms.
     *
     * Novoton API format:
     *   <TermsOfPayment>
     *     <Percent tillDate="2026-02-05">30</Percent>
     *     <Percent tillDate="2026-08-21">70</Percent>
     *   </TermsOfPayment>
     * with a generic <PaymentRule> fallback.
     *
     * @param string $csDateFormat CS-Cart strftime date format for date_formatted
     * @return list<array<string, mixed>> Parsed terms data
     */
    public static function parsePaymentTerms(string $xml_string, string $csDateFormat = ''): array
    {
        if ($xml_string === '') {
            return [];
        }

        $terms = [];

        try {
            $xml = self::parseXmlString($xml_string);
            if ($xml === null) {
                return [];
            }

            // Try Novoton format first: <Percent tillDate="...">value</Percent>
            $percentRules = $xml->xpath('//Percent') ?: [];

            if (!empty($percentRules)) {
                foreach ($percentRules as $rule) {
                    $percent = (int) round((float) (string) $rule);
                    $tillDate = (string) ($rule['tillDate'] ?? $rule['TillDate'] ?? '');

                    if ($percent > 0) {
                        $terms[] = [
                            'percent' => $percent,
                            'date' => $tillDate,
                            'date_formatted' => $tillDate !== '' ? DisplayFormatter::formatDate($tillDate, $csDateFormat) : '',
                            'is_on_booking' => $tillDate === '',
                        ];
                    }
                }
            } else {
                // Fallback: Try generic PaymentRule format
                $paymentRules = $xml->xpath('//PaymentRule') ?: $xml->xpath('//paymentRule') ?: [];

                foreach ($paymentRules as $rule) {
                    $rawDate = (string) ($rule['DateTo'] ?? $rule['tillDate'] ?? $rule['to'] ?? '');
                    $term = [
                        'percent' => (int) round((float) (string) ($rule['PerCent'] ?? $rule['percent'] ?? (string) $rule)),
                        'date' => $rawDate,
                        'date_formatted' => $rawDate !== '' ? DisplayFormatter::formatDate($rawDate, $csDateFormat) : '',
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
     * Parse cancellation terms.
     *
     * Novoton API format:
     *   <TermsOfCancellation>
     *     <Penalty tillDate="2026-08-25" Type="Over Nights">0</Penalty>
     *     <Penalty tillDate="2026-09-02" Type="Percent">100</Penalty>
     *   </TermsOfCancellation>
     * with a generic <CancelRule> fallback.
     *
     * @param string $check_in Check-in date for relative calculations
     * @return list<array<string, mixed>> Parsed cancellation terms
     */
    public static function parseCancellationTerms(string $xml_string, string $check_in = ''): array
    {
        if ($xml_string === '') {
            return [];
        }

        $terms = [];

        try {
            $xml = self::parseXmlString($xml_string);
            if ($xml === null) {
                return [];
            }

            // Try Novoton format first: <Penalty tillDate="..." Type="...">value</Penalty>
            $penaltyRules = $xml->xpath('//Penalty') ?: [];

            if (!empty($penaltyRules)) {
                $check_in_ts = $check_in !== '' ? strtotime($check_in) : 0;

                foreach ($penaltyRules as $rule) {
                    $value = (float) (string) $rule;
                    $tillDate = (string) ($rule['tillDate'] ?? $rule['TillDate'] ?? '');
                    $type = (string) ($rule['Type'] ?? $rule['type'] ?? 'Percent');

                    // Calculate days before check-in
                    $days_before = 0;
                    if ($tillDate !== '' && TypeCoerce::toBool($check_in_ts)) {
                        $till_ts = strtotime($tillDate);
                        if (TypeCoerce::toBool($till_ts)) {
                            $days_before = max(0, (TypeCoerce::toInt($check_in_ts) - TypeCoerce::toInt($till_ts)) / 86400);
                        }
                    }

                    $term = [
                        'value' => $value,
                        'type' => $type,
                        'till_date' => $tillDate,
                        'days_before' => (int) $days_before,
                        'is_penalty' => ($value > 0),
                    ];

                    // Mark as FREE if value is 0
                    if ($value === 0.0) {
                        $term['value'] = 'FREE';
                        $term['is_penalty'] = false;
                    }

                    $terms[] = $term;
                }

                // Sort by till_date ascending (earliest first)
                usort($terms, static fn (array $a, array $b): int => strcmp(TypeCoerce::toString($a['till_date']), TypeCoerce::toString($b['till_date'])));
            } else {
                // Fallback: Try generic CancelRule format
                $cancelRules = $xml->xpath('//CancelRule') ?: $xml->xpath('//cancelRule') ?: [];

                foreach ($cancelRules as $rule) {
                    $term = [
                        'days_before' => (int) (string) ($rule['DaysBefore'] ?? $rule['daysBefore'] ?? $rule['Days'] ?? 0),
                        'value' => (float) (string) ($rule['PerCent'] ?? $rule['percent'] ?? $rule['Penalty'] ?? 0),
                        'type' => (string) ($rule['Type'] ?? $rule['type'] ?? 'Percent'),
                        'is_penalty' => true,
                    ];

                    // Calculate actual date if check_in provided
                    if ($check_in !== '' && $term['days_before'] > 0) {
                        $check_in_ts = strtotime($check_in);
                        if ($check_in_ts !== false && $check_in_ts !== 0) {
                            $term['till_date'] = date('Y-m-d', (int) strtotime("-{$term['days_before']} days", $check_in_ts));
                        }
                    }

                    if ($term['days_before'] > 0 || $term['value'] > 0) {
                        $terms[] = $term;
                    }
                }

                // Sort by days_before descending (earliest deadlines first)
                usort($terms, static fn (array $a, array $b): int => TypeCoerce::toInt($b['days_before']) - TypeCoerce::toInt($a['days_before']));
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', ['message' => 'Novoton: cancellation terms parse error: ' . $e->getMessage()]);
        }

        return $terms;
    }

    /**
     * The latest date cancellation is still free, or null when none is.
     */
    public static function freeCancellationDate(string $xml_string): ?string
    {
        $terms = self::parseCancellationTerms($xml_string);

        if ($terms === []) {
            return null;
        }

        // Find the first term with 0 penalty (free cancellation)
        foreach ($terms as $term) {
            $value = $term['value'] ?? null;
            $tillDate = TypeCoerce::toString($term['till_date'] ?? '');

            if (($value === 'FREE' || $value === 0 || $value === '0' || $value === 0.0) && $tillDate !== '') {
                return $tillDate;
            }
        }

        return null;
    }

    /**
     * Convert XML to array recursively (children merged, attributes as @keys).
     *
     * @return array<string, mixed>
     */
    public static function xmlToArray(\SimpleXMLElement|string $xml): array
    {
        if (is_string($xml)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($xml === false) {
                libxml_clear_errors();
                return [];
            }
            libxml_clear_errors();
        }

        $result = [];

        foreach ($xml->children() as $key => $value) {
            $arr = self::xmlToArray($value);

            if (isset($result[$key])) {
                if (!is_array($result[$key]) || !isset($result[$key][0])) {
                    $result[$key] = [$result[$key]];
                }
                $result[$key][] = count($arr) > 0 ? $arr : (string) $value;
            } else {
                $result[$key] = count($arr) > 0 ? $arr : (string) $value;
            }
        }

        // Include attributes
        foreach ($xml->attributes() as $key => $value) {
            $result['@' . $key] = (string) $value;
        }

        return $result;
    }
}
