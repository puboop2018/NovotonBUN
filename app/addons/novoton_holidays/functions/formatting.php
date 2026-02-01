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
 * @param string $roomId Room code (DBL, SGL, etc.)
 * @return string Formatted room type
 */
function fn_novoton_format_room_type($roomId)
{
    // Decode URL-encoded plus signs (use rawurldecode to preserve + as-is)
    $roomId = str_replace(['%2b', '%2B'], '+', $roomId);
    $roomId = rawurldecode($roomId);
    $roomId = trim($roomId);

    // Fix: ensure + sign between numbers (e.g., "DBL 2 1" -> "DBL 2+1")
    $roomId = preg_replace('/(\d)\s+(\d)/', '$1+$2', $roomId);
    
    // Parse room code pattern: "DBL 2+1" or "DBL 2+1 DELUXE" etc.
    $parts = preg_split('/[\s\+]+/', $roomId);
    $base = strtoupper($parts[0] ?? '');
    
    // Room type mapping
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

    $room_name = $room_map[$base] ?? null;

    // Fallback: handle N-BR pattern (e.g., "4-BR", "5-BR") dynamically
    if ($room_name === null && preg_match('/^(\d+)-BR$/i', $base, $brMatch)) {
        $room_name = 'Apartament ' . $brMatch[1] . ' Dormitoare';
    }

    if ($room_name === null) {
        $room_name = $base;
    }
    
    // Build occupancy string
    $adults = isset($parts[1]) ? intval($parts[1]) : 0;
    $children = isset($parts[2]) ? intval($parts[2]) : 0;
    
    if ($adults > 0) {
        $occupancy = " ({$adults}";
        if ($children > 0) {
            $occupancy .= "+{$children}";
        }
        $occupancy .= ")";
        $room_name .= $occupancy;
    }
    
    // Append additional descriptors (DELUXE, SEA VIEW, etc.)
    if (count($parts) > 3) {
        $extra = array_slice($parts, 3);
        $room_name .= ' ' . implode(' ', $extra);
    }
    
    return $room_name;
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
        // Handle both raw XML and CDATA-wrapped XML
        $xml_string = trim($xml_string);
        
        // If it doesn't start with <, try to extract from CDATA
        if (strpos($xml_string, '<') !== 0) {
            if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $xml_string, $matches)) {
                $xml_string = $matches[1];
            }
        }
        
        // Parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);
        
        if ($xml === false) {
            // Try wrapping in root element
            $xml = simplexml_load_string('<root>' . $xml_string . '</root>');
        }
        
        if ($xml === false) {
            return [];
        }
        
        // Try Novoton format first: <Percent tillDate="...">value</Percent>
        $percentRules = $xml->xpath('//Percent') ?: [];
        
        if (!empty($percentRules)) {
            foreach ($percentRules as $rule) {
                $percent = (float)(string)$rule;
                $tillDate = (string)($rule['tillDate'] ?? $rule['TillDate'] ?? '');
                
                if ($percent > 0) {
                    $terms[] = [
                        'percent' => $percent,
                        'date' => $tillDate,
                        'is_on_booking' => empty($tillDate),
                    ];
                }
            }
        } else {
            // Fallback: Try generic PaymentRule format
            $paymentRules = $xml->xpath('//PaymentRule') ?: $xml->xpath('//paymentRule') ?: [];
            
            foreach ($paymentRules as $rule) {
                $term = [
                    'percent' => (float)($rule['PerCent'] ?? $rule['percent'] ?? (string)$rule ?? 0),
                    'date' => (string)($rule['DateTo'] ?? $rule['tillDate'] ?? $rule['to'] ?? ''),
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
        $xml_string = trim($xml_string);
        
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
        
        if ($xml === false) {
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
            $formatted_date = date('d.m.Y', strtotime($date));
            $lines[] = "{$percent}% până la {$formatted_date}";
        } elseif (!empty($term['is_on_booking'])) {
            $lines[] = "{$percent}% la rezervare";
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
    
    foreach ($terms as $term) {
        $value = $term['value'] ?? 0;
        $type = $term['type'] ?? 'Percent';
        $tillDate = $term['till_date'] ?? '';
        
        if ($value === 'FREE' || $value == 0) {
            if (!empty($tillDate)) {
                $formatted_date = date('d.m.Y', strtotime($tillDate));
                $lines[] = "Până la {$formatted_date}: anulare gratuită";
            } else {
                $lines[] = "Anulare gratuită";
            }
        } else {
            $formatted_date = !empty($tillDate) ? date('d.m.Y', strtotime($tillDate)) : '';
            
            if ($type === 'Over Nights' || $type === 'Overnights') {
                $nights = (int)$value;
                $night_word = ($nights == 1) ? 'noapte' : 'nopți';
                if (!empty($formatted_date)) {
                    $lines[] = "Până la {$formatted_date}: penalizare {$nights} {$night_word}";
                } else {
                    $lines[] = "Penalizare {$nights} {$night_word}";
                }
            } else {
                // Percent type
                $percent = number_format((float)$value, 0);
                if (!empty($formatted_date)) {
                    $lines[] = "Până la {$formatted_date}: penalizare {$percent}%";
                } else {
                    $lines[] = "Penalizare {$percent}%";
                }
            }
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
