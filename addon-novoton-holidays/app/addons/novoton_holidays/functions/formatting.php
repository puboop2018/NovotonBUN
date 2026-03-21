<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Formatting Functions
 * 
 * Functions for formatting room types, board names, terms, etc.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;

/**
 * Format date using CS-Cart's date format from Admin > Settings > Appearance
 *
 * Uses Registry::get('settings.Appearance.date_format') for consistent formatting
 * across the entire store.
 *
 * @param string|int $date Date string or timestamp
 * @return string Formatted date
 */
function fn_novoton_holidays_format_date($date): string
{
    if (empty($date)) {
        return '';
    }

    // Convert to timestamp if string
    $timestamp = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$timestamp) {
        return (string)$date;
    }

    // Get date format from CS-Cart settings (Admin > Settings > Appearance > Date format)
    $date_format = Registry::get('settings.Appearance.date_format');

    // Fallback to DD.MM.YYYY if not set
    if (empty($date_format)) {
        $date_format = '%d.%m.%Y';
    }

    // CS-Cart uses strftime format (%d, %m, %Y), convert to PHP date format
    // Common CS-Cart formats: %d/%m/%Y, %m/%d/%Y, %d.%m.%Y, %Y-%m-%d
    $php_format = str_replace(
        ['%d', '%m', '%Y', '%y', '%B', '%b', '%A', '%a'],
        ['d', 'm', 'Y', 'y', 'F', 'M', 'l', 'D'],
        $date_format
    );

    return date($php_format, $timestamp);
}

/**
 * Format board name for display
 *
 * Delegates to BoardType value object (single source of truth).
 *
 * @param string $boardId Board code (AI, HB, FB, etc.)
 * @return string Formatted board name
 */
function fn_novoton_holidays_format_board_name($boardId): string
{
    return \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName($boardId);
}

/**
 * Format room type code for display
 *
 * Delegates to RoomType value object (single source of truth).
 *
 * When $roomType is provided (from hotelinfo API <Type>), formats as:
 *   "{Type display name} ({IdRoom})" e.g. "Camera Dubla (DBL 2+1)"
 *
 * When $roomType is empty, falls back to parsing the IdRoom code.
 *
 * @param string $roomId Room code from room_price API (e.g., "DBL 2+1", "1-BR APP 2+2")
 * @param string $roomType Room type from hotelinfo API (e.g., "DBL", "APP", "SGL")
 * @return string Formatted room display name
 */
function fn_novoton_holidays_format_room_type($roomId, $roomType = ''): string
{
    return \Tygh\Addons\TravelCore\ValueObjects\RoomType::formatRoomLabel($roomId, $roomType);
}

/**
 * Normalize room code - ensures + sign between occupancy numbers
 * E.g., "DBL 2 1 DELUXE" -> "DBL 2+1 DELUXE"
 *
 * Delegates to RoomType value object (single source of truth).
 *
 * @param string $roomCode Room code from API
 * @return string Normalized room code
 */
function fn_novoton_holidays_normalize_room_code($roomCode): string
{
    return \Tygh\Addons\TravelCore\ValueObjects\RoomType::normalizeRoomCode($roomCode);
}

/**
 * Normalize resort/city name for comparison
 *
 * Handles common API inconsistencies like "ST.CONSTANTINE & ELENA"
 * vs "ST. CONSTANTINE AND ELENA" by stripping punctuation and
 * normalizing whitespace.
 *
 * @param string $name Resort or city name
 * @return string Normalized name for comparison
 */
function fn_novoton_holidays_normalize_resort_name($name): string
{
    $name = strtoupper(trim($name));
    $name = str_replace('&', 'AND', $name);
    $name = str_replace(['.', ',', '-', "'", '"'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

/**
 * Parse an XML string that may be wrapped in CDATA.
 *
 * Handles raw XML, CDATA-wrapped XML, and fragments that need a root wrapper.
 *
 * @param string $xml_string Raw XML or CDATA string
 * @return \SimpleXMLElement|null Parsed XML or null on failure
 */
function fn_novoton_holidays_parse_xml_string($xml_string): ?\SimpleXMLElement
{
    if (empty($xml_string)) {
        return null;
    }

    $xml_string = trim($xml_string);

    // Extract from CDATA if needed
    if (strpos($xml_string, '<') !== 0) {
        if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $xml_string, $matches)) {
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

    return $xml ?: null;
}

/**
 * Parse payment terms from XML string
 * 
 * Novoton API format:
 * <TermsOfPayment>
 *   <Percent tillDate="2026-02-05">30</Percent>
 *   <Percent tillDate="2026-08-21">70</Percent>
 * </TermsOfPayment>
 * 
 * @param string $xml_string XML terms string
 * @return array Parsed terms data
 */
function fn_novoton_holidays_parse_payment_terms($xml_string): array
{
    if (empty($xml_string)) {
        return [];
    }

    $terms = [];

    try {
        $xml = fn_novoton_holidays_parse_xml_string($xml_string);
        if ($xml === null) {
            return [];
        }
        
        // Try Novoton format first: <Percent tillDate="...">value</Percent>
        $percentRules = $xml->xpath('//Percent') ?: [];
        
        if (!empty($percentRules)) {
            foreach ($percentRules as $rule) {
                $percent = (int)round((float)(string)$rule);
                $tillDate = (string)($rule['tillDate'] ?? $rule['TillDate'] ?? '');

                if ($percent > 0) {
                    $terms[] = [
                        'percent' => $percent,
                        'date' => $tillDate,
                        'date_formatted' => !empty($tillDate) ? fn_novoton_holidays_format_date($tillDate) : '',
                        'is_on_booking' => empty($tillDate),
                    ];
                }
            }
        } else {
            // Fallback: Try generic PaymentRule format
            $paymentRules = $xml->xpath('//PaymentRule') ?: $xml->xpath('//paymentRule') ?: [];

            foreach ($paymentRules as $rule) {
                $rawDate = (string)($rule['DateTo'] ?? $rule['tillDate'] ?? $rule['to'] ?? '');
                $term = [
                    'percent' => (int)round((float)($rule['PerCent'] ?? $rule['percent'] ?? (string)$rule ?? 0)),
                    'date' => $rawDate,
                    'date_formatted' => !empty($rawDate) ? fn_novoton_holidays_format_date($rawDate) : '',
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
 * Parse cancellation terms from XML string
 * 
 * Novoton API format:
 * <TermsOfCancellation>
 *   <Penalty tillDate="2026-08-25" Type="Over Nights">0</Penalty>
 *   <Penalty tillDate="2026-09-01" Type="Over Nights">2</Penalty>
 *   <Penalty tillDate="2026-09-02" Type="Percent">100</Penalty>
 * </TermsOfCancellation>
 * 
 * @param string $xml_string XML terms string
 * @param string $check_in Check-in date for relative calculations
 * @return array Parsed cancellation terms
 */
function fn_novoton_holidays_parse_cancellation_terms($xml_string, $check_in = ''): array
{
    if (empty($xml_string)) {
        return [];
    }
    
    $terms = [];

    try {
        $xml = fn_novoton_holidays_parse_xml_string($xml_string);
        if ($xml === null) {
            return [];
        }

        // Try Novoton format first: <Penalty tillDate="..." Type="...">value</Penalty>
        $penaltyRules = $xml->xpath('//Penalty') ?: [];
        
        if (!empty($penaltyRules)) {
            $check_in_ts = !empty($check_in) ? strtotime($check_in) : 0;
            
            foreach ($penaltyRules as $rule) {
                $value = (float)(string)$rule;
                $tillDate = (string)($rule['tillDate'] ?? $rule['TillDate'] ?? '');
                $type = (string)($rule['Type'] ?? $rule['type'] ?? 'Percent');
                
                // Calculate days before check-in
                $days_before = 0;
                if (!empty($tillDate) && $check_in_ts) {
                    $till_ts = strtotime($tillDate);
                    if ($till_ts && $check_in_ts) {
                        $days_before = max(0, ($check_in_ts - $till_ts) / 86400);
                    }
                }
                
                $term = [
                    'value' => $value,
                    'type' => $type,
                    'till_date' => $tillDate,
                    'days_before' => (int)$days_before,
                    'is_penalty' => ($value > 0),
                ];
                
                // Mark as FREE if value is 0
                if ($value === 0 || $value === 0.0) {
                    $term['value'] = 'FREE';
                    $term['is_penalty'] = false;
                }
                
                $terms[] = $term;
            }
            
            // Sort by till_date ascending (earliest first)
            usort($terms, function($a, $b) {
                return strcmp($a['till_date'], $b['till_date']);
            });
        } else {
            // Fallback: Try generic CancelRule format
            $cancelRules = $xml->xpath('//CancelRule') ?: $xml->xpath('//cancelRule') ?: [];
            
            foreach ($cancelRules as $rule) {
                $term = [
                    'days_before' => (int)($rule['DaysBefore'] ?? $rule['daysBefore'] ?? $rule['Days'] ?? 0),
                    'value' => (float)($rule['PerCent'] ?? $rule['percent'] ?? $rule['Penalty'] ?? 0),
                    'type' => (string)($rule['Type'] ?? $rule['type'] ?? 'Percent'),
                    'is_penalty' => true,
                ];
                
                // Calculate actual date if check_in provided
                if (!empty($check_in) && $term['days_before'] > 0) {
                    $check_in_ts = strtotime($check_in);
                    if ($check_in_ts) {
                        $term['till_date'] = date('Y-m-d', strtotime("-{$term['days_before']} days", $check_in_ts));
                    }
                }
                
                if ($term['days_before'] > 0 || $term['value'] > 0) {
                    $terms[] = $term;
                }
            }
            
            // Sort by days_before descending (earliest deadlines first)
            usort($terms, function($a, $b) {
                return $b['days_before'] - $a['days_before'];
            });
        }
        
    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', ['message' => 'Novoton: cancellation terms parse error: ' . $e->getMessage()]);
    }

    return $terms;
}

/**
 * Format payment terms with calculated amounts for display
 *
 * Uses CS-Cart's currency coefficient for conversion to match sitewide
 * price display (same approach as room prices on search results).
 *
 * Format: "10% (150 EUR) - due by 05.03.2026"
 *
 * @param string $xml_string Raw XML string with payment terms
 * @param float $total_price Total booking price in primary currency (EUR)
 * @param string $currency_code Display currency code (default: EUR)
 * @param float $coefficient Currency conversion coefficient (default: 1.0)
 * @param string $currency_symbol Currency symbol for display (default: '')
 * @return string Formatted payment terms with amounts
 */
function fn_novoton_holidays_format_payment_terms_with_amounts($xml_string, $total_price, $currency_code = 'EUR', $coefficient = 1.0, $currency_symbol = ''): string
{
    $terms = fn_novoton_holidays_parse_payment_terms($xml_string);

    if (empty($terms)) {
        return '';
    }

    // Resolve currency symbol from CS-Cart registry if not provided
    if (empty($currency_symbol) && !empty($currency_code)) {
        $currencies = \Tygh\Registry::get('currencies');
        if (!empty($currencies[$currency_code]['symbol'])) {
            $currency_symbol = $currencies[$currency_code]['symbol'];
        } else {
            $currency_symbol = $currency_code;
        }
        // Also resolve coefficient from registry if still default
        if ($coefficient == 1.0 && !empty($currencies[$currency_code]['coefficient'])) {
            $coefficient = (float)$currencies[$currency_code]['coefficient'];
        }
    }

    $lines = [];

    foreach ($terms as $term) {
        $percent = isset($term['percent']) ? (float)$term['percent'] : 0;
        $date = $term['date'] ?? '';

        // Calculate amount from percentage and convert to display currency
        $amount = ($percent / 100) * (float)$total_price * (float)$coefficient;

        // Use consistent price formatting (with sup tag for decimals)
        $formatted_amount = fn_novoton_holidays_format_price($amount, 1.0, $currency_symbol);
        $percent_display = number_format($percent, 0);

        if (!empty($date)) {
            $formatted_date = fn_novoton_holidays_format_date($date);
            $lines[] = __('novoton_holidays.payment_percent_amount_until', [
                '[percent]' => $percent_display,
                '[amount]' => $formatted_amount,
                '[date]' => $formatted_date
            ]);
        } elseif (!empty($term['is_on_booking'])) {
            $lines[] = __('novoton_holidays.payment_percent_amount_on_booking', [
                '[percent]' => $percent_display,
                '[amount]' => $formatted_amount
            ]);
        } else {
            $lines[] = "{$percent_display}% ({$formatted_amount})";
        }
    }

    return implode("\n", $lines);
}

/**
 * Format payment terms for display
 *
 * @param string $xml_string Raw XML string
 * @return string Formatted HTML
 */
function fn_novoton_holidays_format_payment_terms($xml_string): string
{
    return \Tygh\Addons\NovotonHolidays\Services\TermsFormatter::formatPaymentTerms((string) $xml_string);
}

/**
 * Format cancellation terms for display
 * 
 * @param string $xml_string Raw XML string
 * @param string $check_in Check-in date
 * @return string Formatted HTML
 */
function fn_novoton_holidays_format_cancellation_terms($xml_string, $check_in = ''): string
{
    return \Tygh\Addons\NovotonHolidays\Services\TermsFormatter::formatCancellationTerms((string) $xml_string, $check_in);
}

/**
 * Get free cancellation date from terms
 * 
 * @param string $xml_string Raw XML string
 * @return string|null Date string or null
 */
function fn_novoton_holidays_get_free_cancellation_date($xml_string): ?string
{
    $terms = fn_novoton_holidays_parse_cancellation_terms($xml_string);
    
    if (empty($terms)) {
        return null;
    }
    
    // Find the first term with 0 penalty (free cancellation)
    foreach ($terms as $term) {
        $value = $term['value'] ?? null;
        $tillDate = $term['till_date'] ?? '';
        
        if (($value === 'FREE' || $value == 0) && !empty($tillDate)) {
            return $tillDate;
        }
    }
    
    return null;
}

/**
 * Format hotel display name: Title Case + append property type for short names.
 *
 * Rules:
 *  1. Always convert to Title Case.
 *  2. If the name already contains a property type keyword (Hotel, Resort, etc.) — keep as-is.
 *  3. If the name has 3+ words — keep as-is.
 *  4. If the name has 1-2 words — append the detected property type from the feature mapping system.
 *
 * @param string $hotel_name  Raw hotel name (usually UPPERCASE from API)
 * @param string $detected_property_type  Property type code detected by PropertyTypeDetector (e.g. 'hotel', 'resort')
 * @return string  Formatted display name
 */
function fn_novoton_holidays_format_hotel_display_name(string $hotel_name, string $detected_property_type = ''): string
{
    $name = trim($hotel_name);
    if ($name === '') {
        return $name;
    }

    // Step 1: Title Case
    $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

    // Fix common patterns after Title Case (Roman numerals, &)
    $name = str_replace([' & ', ' And '], ' & ', $name);
    // Restore common Roman numerals that Title Case lowercased
    $name = preg_replace_callback('/\b(Ii|Iii|Iv|Vi|Vii|Viii|Ix)\b/', function ($m) {
        return strtoupper($m[1]);
    }, $name);

    // Step 2: Check if name already contains a property type keyword
    $detector = _nvt_property_type_detector();
    $nameType = $detector->detectFromName($name);
    if ($nameType !== null) {
        // Name already contains a type keyword (Hotel, Resort, Villa, etc.) — done
        return $name;
    }

    // Step 3: Count words — if 3+, keep as-is
    $word_count = count(preg_split('/\s+/', $name));
    if ($word_count >= 3) {
        return $name;
    }

    // Step 4: 1-2 words, append the property type
    if (empty($detected_property_type)) {
        $detected_property_type = 'hotel'; // default
    }

    // Map property type code to display label
    $type_labels = [
        'hotel'          => 'Hotel',
        'motel'          => 'Motel',
        'hostel'         => 'Hostel',
        'villa'          => 'Villa',
        'apartment'      => 'Apartments',
        'boarding_house'  => 'Boarding House',
        'cabin'           => 'Cabin',
        'chalet'          => 'Chalet',
        'guest_house'     => 'Guest House',
        'resort'         => 'Resort',
    ];

    $label = $type_labels[$detected_property_type] ?? 'Hotel';
    return $name . ' ' . $label;
}

/**
 * Build hotel title in standard format
 * 
 * @param string $hotel_name Hotel name
 * @param string $city City
 * @param string $country Country
 * @param int|string $year Year
 * @return string Formatted title
 */
function fn_novoton_holidays_build_hotel_title($hotel_name, $city, $country, $year): string
{
    // Apply the same display-name formatting used for product names.
    // This is idempotent: names that already contain a type keyword pass through unchanged.
    $hotel_name = fn_novoton_holidays_format_hotel_display_name(trim($hotel_name));
    
    // Build location part
    $location_parts = [];
    if (!empty($city)) {
        $location_parts[] = ucwords(strtolower(trim($city)));
    }
    if (!empty($country)) {
        $location_parts[] = ucwords(strtolower(trim($country)));
    }
    
    $location = implode(', ', $location_parts);
    
    // Build full title
    $title = $hotel_name;
    if (!empty($location)) {
        $title .= ' - ' . $location;
    }
    if (!empty($year)) {
        $title .= ' ' . $year;
    }
    
    return $title;
}

/**
 * Convert XML to array recursively
 * 
 * @param \SimpleXMLElement|string $xml XML object or string
 * @return array Converted array
 */
function fn_novoton_holidays_xml_to_array($xml): array
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
        $arr = fn_novoton_holidays_xml_to_array($value);
        
        if (isset($result[$key])) {
            if (!is_array($result[$key]) || !isset($result[$key][0])) {
                $result[$key] = [$result[$key]];
            }
            $result[$key][] = count($arr) > 0 ? $arr : (string)$value;
        } else {
            $result[$key] = count($arr) > 0 ? $arr : (string)$value;
        }
    }
    
    // Include attributes
    foreach ($xml->attributes() as $key => $value) {
        $result['@' . $key] = (string)$value;
    }
    
    return $result;
}

/**
 * Format a price for display with consistent thousands separator and optional decimal superscript
 *
 * Converts raw price (EUR) to display currency using coefficient, then formats:
 * - When round_prices is enabled: always "2.853" (integer, dot as thousands separator)
 * - Whole numbers: "2.853" (dot as thousands separator, no decimals)
 * - Prices with decimals: "7.419<sup class="price-decimal">99</sup>" (decimal part in superscript)
 *
 * @param float|string $amount      Raw price in primary currency
 * @param float|string $coefficient Currency conversion coefficient (default: 1.0)
 * @param string       $symbol      Currency symbol or code to append (default: '')
 * @return string Formatted price HTML (may contain <sup> tag)
 */
function fn_novoton_holidays_format_price($amount, $coefficient = 1.0, string $symbol = ''): string
{
    $display_price = (float)$amount * (float)$coefficient;

    // When round_prices is enabled, always display as integer (no decimals)
    $shouldRound = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::isRoundPrices();

    if ($shouldRound) {
        $formatted = number_format(round($display_price), 0, '', '.');
    } else {
        // Check if price has meaningful decimals (more than 0.005 away from nearest integer)
        $rounded = round($display_price);
        $has_decimals = abs($display_price - $rounded) >= 0.005;

        if ($has_decimals) {
            // Split into integer and decimal parts
            $integer_part = (int)floor($display_price);
            $decimal_part = (int)round(($display_price - $integer_part) * 100);
            $formatted_integer = number_format($integer_part, 0, '', '.');
            $formatted = $formatted_integer . '<sup class="price-decimal">' . str_pad((string)$decimal_part, 2, '0', STR_PAD_LEFT) . '</sup>';
        } else {
            // Format as integer with thousands separator
            $formatted = number_format($rounded, 0, '', '.');
        }
    }

    if (!empty($symbol)) {
        $formatted .= ' ' . $symbol;
    }

    return $formatted;
}
