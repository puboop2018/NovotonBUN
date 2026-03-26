(function(_, $) {
    $(document).ready(function() {
        // CS-Cart appends numeric suffixes to addon setting IDs,
        // so we use attribute-starts-with selectors
        var $settings = $('[id^="content_travel_core"]');

        if (!$settings.length) {
            return;
        }

        // cron_access_key: security token -> large
        $settings.find('[id^="addon_option_travel_core_cron_access_key"]').addClass('input-text-large');

        // currency_risk_commission: percentage -> short
        $settings.find('[id^="addon_option_travel_core_currency_risk_commission"]').addClass('input-text-short');
    });
}(Tygh, Tygh.$));
