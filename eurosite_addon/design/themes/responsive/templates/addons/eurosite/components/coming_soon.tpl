{* Shared placeholder for the not-yet-implemented Eurosite modules
   (Pachete / Transport / Circuite Touroperator). $eurosite_module is set
   by the mode template that includes this. *}

{if $eurosite_module == "packages"}
    {assign var="eurosite_module_title" value=__("eurosite.module_packages", ["[default]" => "Pachete Touroperator"])}
{elseif $eurosite_module == "transport"}
    {assign var="eurosite_module_title" value=__("eurosite.module_transport", ["[default]" => "Transport Touroperator"])}
{else}
    {assign var="eurosite_module_title" value=__("eurosite.module_circuits", ["[default]" => "Circuite Touroperator"])}
{/if}

{capture name="mainbox"}
<div class="travel-search-results-page eurosite-coming-soon" style="text-align:center; padding: 60px 20px;">
    <h1>{$eurosite_module_title}</h1>
    <p class="muted" style="font-size: 16px; margin: 16px 0 28px;">
        {__("eurosite.module_coming_soon", ["[default]" => "This Eurosite module is coming soon."])}
    </p>
    <a href="{"eurosite_booking.search"|fn_url}" class="ty-btn ty-btn__primary">
        {__("eurosite.module_try_hotels", ["[default]" => "Search hotel stays instead"])}
    </a>
</div>
{/capture}

{include file="common/mainbox.tpl" title=$eurosite_module_title content=$smarty.capture.mainbox}
