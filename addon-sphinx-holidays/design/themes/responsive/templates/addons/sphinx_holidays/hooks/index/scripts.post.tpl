{**
 * Sphinx Holidays - JavaScript Connection Hook
 *
 * Sets provider-specific config ONLY on Sphinx pages to avoid
 * overwriting Novoton config (both hooks run on every page).
 * Shared JS (utils, multiroom, dob-validation, booking-form-validation)
 * is loaded by travel_core's hooks/index/scripts.post.tpl.
 *}

{* Only set provider config on Sphinx pages or Sphinx hotel product pages — avoids overwriting Novoton config *}
{$_sph_dispatch = $smarty.request.dispatch|default:''}
{if $_sph_dispatch|substr:0:7 == 'sphinx_' || ($_sph_dispatch|substr:0:9 == 'products.' && $is_sphinx_hotel)}
<script>
window.TravelBookingConfig = window.TravelBookingConfig || {};
window.TravelBookingConfig.provider = 'sphinx';
window.TravelBookingConfig.debug = {if $addons.sphinx_holidays.debug_logging == "Y"}true{else}false{/if};
window.TravelBookingConfig.ajaxRecalcUrl = '{"sphinx_booking.ajax_recalculate_price"|fn_url:"C"}';
window.TravelBookingConfig.ajaxRecalcDispatch = 'sphinx_booking.ajax_recalculate_price';
</script>
{/if}

{* Product pages get the poll + terms-modal behavior too: the booking engine
   swaps sphinx offers INLINE into the PDP and re-arms polling via the
   travel:results-swapped event this file listens for. Results pages keep
   loading it via their own {script} tag — CS-Cart dedupes the asset. *}
{if $_sph_dispatch|substr:0:9 == 'products.'}
    {script src="js/addons/sphinx_holidays/search-results.js"}
{/if}
