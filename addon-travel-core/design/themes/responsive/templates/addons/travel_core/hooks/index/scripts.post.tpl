{**
 * Travel Core - JavaScript Connection Hook
 *
 * Loads shared JS files used by all travel provider addons.
 * Provider-specific scripts are loaded by each provider's own hooks.
 *
 * @package TravelCore
 * @since 1.0.0
 *}

<!-- [travel_core] index/scripts.post.tpl LOADED -->
{$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:'1'}

{* Shared utilities *}
<script src="{$config.current_location}/js/addons/travel_core/utils.js?v={$cache_ver}" defer></script>

{* Multi-room booking *}
<script src="{$config.current_location}/js/addons/travel_core/multiroom-booking.js?v={$cache_ver}" defer></script>

{* Form validation scripts *}
<script src="{$config.current_location}/js/addons/travel_core/dob-validation.js?v={$cache_ver}" defer></script>
<script src="{$config.current_location}/js/addons/travel_core/booking-form-validation.js?v={$cache_ver}" defer></script>

{* ?travel_debug=1: anchor-INDEPENDENT diagnosis. The visible debug panel
   renders through the products:product_detail_bottom anchor, which some
   product templates don't fire — this index:scripts hook fires on every
   page, so the same payload always reaches the browser console (F12). *}
{if $travel_debug_enabled && $travel_debug_output}
<script>console.log('[travel_debug]', {$travel_debug_output|replace:'</':'<\/' nofilter});</script>
{/if}
