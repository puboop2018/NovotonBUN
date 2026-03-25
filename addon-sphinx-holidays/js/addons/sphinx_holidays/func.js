(function(_, $) {
    $(document).ready(function() {
        // CS-Cart appends numeric suffixes to addon setting IDs,
        // so we use attribute-starts-with selectors
        var $settings = $('[id^="content_sphinx_holidays"]');

        if (!$settings.length) {
            return;
        }

        // --- Large fields: URLs, keys, templates, domain lists ---
        var largeFields = [
            'api_base_url',
            'ignore_domains',
            'cron_access_key',
            'seo_product_name',
            'seo_page_title',
            'seo_meta_keywords',
            'seo_name_slug'
        ];

        $.each(largeFields, function(i, field) {
            $settings.find('[id^="addon_option_sphinx_holidays_' + field + '"]').addClass('input-text-large');
        });

        // api_key: password input -> large
        $settings.find('[id^="addon_option_sphinx_holidays_api_key"]').addClass('input-text-large');

        // --- Medium fields: category IDs, commission ---
        var mediumFields = [
            'commission',
            'hotels_category_id',
            'packages_category_id'
        ];

        $.each(mediumFields, function(i, field) {
            $settings.find('[id^="addon_option_sphinx_holidays_' + field + '"]').addClass('input-text-medium');
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
            $settings.find('[id^="addon_option_sphinx_holidays_' + field + '"]').addClass('input-text-short');
        });

        // --- Textarea fields: meta description, full description ---
        var textareaFields = [
            'seo_meta_description',
            'seo_full_description'
        ];

        $.each(textareaFields, function(i, field) {
            $settings.find('[id^="addon_option_sphinx_holidays_' + field + '"]').addClass('input-textarea');
        });

        // Remove redundant size="30" from SEO template inputs (CSS class controls width)
        $settings.find('[id^="addon_option_sphinx_holidays_seo_"]').removeAttr('size');
    });
}(Tygh, Tygh.$));
