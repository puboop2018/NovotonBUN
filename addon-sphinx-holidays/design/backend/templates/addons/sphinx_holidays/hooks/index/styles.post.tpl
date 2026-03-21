{**
 * Sphinx Holidays - Admin Panel CSS Styles Hook
 *
 * Loads shared travel_core admin styles for dashboard stat cards,
 * status badges, sync logs, etc.
 *}

{style src="addons/travel_core/admin_styles.css"}

{* Widen Sphinx addon settings fields to match CS-Cart input-large *}
<style>
    #addon_options_sphinx_holidays input[type="text"],
    #addon_options_sphinx_holidays input[type="password"],
    #addon_options_sphinx_holidays textarea,
    #content_general input[type="text"],
    #content_general input[type="password"],
    #content_general textarea {ldelim}
        width: calc(100% - 5px);
        max-width: none;
        box-sizing: border-box;
    {rdelim}
</style>
