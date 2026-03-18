{**
 * Sphinx Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config ONLY on Sphinx pages to avoid
 * overwriting Novoton config (both hooks run on every page).
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Only set provider config on Sphinx pages — avoids overwriting Novoton config *}
{$_sph_dispatch = $smarty.request.dispatch|default:''}
{if $_sph_dispatch|substr:0:7 == 'sphinx_'}
<script>
window.TravelBookingConfig = window.TravelBookingConfig || {};
window.TravelBookingConfig.provider = 'sphinx';
window.TravelBookingConfig.debug = {if $addons.sphinx_holidays.debug_logging == "Y"}true{else}false{/if};
window.TravelBookingConfig.ajaxRecalcUrl = '{"sphinx_booking.ajax_recalculate_price"|fn_url:"C"}';
window.TravelBookingConfig.ajaxRecalcDispatch = 'sphinx_booking.ajax_recalculate_price';
</script>
{/if}
