{**
 * Novoton Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config, then loads novoton-only legacy JS.
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Pass addon settings to frontend JS — provider-specific config *}
<script>
window.TravelBookingConfig = window.TravelBookingConfig || {};
window.TravelBookingConfig.provider = 'novoton';
window.TravelBookingConfig.debug = {if $addons.novoton_holidays.debug_mode == "Y"}true{else}false{/if};
window.TravelBookingConfig.ajaxRecalcUrl = '{"novoton_booking.ajax_recalculate_price"|fn_url:"C"}';
window.TravelBookingConfig.ajaxRecalcDispatch = 'novoton_booking.ajax_recalculate_price';
window.TravelBookingConfig.searchDispatch = 'novoton_booking.search';
{* Backwards compatibility *}
window.NovotonConfig = window.TravelBookingConfig;
</script>

{* Legacy jQuery booking engine (novoton-only, uses $.ceEvent / $.ceAjax) *}
<script src="{$config.current_location}/js/addons/novoton_holidays/booking_engine.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
