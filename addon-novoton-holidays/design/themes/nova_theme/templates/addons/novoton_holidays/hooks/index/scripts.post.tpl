{**
 * Novoton Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config ONLY on Novoton or shared pages to avoid
 * overwriting Sphinx config (both hooks run on every page).
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Only set provider config on Novoton or shared pages — avoids overwriting Sphinx config *}
{$_nvt_dispatch = $smarty.request.dispatch|default:''}
{if $_nvt_dispatch|substr:0:8 == 'novoton_' || $_nvt_dispatch|substr:0:9 == 'products.' || $_nvt_dispatch|substr:0:8 == 'checkout' || $_nvt_dispatch == ''}
<script>
window.TravelBookingConfig = window.TravelBookingConfig || {};
window.TravelBookingConfig.provider = 'novoton';
window.TravelBookingConfig.debug = {if $addons.novoton_holidays.debug_mode == "Y"}true{else}false{/if};
window.TravelBookingConfig.ajaxRecalcUrl = '{"novoton_booking.ajax_recalculate_price"|fn_url:"C"}';
window.TravelBookingConfig.ajaxRecalcDispatch = 'novoton_booking.ajax_recalculate_price';
{* Backwards compatibility *}
window.NovotonConfig = window.TravelBookingConfig;
</script>
{/if}

{* Legacy jQuery booking engine (novoton-only, uses $.ceEvent / $.ceAjax) *}
<script src="{$config.current_location}/js/addons/novoton_holidays/booking_engine.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
