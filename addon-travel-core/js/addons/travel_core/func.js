(function(_, $) {
    $(document).ready(function() {
        var $settings = $('#addon_options_travel_core');

        if ($settings.length) {
            // active_providers: comma-separated list -> large
            $settings.find('#addon_option_travel_core_active_providers').addClass('input-text-large');

            // cron_access_key: security token -> large
            $settings.find('#addon_option_travel_core_cron_access_key').addClass('input-text-large');

            // currency_risk_commission: percentage -> short
            $settings.find('#addon_option_travel_core_currency_risk_commission').addClass('input-text-short');
        }
    });
}(Tygh, Tygh.$));
