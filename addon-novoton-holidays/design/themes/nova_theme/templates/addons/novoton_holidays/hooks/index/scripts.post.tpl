{**
 * Novoton Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config ONLY on Novoton or shared pages to avoid
 * overwriting Sphinx config (both hooks run on every page).
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Only set provider config on Novoton pages or Novoton hotel product pages — avoids overwriting Sphinx config *}
{$_nvt_dispatch = $smarty.request.dispatch|default:''}
{if $_nvt_dispatch|substr:0:8 == 'novoton_' || ($_nvt_dispatch|substr:0:9 == 'products.' && $is_hotel_product) || $_nvt_dispatch|substr:0:8 == 'checkout' || $_nvt_dispatch == ''}
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

{* Product pages get the search-results modal helpers too: the booking
   engine swaps novoton offers INLINE into the PDP, and the swapped rows
   call openInfoModal()/closeInfoModal() from this file. Results pages keep
   loading it via their own {script} tag — CS-Cart dedupes the asset. *}
{if $_nvt_dispatch|substr:0:9 == 'products.'}
    {script src="js/addons/novoton_holidays/search-results.js"}
{/if}
