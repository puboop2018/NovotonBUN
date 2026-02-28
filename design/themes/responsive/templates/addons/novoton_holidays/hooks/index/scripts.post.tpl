{**
 * Novoton Holidays - JavaScript Connection Hook
 * 
 * This hook properly connects addon JS files following CS-Cart conventions.
 * Location: design/themes/responsive/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl
 * 
 * All scripts are loaded from js/addons/novoton_holidays/ directory.
 * CS-Cart will automatically include this when rendering pages.
 *}

{* Pass addon settings to frontend JS *}
<script>
window.NovotonConfig = window.NovotonConfig || {};
window.NovotonConfig.debug = {if $addons.novoton_holidays.debug_mode == "Y"}true{else}false{/if};
</script>

{* Core booking functionality - always load *}
{script src="js/addons/novoton_holidays/utils.js"}
{script src="js/addons/novoton_holidays/booking_engine.js"}

{* Multi-room booking *}
{script src="js/addons/novoton_holidays/multiroom-booking.js"}

{* Form validation scripts *}
{script src="js/addons/novoton_holidays/dob-validation.js"}
{script src="js/addons/novoton_holidays/booking-form-validation.js"}
