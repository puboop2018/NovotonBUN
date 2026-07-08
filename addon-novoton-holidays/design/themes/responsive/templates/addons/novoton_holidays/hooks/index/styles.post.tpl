{**
 * Novoton Holidays - CSS Styles Connection Hook
 *
 * Loads novoton-specific styles only.
 * Shared booking-engine.css and booking-form-react.css are loaded
 * by travel_core's hooks/index/styles.post.tpl.
 *}

{* Novoton-specific addon styles (LESS - will be compiled by CS-Cart) *}
{style src="addons/novoton_holidays/styles.less"}

{* Search-results page: novoton-specific classes (multi-room grid, quota
   states, promos, info modal). The offer cards themselves come from
   travel_core's shared search-results.css. *}
{style src="addons/novoton_holidays/novoton-results.css"}
