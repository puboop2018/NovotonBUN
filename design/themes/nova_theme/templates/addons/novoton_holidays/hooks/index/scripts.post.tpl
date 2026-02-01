{**
 * Novoton Holidays - JavaScript Connection Hook
 * 
 * This hook properly connects addon JS files following CS-Cart conventions.
 * Location: design/themes/responsive/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl
 * 
 * All scripts are loaded from js/addons/novoton_holidays/ directory.
 * CS-Cart will automatically include this when rendering pages.
 *}

{* Core booking functionality - always load *}
{script src="js/addons/novoton_holidays/utils.js"}
{script src="js/addons/novoton_holidays/booking_engine.js"}

{* Multi-room booking support *}
{script src="js/addons/novoton_holidays/multiroom-booking.js"}

{* Form validation scripts *}
{script src="js/addons/novoton_holidays/dob-validation.js"}
{script src="js/addons/novoton_holidays/booking-form-validation.js"}
