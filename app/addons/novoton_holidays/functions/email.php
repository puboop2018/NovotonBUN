<?php
/**
 * Novoton Holidays - Email Functions
 * 
 * Functions for email notifications and reports.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Generate CSV report from import results
 * 
 * @param array $results Array of import results
 * @param string $import_type Type of import (manual/cron)
 * @param array $summary Summary statistics
 * @return string CSV content
 */
function fn_novoton_generate_import_csv_report($results, $import_type = 'manual', $summary = [])
{
    $csv_lines = [];
    
    // Header row
    $csv_lines[] = implode(';', [
        'Hotel ID',
        'Hotel Name', 
        'Action',
        'Product ID',
        'Star Rating',
        'Description',
        'Facilities',
        'Error',
        'Timestamp'
    ]);
    
    // Data rows
    foreach ($results as $row) {
        $csv_lines[] = implode(';', [
            $row['hotel_id'] ?? '',
            '"' . str_replace('"', '""', $row['hotel_name'] ?? '') . '"',
            $row['action'] ?? '',
            $row['product_id'] ?? '',
            $row['star_rating'] ?? '',
            $row['description'] ?? '',
            $row['facilities'] ?? '',
            '"' . str_replace('"', '""', $row['error'] ?? '') . '"',
            $row['timestamp'] ?? date('Y-m-d H:i:s')
        ]);
    }
    
    return implode("\n", $csv_lines);
}

/**
 * Send cron/import report email via CS-Cart Mailer
 *
 * Uses the 'novoton_holidays_import_report' email template registered in addon.xml.
 * Works for all cron job types — hotel_list, hotel_info, room_price, etc.
 *
 * @param array  $summary     Summary statistics: added, updated, skipped, errors, duration, plus any extra keys
 * @param string $import_type Type identifier: hotel_list, hotel_info, room_price, offers_update, add_products, facilities, resinfo, manual
 * @param string $country     Country code or comma-separated list
 * @param array  $results     Optional detailed results for CSV attachment (empty = no attachment)
 * @return bool Success
 */
function fn_novoton_send_import_report_email($results, $import_type, $summary, $country = '')
{
    // Get admin email from settings
    $admin_email = Registry::get('settings.Company.company_orders_email');
    if (empty($admin_email)) {
        $admin_email = Registry::get('settings.Company.company_site_administrator');
    }
    if (empty($admin_email)) {
        $admin_email = db_get_field("SELECT email FROM ?:users WHERE user_type = 'A' AND status = 'A' ORDER BY user_id LIMIT 1");
    }
    
    if (empty($admin_email)) {
        fn_log_event('novoton', 'error', 'Cannot send cron report: no admin email found');
        return false;
    }

    // Build type label for email subject
    $type_labels = [
        'hotel_list'     => 'Hotel List Sync',
        'hotel_info'     => 'Hotel Accommodation',
        'room_price'     => 'Price Check',
        'add_products'   => 'Add Hotels as Products',
        'offers_update'  => 'Offers Update',
        'facilities'     => 'Facilities Sync',
        'resinfo'        => 'Booking Status Check',
        'check_packages' => 'Check Hotel Packages',
        'manual'         => 'Manual Import',
        'cron'           => 'Cron Sync',
    ];
    $type_label = $type_labels[$import_type] ?? ucfirst(str_replace('_', ' ', $import_type));

    // Prepare data for email template
    $email_data = [
        'import_type_label' => $type_label,
        'country' => $country ?: 'ALL',
        'date' => date('d.m.Y H:i'),
        'summary' => [
            'added'    => $summary['added'] ?? 0,
            'updated'  => $summary['updated'] ?? 0,
            'skipped'  => $summary['skipped'] ?? 0,
            'errors'   => $summary['errors'] ?? 0,
            'duration' => $summary['duration'] ?? 'N/A',
        ],
        'results_count' => count($results),
    ];

    // Generate CSV attachment only if there are detailed results
    $attachments = [];
    if (!empty($results)) {
        $csv_content = fn_novoton_generate_import_csv_report($results, $import_type, $summary);

        $filename = 'novoton_' . $import_type . '_report_' . date('Y-m-d_H-i-s') . '.csv';
        $temp_path = fn_get_files_dir_path() . 'novoton_reports/';

        if (!is_dir($temp_path)) {
            fn_mkdir($temp_path);
        }

        $file_path = $temp_path . $filename;
        file_put_contents($file_path, $csv_content);

        if (file_exists($file_path)) {
            $attachments[] = [
                'path' => $file_path,
                'name' => $filename,
            ];
        }
    }

    $send_result = false;

    try {
        /** @var \Tygh\Mailer\Mailer $mailer */
        $mailer = Tygh::$app['mailer'];

        $send_result = $mailer->send([
            'to' => $admin_email,
            'from' => 'default_company_orders_department',
            'data' => $email_data,
            'template_code' => 'novoton_holidays_import_report',
            'attachments' => $attachments,
        ], 'A', CART_LANGUAGE);

    } catch (\Exception $e) {
        fn_log_event('novoton', 'error', 'Failed to send cron report email: ' . $e->getMessage());
    }

    // Clean up old reports (once a day)
    $temp_path = fn_get_files_dir_path() . 'novoton_reports/';
    if (is_dir($temp_path)) {
        fn_novoton_cleanup_old_reports($temp_path, 7);
    }

    return $send_result;
}

/**
 * Clean up old report files
 * 
 * @param string $dir Directory path
 * @param int $days Keep files newer than this many days
 */
function fn_novoton_cleanup_old_reports($dir, $days = 7)
{
    if (!is_dir($dir)) {
        return;
    }
    
    $cutoff = time() - ($days * 86400);
    
    foreach (glob($dir . '*.csv') as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * Generate hotel features CSV for CS-Cart import
 * 
 * @return array ['success' => bool, 'file_path' => string, 'count' => int, 'error' => string]
 */
function fn_novoton_generate_hotel_features_csv()
{
    $result = [
        'success' => false,
        'file_path' => '',
        'count' => 0,
        'error' => ''
    ];
    
    try {
        // V3: Get all hotels with products (boards now in hotel_data JSON)
        $hotels = db_get_array(
            "SELECT h.hotel_id, h.hotel_name, h.hotel_type, h.product_id, h.hotel_data, p.product_code
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.product_id > 0
             ORDER BY h.hotel_name"
        );

        if (empty($hotels)) {
            $result['error'] = 'No hotels with products found';
            return $result;
        }

        // CSV header
        $csv_lines = [];
        $csv_lines[] = implode(';', [
            'Product code',
            'Language',
            'Feature: Stele',
            'Feature: Tip Masa'
        ]);

        // Star rating labels
        $star_labels = [
            'ro' => ['1 stea', '2 stele', '3 stele', '4 stele', '5 stele'],
            'en' => ['1 star', '2 stars', '3 stars', '4 stars', '5 stars']
        ];

        foreach ($hotels as $hotel) {
            $product_code = !empty($hotel['product_code']) ? $hotel['product_code'] : 'NVT-' . $hotel['hotel_id'];
            $stars = intval($hotel['hotel_type']); // "4*" -> 4, "Apart" -> 0

            // V3: Parse boards from hotel_data JSON
            $board_names = [];
            if (!empty($hotel['hotel_data'])) {
                $hotelData = json_decode($hotel['hotel_data'], true);
                if (!empty($hotelData['board'])) {
                    $boards_arr = isset($hotelData['board']['IdBoard']) ? [$hotelData['board']] : $hotelData['board'];
                    foreach ($boards_arr as $b) {
                        $code = is_array($b) ? ($b['IdBoard'] ?? $b['Board'] ?? '') : (string)$b;
                        if (!empty($code)) {
                            $board_names[] = fn_novoton_format_board_name($code);
                        }
                    }
                }
            }
            $boards_str = implode(',', array_unique($board_names));
            
            // Romanian row
            $star_ro = ($stars >= 1 && $stars <= 5) ? $star_labels['ro'][$stars - 1] : '';
            $csv_lines[] = implode(';', [
                $product_code,
                'ro',
                $star_ro,
                $boards_str
            ]);
            
            // English row
            $star_en = ($stars >= 1 && $stars <= 5) ? $star_labels['en'][$stars - 1] : '';
            $csv_lines[] = implode(';', [
                $product_code,
                'en',
                $star_en,
                $boards_str
            ]);
            
            $result['count']++;
        }
        
        // Save to file in novoton_reports directory
        $filename = 'novoton_hotel_features_' . date('Ymd_His') . '.csv';
        $dir = fn_get_files_dir_path() . 'novoton_reports/';

        if (!is_dir($dir)) {
            fn_mkdir($dir);
        }

        $file_path = $dir . $filename;
        $csv_content = implode("\n", $csv_lines);
        
        if (file_put_contents($file_path, $csv_content)) {
            $result['success'] = true;
            $result['file_path'] = $file_path;
            $result['filename'] = $filename;
        } else {
            $result['error'] = 'Failed to write CSV file';
        }
        
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}
