{**
 * Sphinx Holidays - Admin Panel CSS Styles Hook
 *
 * Loads shared travel_core admin styles for dashboard stat cards,
 * status badges, sync logs, etc.
 *}

{style src="addons/travel_core/admin_styles.css"}

{* Add CS-Cart input-text-large class to Sphinx addon settings fields *}
<script>
(function($) {ldelim}
    $(document).ready(function() {ldelim}
        $('#addon_options_sphinx_holidays input[type="text"], #addon_options_sphinx_holidays input[type="password"], #addon_options_sphinx_holidays textarea').addClass('input-text-large');
        $('#content_general input[type="text"], #content_general input[type="password"], #content_general textarea').addClass('input-text-large');
    {rdelim});
{rdelim})(Tygh.$);
</script>
