(function(_, $) {
    $(document).ready(function() {
        var $settings = $('#addon_options_sphinx_holidays');

        if (!$settings.length) {
            return;
        }

        // --- Large fields: URLs, keys, templates, domain lists ---

        var largeFields = [
            'api_base_url',
            'ignore_domains',
            'product_category_template',
            'cron_access_key'
        ];

        $.each(largeFields, function(i, field) {
            $settings.find('#addon_option_sphinx_holidays_' + field).addClass('input-text-large');
        });

        // api_key: password input -> large
        $settings.find('#addon_option_sphinx_holidays_api_key').addClass('input-text-large');

        // --- Medium fields: category IDs, commission ---

        var mediumFields = [
            'commission',
            'hotels_category_id',
            'packages_category_id'
        ];

        $.each(mediumFields, function(i, field) {
            $settings.find('#addon_option_sphinx_holidays_' + field).addClass('input-text-medium');
        });

        // --- Short fields: small integers (counts, seconds, multipliers) ---

        var shortFields = [
            'cache_ttl_search',
            'search_poll_interval',
            'search_max_polls',
            'api_max_retries',
            'api_retry_delay_ms',
            'api_retry_multiplier',
            'circuit_breaker_threshold',
            'circuit_breaker_timeout'
        ];

        $.each(shortFields, function(i, field) {
            $settings.find('#addon_option_sphinx_holidays_' + field).addClass('input-text-short');
        });
    });
}(Tygh, Tygh.$));
