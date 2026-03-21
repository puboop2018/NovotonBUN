{**
 * Sphinx Holidays - Admin Panel CSS Styles Hook
 *
 * Loads shared travel_core admin styles for dashboard stat cards,
 * status badges, sync logs, etc.
 *}

{style src="addons/travel_core/admin_styles.css"}

{* Widen Sphinx addon settings fields (api_base_url, api_key, ignore_domains) *}
<style>
    #addon_options_sphinx_holidays input[type="text"],
    #addon_options_sphinx_holidays input[type="password"],
    #addon_options_sphinx_holidays textarea,
    #content_general input[type="text"],
    #content_general input[type="password"],
    #content_general textarea {ldelim}
        min-width: 550px;
        box-sizing: border-box;
    {rdelim}
</style>
