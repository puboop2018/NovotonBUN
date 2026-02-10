<?php
/**
 * Novoton Holidays - Alternative Requests Admin Controller
 * Manages hotel_request and alternative_RS functionality
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Check alternatives for a request
    if ($mode === 'check_alternatives' || $mode === 'alternative_rs') {
        $request_id = intval($_REQUEST['request_id'] ?? 0);
        
        if ($request_id > 0) {
            $request = db_get_row("SELECT * FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
            
            if ($request && !empty($request['novoton_request_id'])) {
                // Load API
                $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
                if (file_exists($src_dir . 'NovotonApi.php')) {
                    require_once($src_dir . 'NovotonApi.php');
                }
                
                $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
                $response = $api->getAlternatives($request['novoton_request_id']);
                
                if ($response && isset($response->alternative)) {
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'res_num' => (string)($alt->ResNum ?? ''),
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->HotelName ?? ''),
                            'package_name' => (string)($alt->PackageName ?? ''),
                            'room_id' => (string)($alt->IdRoom ?? ''),
                            'check_in' => (string)($alt->CheckIn ?? ''),
                            'check_out' => (string)($alt->CheckOut ?? ''),
                            'board_id' => (string)($alt->IdBoard ?? ''),
                            'quota' => (string)($alt->Quota ?? ''),
                            'total' => (string)($alt->Total ?? ''),
                            'currency' => (string)($alt->Currency ?? 'EUR')
                        ];
                    }
                    
                    db_query(
                        "UPDATE ?:novoton_alternative_requests SET alternatives_data = ?s, status = 'alternatives_found', updated_at = NOW() WHERE request_id = ?i",
                        json_encode($alternatives),
                        $request_id
                    );
                    
                    fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_found', ['[count]' => count($alternatives)]));
                } else {
                    fn_set_notification('W', __('warning'), __('novoton_holidays.no_alternatives_yet'));
                }
            } else {
                fn_set_notification('E', __('error'), __('novoton_holidays.no_novoton_request_id'));
            }
        }
        
        return [CONTROLLER_STATUS_OK, 'novoton_alternatives.manage'];
    }
    
    // Notify customer about alternatives
    if ($mode === 'notify_customer') {
        $request_id = intval($_REQUEST['request_id'] ?? 0);
        
        if ($request_id > 0) {
            $request = db_get_row("SELECT * FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
            
            if ($request && !empty($request['alternatives_data'])) {
                $alternatives = json_decode($request['alternatives_data'], true);
                
                if (!empty($alternatives)) {
                    // Send email to customer
                    $mail_data = [
                        'request' => $request,
                        'alternatives' => $alternatives,
                        'hotel_name' => $request['hotel_name'],
                        'check_in' => $request['check_in'],
                        'check_out' => $request['check_out']
                    ];
                    
                    $mailer = Tygh::$app['mailer'];
                    $result = $mailer->send([
                        'to' => $request['contact_email'],
                        'from' => 'default_company_orders_department',
                        'data' => $mail_data,
                        'template_code' => 'novoton_alternatives_available',
                        'tpl' => 'addons/novoton_holidays/email/alternatives_available.tpl'
                    ], 'A');
                    
                    if ($result) {
                        db_query(
                            "UPDATE ?:novoton_alternative_requests SET status = 'notified', notified_at = NOW() WHERE request_id = ?i",
                            $request_id
                        );
                        fn_set_notification('N', __('notice'), __('novoton_holidays.customer_notified'));
                    } else {
                        fn_set_notification('E', __('error'), __('novoton_holidays.email_send_failed'));
                    }
                }
            }
        }
        
        return [CONTROLLER_STATUS_OK, 'novoton_alternatives.manage'];
    }
    
    // Delete request
    if ($mode === 'delete') {
        $request_id = intval($_REQUEST['request_id'] ?? 0);
        
        if ($request_id > 0) {
            db_query("DELETE FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
            fn_set_notification('N', __('notice'), __('novoton_holidays.request_deleted'));
        }
        
        return [CONTROLLER_STATUS_OK, 'novoton_alternatives.manage'];
    }
    
    // Bulk check all pending requests
    if ($mode === 'check_all_pending') {
        $pending = db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests WHERE status = 'pending' AND novoton_request_id != '' AND novoton_request_id IS NOT NULL"
        );
        
        if (!empty($pending)) {
            $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            }
            
            $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
            $found = 0;
            
            foreach ($pending as $request) {
                $response = $api->getAlternatives($request['novoton_request_id']);
                
                if ($response && isset($response->alternative)) {
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'res_num' => (string)($alt->ResNum ?? ''),
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->HotelName ?? ''),
                            'room_id' => (string)($alt->IdRoom ?? ''),
                            'check_in' => (string)($alt->CheckIn ?? ''),
                            'check_out' => (string)($alt->CheckOut ?? ''),
                            'total' => (string)($alt->Total ?? '')
                        ];
                    }
                    
                    if (!empty($alternatives)) {
                        db_query(
                            "UPDATE ?:novoton_alternative_requests SET alternatives_data = ?s, status = 'alternatives_found' WHERE request_id = ?i",
                            json_encode($alternatives),
                            $request['request_id']
                        );
                        $found++;
                    }
                }
                
                usleep(200000); // 200ms delay between requests
            }
            
            fn_set_notification('N', __('notice'), __('novoton_holidays.bulk_check_complete', ['[checked]' => count($pending), '[found]' => $found]));
        } else {
            fn_set_notification('I', __('information'), __('novoton_holidays.no_pending_requests'));
        }
        
        return [CONTROLLER_STATUS_OK, 'novoton_alternatives.manage'];
    }
}

// View/manage alternative requests
if ($mode === 'manage') {
    
    $items_per_page = Registry::get('settings.Appearance.admin_elements_per_page');
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    
    // Filters
    $status_filter = $_REQUEST['status'] ?? '';
    $search_email = $_REQUEST['email'] ?? '';
    
    $where = [];
    $params = [];
    
    if (!empty($status_filter)) {
        $where[] = "status = ?s";
        $params[] = $status_filter;
    }
    
    if (!empty($search_email)) {
        $where[] = "contact_email LIKE ?l";
        $params[] = '%' . $search_email . '%';
    }
    
    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Get total count
    $total_items = db_get_field(
        "SELECT COUNT(*) FROM ?:novoton_alternative_requests " . $where_sql,
        ...$params
    );
    
    // Get requests with pagination
    $offset = ($page - 1) * $items_per_page;
    $requests = db_get_array(
        "SELECT * FROM ?:novoton_alternative_requests " . $where_sql . " ORDER BY created_at DESC LIMIT ?i, ?i",
        ...array_merge($params, [$offset, $items_per_page])
    );
    
    // Decode alternatives data
    foreach ($requests as &$req) {
        if (!empty($req['alternatives_data'])) {
            $req['alternatives'] = json_decode($req['alternatives_data'], true);
        }
    }
    
    // Get status counts for tabs
    $status_counts = db_get_hash_single_array(
        "SELECT status, COUNT(*) as cnt FROM ?:novoton_alternative_requests GROUP BY status",
        ['status', 'cnt']
    );
    
    Tygh::$app['view']->assign('requests', $requests);
    Tygh::$app['view']->assign('status_counts', $status_counts);
    Tygh::$app['view']->assign('status_filter', $status_filter);
    Tygh::$app['view']->assign('search_email', $search_email);
    Tygh::$app['view']->assign('total_items', $total_items);
    Tygh::$app['view']->assign('items_per_page', $items_per_page);
    Tygh::$app['view']->assign('page', $page);
}

// View single request details
if ($mode === 'view') {
    $request_id = intval($_REQUEST['request_id'] ?? 0);
    
    if ($request_id > 0) {
        $request = db_get_row("SELECT * FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
        
        if ($request) {
            if (!empty($request['alternatives_data'])) {
                $request['alternatives'] = json_decode($request['alternatives_data'], true);
            }
            if (!empty($request['api_response'])) {
                $request['api_response_decoded'] = json_decode($request['api_response'], true);
            }
            
            Tygh::$app['view']->assign('request', $request);
        } else {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
    } else {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
}
