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

{* Search-results page: novoton-specific classes (multi-room grid, quota
   states, promos, info modal). The offer cards themselves come from
   travel_core's shared search-results.css. *}
{style src="addons/novoton_holidays/novoton-results.css"}

{* NOTE: novoton's own booking-engine.css was deleted — it was a stale fork
   of travel_core's booking-engine.css and, loaded here, its older rules
   overrode the shared (newer) stylesheet on this theme. *}
