{* Shared placeholder for the not-yet-implemented Eurosite modules
   (Pachete / Transport / Circuite Touroperator). $eurosite_module is set
   by the mode template that includes this. *}

{assign var="eurosite_module_titles" value=[
    "packages"  => __("eurosite.module_packages",  ["[default]" => "Pachete Touroperator"]),
    "transport" => __("eurosite.module_transport", ["[default]" => "Transport Touroperator"]),
    "circuits"  => __("eurosite.module_circuits",  ["[default]" => "Circuite Touroperator"])
]}
{assign var="eurosite_module_title" value=$eurosite_module_titles.$eurosite_module}

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
