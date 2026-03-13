{**
 * Novoton Holidays - JavaScript Connection Hook
 *
 * This hook properly connects addon JS files following CS-Cart conventions.
 * Location: design/themes/responsive/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl
 *
 * All scripts are loaded from js/addons/novoton_holidays/ directory.
 * CS-Cart will automatically include this when rendering pages.
 *
 * Uses NOVOTON_CACHE_VER for cache-busting: changes automatically when
 * JS files are modified, so browser caches are busted on every deploy.
 *}

{* Pass addon settings to frontend JS *}
<script>
window.NovotonConfig = window.NovotonConfig || {};
window.NovotonConfig.debug = {if $addons.novoton_holidays.debug_mode == "Y"}true{else}false{/if};
window.NovotonConfig.ajaxRecalcUrl = '{"novoton_booking.ajax_recalculate_price"|fn_url:"C"}';
</script>

{* Core booking functionality - always load *}
<script src="{$config.current_location}/js/addons/novoton_holidays/utils.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
<script src="{$config.current_location}/js/addons/novoton_holidays/booking_engine.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>

{* Multi-room booking *}
<script src="{$config.current_location}/js/addons/novoton_holidays/multiroom-booking.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>

{* Form validation scripts *}
<script src="{$config.current_location}/js/addons/novoton_holidays/dob-validation.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
<script src="{$config.current_location}/js/addons/novoton_holidays/booking-form-validation.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
