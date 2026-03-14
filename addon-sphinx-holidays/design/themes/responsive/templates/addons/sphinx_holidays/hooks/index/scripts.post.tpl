{**
 * Sphinx Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config for the shared travel_core JS.
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Pass Sphinx-specific config to frontend JS *}
<script>
window.TravelBookingConfig = window.TravelBookingConfig || {};
window.TravelBookingConfig.provider = 'sphinx';
window.TravelBookingConfig.debug = {if $addons.sphinx_holidays.debug_logging == "Y"}true{else}false{/if};
window.TravelBookingConfig.ajaxRecalcUrl = '{"sphinx_booking.ajax_recalculate_price"|fn_url:"C"}';
window.TravelBookingConfig.ajaxRecalcDispatch = 'sphinx_booking.ajax_recalculate_price';
window.TravelBookingConfig.searchDispatch = 'sphinx_booking.search';
</script>
