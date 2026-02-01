{**
 * Novoton Holidays - CSS Styles Connection Hook
 * 
 * This hook properly connects addon CSS files following CS-Cart conventions.
 * Location: design/themes/responsive/templates/addons/novoton_holidays/hooks/index/styles.post.tpl
 * 
 * CS-Cart will automatically compile LESS files and cache all styles together.
 * All addon styles are collected into one cached file for performance.
 *}

{* Main addon styles (LESS - will be compiled by CS-Cart) *}
{style src="addons/novoton_holidays/styles.less"}

{* Booking form React component styles *}
{style src="addons/novoton_holidays/booking-form-react.css"}
