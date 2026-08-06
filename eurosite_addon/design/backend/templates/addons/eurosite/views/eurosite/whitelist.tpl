{capture name="mainbox"}

<div class="travel-admin-panel" id="eurosite-whitelist">

    {if !$eurosite_countries_synced}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.whitelist_needs_countries", ["[default]" => "The country catalog has not been synced yet — run the 'countries' sync from the dashboard first. City lists fall back to live API calls."])}
        </div>
    {/if}

    <p class="muted">
        {__("eurosite.whitelist_hint", ["[default]" => "Only whitelisted destinations are synced and searchable on the storefront. Tick a country to include all of its cities, or expand it and pick specific cities."])}
    </p>

    {* All values the page script needs ride as data attributes; the script
       itself is a real JS file (js/addons/eurosite/whitelist.js) — inline
       scripts go through the backend's inline_script/CSP rewriter and
       Smarty-parse their braces, both of which have broken this page. *}
    <div id="eurosite-whitelist-data"
         data-whitelist="{$eurosite_whitelist_json|escape:html}"
         data-cities-url="{"eurosite.get_cities"|fn_url}"
         data-txt-loading="{__("loading", ["[default]" => "Loading..."])|escape:html}"
         data-txt-no-cities="{__("eurosite.no_cities", ["[default]" => "No cities found."])|escape:html}"
         data-txt-failed="{__("eurosite.request_failed", ["[default]" => "Request failed."])|escape:html}"></div>

    <form action="{""|fn_url}" method="post" id="eurosite-whitelist-form">
        <input type="hidden" name="dispatch" value="eurosite.save_whitelist" />
        <input type="hidden" name="whitelist_json" id="eurosite-whitelist-json" value="" />

        <div id="eurosite-country-list">
            {foreach from=$eurosite_countries item=country}
                {assign var="cc" value=$country.country_code}
                {assign var="wl" value=$eurosite_whitelist_map.$cc}
                <div class="eurosite-country-row" data-country="{$cc}" style="border-bottom: 1px solid #eee; padding: 6px 0;">
                    <label class="checkbox inline" style="min-width: 260px;">
                        <input type="checkbox" class="eurosite-country-all" data-country="{$cc}"
                               {if $wl && $wl.all}checked{/if} />
                        <strong>{$country.name|escape:html}</strong> <code>{$cc}</code>
                        {if $wl && !$wl.all && $wl.cities}
                            <span class="label">{$wl.cities|count} {__("eurosite.cities_selected", ["[default]" => "cities"])}</span>
                        {/if}
                    </label>
                    <a href="#" class="eurosite-expand" data-country="{$cc}">{__("eurosite.pick_cities", ["[default]" => "pick specific cities"])}</a>
                    <div class="eurosite-city-box" data-country="{$cc}" style="display:none; margin: 6px 0 6px 24px;"></div>
                </div>
            {/foreach}
        </div>

        <div class="buttons-container" style="margin-top: 14px;">
            <button type="submit" class="btn btn-primary">{__("save")}</button>
            <a href="{"eurosite.manage"|fn_url}" class="btn">{__("eurosite.back_to_dashboard", ["[default]" => "Back to dashboard"])}</a>
        </div>
    </form>
</div>

{script src="js/addons/eurosite/whitelist.js"}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.whitelist_title", ["[default]" => "Eurosite — Destination whitelist"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
