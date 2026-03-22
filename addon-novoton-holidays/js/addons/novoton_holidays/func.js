(function(_, $) {
    $(document).ready(function() {
        var $settings = $('#addon_options_novoton_holidays');

        if (!$settings.length) {
            return;
        }

        // --- Section: General ---

        // api_url: domain/URL -> large
        $settings.find('#addon_option_novoton_holidays_api_url').addClass('input-text-large');

        // api_key: API token -> large
        $settings.find('#addon_option_novoton_holidays_api_key').addClass('input-text-large');

        // api_id: provider ID -> short
        $settings.find('#addon_option_novoton_holidays_api_id').addClass('input-text-short');

        // api_user: username -> medium
        $settings.find('#addon_option_novoton_holidays_api_user').addClass('input-text-medium');

        // api_password: password field (rendered by core, may already be styled)

        // commission: percentage -> short
        $settings.find('#addon_option_novoton_holidays_commission').addClass('input-text-short');

        // price_higher_threshold: percentage -> short
        $settings.find('#addon_option_novoton_holidays_price_higher_threshold').addClass('input-text-short');

        // preorder_cache_ttl: seconds -> short
        $settings.find('#addon_option_novoton_holidays_preorder_cache_ttl').addClass('input-text-short');

        // product_code_prefixes: short text -> medium
        $settings.find('#addon_option_novoton_holidays_product_code_prefixes').addClass('input-text-medium');

        // country_category_map: multi-line data -> textarea-long
        $settings.find('#addon_option_novoton_holidays_country_category_map').addClass('input-textarea-long');

        // cron_access_key: security token -> large
        $settings.find('#addon_option_novoton_holidays_cron_access_key').addClass('input-text-large');

        // --- Section: Advanced ---

        // Retry/resilience small integers -> short
        var shortFields = [
            'api_max_retries',
            'api_retry_delay_ms',
            'api_retry_multiplier',
            'circuit_breaker_threshold',
            'circuit_breaker_timeout',
            'cache_ttl_room_price',
            'cache_ttl_availability',
            'cache_ttl_search',
            'sync_interval_hotel_info_days',
            'sync_interval_price_info_days',
            'sync_interval_facilities_days',
            'cron_batch_size',
            'cron_max_execution_time',
            'slow_item_warning_ms',
            'rate_limit_requests_per_min',
            'rate_limit_bookings_per_hour'
        ];

        $.each(shortFields, function(i, field) {
            $settings.find('#addon_option_novoton_holidays_' + field).addClass('input-text-short');
        });
    });
}(Tygh, Tygh.$));
