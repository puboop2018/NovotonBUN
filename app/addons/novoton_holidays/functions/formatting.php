<?php
/**
 * Novoton Holidays - Formatting Functions
 * 
 * Functions for formatting room types, board names, terms, etc.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
function fn_novoton_format_date($date)
{
    if (empty($date)) {
        return '';
    }

    // Convert to timestamp if string
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    if (!$timestamp) {
        return $date;
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
 * @param string $boardId Board code (AI, HB, FB, etc.)
 * @return string Formatted board name
 */
function fn_novoton_format_board_name($boardId)
{
    $boardId = trim(strtoupper($boardId));
    
    $board_map = [
        'AI' => 'All Inclusive',
        'ALL INCL' => 'All Inclusive',
        'ALL INCLUSIVE' => 'All Inclusive',
        'ALLINC' => 'All Inclusive',
        'UAI' => 'Ultra All Inclusive',
        'ULTRA ALL INCL' => 'Ultra All Inclusive',
        'ULTRA ALL INCLUSIVE' => 'Ultra All Inclusive',
        'FB' => 'Full Board',
        'FB+' => 'Full Board Plus',
        'FULL BOARD' => 'Full Board',
        'HB' => 'Half Board',
        'HB+' => 'Half Board Plus',
        'HALF BOARD' => 'Half Board',
        'BB' => 'Bed & Breakfast',
        'BED AND BREAKFAST' => 'Bed & Breakfast',
        'RO' => 'Room Only',
        'ROOM ONLY' => 'Room Only',
        'SC' => 'Self Catering',
        'SELF CATERING' => 'Self Catering',
    ];
    
    return $board_map[$boardId] ?? $boardId;
}

/**
 * Format room type code for display
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
function fn_novoton_format_room_type($roomId, $roomType = '')
{
    // Decode URL-encoded plus signs
    $roomId = str_replace(['%2b', '%2B'], '+', $roomId);
    $roomId = rawurldecode($roomId);
    $roomId = trim($roomId);

    // Detect if roomType or roomId is already formatted (e.g., "Camera Dubla (DBL 2+1)")
    // to prevent double formatting
    $formatted_pattern = '/^(Camera|Apartament|Studio|Suita|Vila|Bungalou|Maisoneta|Penthouse|Junior Suita)\s.*\(.+\)$/i';
    if (!empty($roomType) && preg_match($formatted_pattern, $roomType)) {
        return $roomType;
    }
    if (preg_match($formatted_pattern, $roomId)) {
        return $roomId;
    }

    // Room type mapping (used for both hotelinfo Type and IdRoom base code)
    $room_map = [
        'SGL' => 'Camera Single',
        'DBL' => 'Camera Dubla',
        'TWIN' => 'Camera Twin',
        'TWN' => 'Camera Twin',
        'TRP' => 'Camera Tripla',
        'TRPL' => 'Camera Tripla',
        'TRIPLE' => 'Camera Tripla',
        'QUA' => 'Camera Cvadrupla',
        'QUAD' => 'Camera Cvadrupla',
        'FAM' => 'Camera Familie',
        'FAMILY' => 'Camera Familie',
        'STUDIO' => 'Studio',
        'STD' => 'Studio',
        'APT' => 'Apartament',
        'APP' => 'Apartament',
        'APARTMENT' => 'Apartament',
        'SUITE' => 'Suita',
        'STE' => 'Suita',
        'JRSUITE' => 'Junior Suita',
        'JST' => 'Junior Suita',
        'JUNIOR' => 'Junior Suita',
        'VILLA' => 'Vila',
        'VLA' => 'Vila',
        'BUNGALOW' => 'Bungalou',
        'BNG' => 'Bungalou',
        'MAISONETTE' => 'Maisoneta',
        'MAI' => 'Maisoneta',
        'PENTHOUSE' => 'Penthouse',
        'PH' => 'Penthouse',
        'DLX' => 'Camera Deluxe',
        'DELUXE' => 'Camera Deluxe',
        'SUP' => 'Camera Superior',
        'SUPERIOR' => 'Camera Superior',
        '1-BR' => 'Apartament 1 Dormitor',
        '2-BR' => 'Apartament 2 Dormitoare',
        '3-BR' => 'Apartament 3 Dormitoare',
    ];

    // If hotelinfo Type is provided, use it: "{Type display name} ({IdRoom})"
    if (!empty($roomType)) {
        $typeKey = strtoupper(trim($roomType));
        $typeName = $room_map[$typeKey] ?? $roomType;
        return $typeName . ' (' . $roomId . ')';
    }

    // Fallback: parse IdRoom code when no hotelinfo Type available
    // Normalize: "DBL 2 1" -> "DBL 2+1"
    $roomIdNorm = preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomId);
    $parts = preg_split('/[\s]+/', $roomIdNorm, 2);
    $base = strtoupper($parts[0] ?? '');

    $room_name = $room_map[$base] ?? null;

    // Fallback: handle N-BR pattern (e.g., "4-BR", "5-BR") dynamically
    if ($room_name === null && preg_match('/^(\d+)-BR$/i', $base, $brMatch)) {
        $room_name = 'Apartament ' . $brMatch[1] . ' Dormitoare';
    }

    if ($room_name === null) {
        // Unknown base code — return original roomId as-is
        return $roomId;
    }

    // Return: "Camera Dubla (DBL 2+1)" — translated name + original code
    return $room_name . ' (' . $roomIdNorm . ')';
}

/**
 * Normalize room code - ensures + sign between occupancy numbers
 * E.g., "DBL 2 1 DELUXE" -> "DBL 2+1 DELUXE"
 * 
 * @param string $roomCode Room code from API
 * @return string Normalized room code
 */
function fn_novoton_normalize_room_code($roomCode)
{
    $roomCode = str_replace(['%2b', '%2B'], '+', $roomCode);
    $roomCode = rawurldecode($roomCode);
    $roomCode = trim($roomCode);
    // Ensure + sign between consecutive digits with space
    $roomCode = preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomCode);
    return $roomCode;
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
function fn_novoton_normalize_resort_name($name)
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
function fn_novoton_parse_xml_string($xml_string)
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
    $xml = simplexml_load_string($xml_string);

    if ($xml === false) {
        $xml = simplexml_load_string('<root>' . $xml_string . '</root>');
    }

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
function fn_novoton_parse_payment_terms($xml_string)
{
    if (empty($xml_string)) {
        return [];
    }

    $terms = [];

    try {
        $xml = fn_novoton_parse_xml_string($xml_string);
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
                        'date_formatted' => !empty($tillDate) ? fn_novoton_format_date($tillDate) : '',
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
                    'date_formatted' => !empty($rawDate) ? fn_novoton_format_date($rawDate) : '',
                    'is_on_booking' => false,
                ];

                if ($term['percent'] > 0) {
                    $terms[] = $term;
                }
            }
        }
    } catch (\Exception $e) {
        // Silently fail on parse errors
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
function fn_novoton_parse_cancellation_terms($xml_string, $check_in = '')
{
    if (empty($xml_string)) {
        return [];
    }
    
    $terms = [];

    try {
        $xml = fn_novoton_parse_xml_string($xml_string);
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
                if ($value == 0) {
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
        // Silently fail
    }
    
    return $terms;
}

/**
 * Format payment terms with calculated amounts for display
 *
 * Format: "10% (150.00€) - due by 05.03.2026"
 *
 * @param string $xml_string Raw XML string with payment terms
 * @param float $total_price Total booking price to calculate amounts from
 * @param string $currency_code Currency code (default: EUR)
 * @return string Formatted payment terms with amounts
 */
function fn_novoton_format_payment_terms_with_amounts($xml_string, $total_price, $currency_code = 'EUR')
{
    $terms = fn_novoton_parse_payment_terms($xml_string);

    if (empty($terms)) {
        return '';
    }

    // Get rounding setting from addon
    $round_prices = Registry::get('addons.novoton_holidays.round_prices');
    $round_prices = ($round_prices === 'Y' || $round_prices === true);

    $lines = [];

    foreach ($terms as $term) {
        $percent = isset($term['percent']) ? (float)$term['percent'] : 0;
        $date = $term['date'] ?? '';

        // Calculate amount from percentage
        $amount = ($percent / 100) * (float)$total_price;

        // Round if setting is enabled
        if ($round_prices) {
            $amount = round($amount);
        }

        // Format amount using CS-Cart's currency formatting
        $formatted_amount = fn_format_price($amount, $currency_code);
        $percent_display = number_format($percent, 0);

        if (!empty($date)) {
            $formatted_date = fn_novoton_format_date($date);
            // "[percent]% ([amount]) - due by [date]" / "[percent]% ([amount]) - până la [date]"
            $lines[] = __('novoton_holidays.payment_percent_amount_until', [
                '[percent]' => $percent_display,
                '[amount]' => $formatted_amount,
                '[date]' => $formatted_date
            ]);
        } elseif (!empty($term['is_on_booking'])) {
            // "[percent]% ([amount]) on booking" / "[percent]% ([amount]) la rezervare"
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
function fn_novoton_format_payment_terms($xml_string)
{
    $terms = fn_novoton_parse_payment_terms($xml_string);

    if (empty($terms)) {
        return '';
    }

    $lines = [];

    foreach ($terms as $term) {
        $percent = isset($term['percent']) ? number_format($term['percent'], 0) : '0';
        $date = $term['date'] ?? '';

        if (!empty($date)) {
            $formatted_date = fn_novoton_format_date($date);
            // "[percent]% until [date]" / "[percent]% până la [date]"
            $lines[] = __('novoton_holidays.payment_percent_until', [
                '[percent]' => $percent,
                '[date]' => $formatted_date
            ]);
        } elseif (!empty($term['is_on_booking'])) {
            // "[percent]% on booking" / "[percent]% la rezervare"
            $lines[] = __('novoton_holidays.payment_percent_on_booking', ['[percent]' => $percent]);
        } else {
            $lines[] = "{$percent}%";
        }
    }

    return implode("\n", $lines);
}

/**
 * Format cancellation terms for display
 * 
 * @param string $xml_string Raw XML string
 * @param string $check_in Check-in date
 * @return string Formatted HTML
 */
function fn_novoton_format_cancellation_terms($xml_string, $check_in = '')
{
    $terms = fn_novoton_parse_cancellation_terms($xml_string, $check_in);

    if (empty($terms)) {
        return '';
    }

    $lines = [];
    $prev_till_date = null;

    foreach ($terms as $idx => $term) {
        $value = $term['value'] ?? 0;
        $type = $term['type'] ?? 'Percent';
        $tillDate = $term['till_date'] ?? '';
        $is_last = ($idx === count($terms) - 1);

        if ($value === 'FREE' || $value == 0) {
            // Free cancellation tier
            if (!empty($tillDate)) {
                // "Free cancellation before [date]" / "Anulare gratuită până la [date]"
                $lines[] = __('novoton_holidays.cancel_free_before', ['[date]' => fn_novoton_format_date($tillDate)]);
            } else {
                // "Free cancellation" / "Anulare gratuită"
                $lines[] = __('novoton_holidays.cancel_free');
            }
            $prev_till_date = $tillDate;
        } elseif ($is_last && $type === 'Percent' && (float)$value >= 100) {
            // Final 100% tier = No Show
            // "No show: 100% penalty" / "Neprezentare: penalizare 100%"
            $lines[] = __('novoton_holidays.cancel_no_show');
        } else {
            // Middle tier: show date range from [prev_tillDate + 1 day] to [current_tillDate]
            $penalty_str = '';
            if ($type === 'Over Nights' || $type === 'Overnights') {
                $nights = (int)$value;
                // "X night(s) penalty" / "penalizare X noapte/nopți"
                $penalty_str = __('novoton_holidays.cancel_nights_penalty', ['[nights]' => $nights]);
            } else {
                $percent = number_format((float)$value, 0);
                // "X% penalty" / "penalizare X%"
                $penalty_str = __('novoton_holidays.cancel_percent_penalty', ['[percent]' => $percent]);
            }

            if (!empty($prev_till_date) && !empty($tillDate)) {
                $from_str = fn_novoton_format_date(strtotime($prev_till_date . ' +1 day'));
                $to_str = fn_novoton_format_date($tillDate);
                // "Between [from] - [to]: [penalty]" / "Între [from] - [to]: [penalty]"
                $lines[] = __('novoton_holidays.cancel_between_dates', [
                    '[from]' => $from_str,
                    '[to]' => $to_str,
                    '[penalty]' => $penalty_str
                ]);
            } elseif (!empty($tillDate)) {
                // "Until [date]: [penalty]" / "Până la [date]: [penalty]"
                $lines[] = __('novoton_holidays.cancel_until_date', [
                    '[date]' => fn_novoton_format_date($tillDate),
                    '[penalty]' => $penalty_str
                ]);
            } else {
                $lines[] = ucfirst($penalty_str);
            }

            $prev_till_date = $tillDate;
        }
    }

    return implode("\n", $lines);
}

/**
 * Get free cancellation date from terms
 * 
 * @param string $xml_string Raw XML string
 * @return string|null Date string or null
 */
function fn_novoton_get_free_cancellation_date($xml_string)
{
    $terms = fn_novoton_parse_cancellation_terms($xml_string);
    
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
 * Build hotel title in standard format
 * 
 * @param string $hotel_name Hotel name
 * @param string $city City
 * @param string $country Country
 * @param int|string $year Year
 * @return string Formatted title
 */
function fn_novoton_build_hotel_title($hotel_name, $city, $country, $year)
{
    // Clean and Title Case the hotel name
    $hotel_name = trim($hotel_name);
    $hotel_name = mb_convert_case($hotel_name, MB_CASE_TITLE, 'UTF-8');
    
    // Fix common patterns after Title Case
    $hotel_name = str_replace([' & ', ' And '], ' & ', $hotel_name);
    
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
function fn_novoton_xml_to_array($xml)
{
    if (is_string($xml)) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml);
        if ($xml === false) {
            return [];
        }
    }
    
    $result = [];
    
    foreach ($xml->children() as $key => $value) {
        $arr = fn_novoton_xml_to_array($value);
        
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
