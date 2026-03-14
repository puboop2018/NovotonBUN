{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{* Search/filter form *}
<form action="{""|fn_url}" method="get" name="sphinx_destinations_search_form">
    <input type="hidden" name="dispatch" value="sphinx_holidays.destinations" />

    <div class="sidebar-row">
        <h6>{__("search")}</h6>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.destination_type")}:</label>
            <select name="type">
                <option value="">{__("sphinx_holidays.all_types")}</option>
                <option value="continent" {if $search.type == "continent"}selected{/if}>{__("sphinx_holidays.continents")}</option>
                <option value="country" {if $search.type == "country"}selected{/if}>{__("sphinx_holidays.countries")}</option>
                <option value="region" {if $search.type == "region"}selected{/if}>{__("sphinx_holidays.regions")}</option>
                <option value="city" {if $search.type == "city"}selected{/if}>{__("sphinx_holidays.cities")}</option>
                <option value="destination" {if $search.type == "destination"}selected{/if}>{__("sphinx_holidays.destinations")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.destination_name")}:</label>
            <input type="text" name="q" value="{$search.q|escape:html}" size="20" placeholder="{__("sphinx_holidays.search_destinations")}" />
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </div>
</form>

{if $destinations}
<table class="table table-middle">
    <thead>
        <tr>
            <th width="80">ID</th>
            <th>{__("sphinx_holidays.destination_name")}</th>
            <th width="120">{__("sphinx_holidays.destination_type")}</th>
            <th width="80">{__("sphinx_holidays.country_code")}</th>
            <th width="80">{__("sphinx_holidays.hotel_count")}</th>
            <th width="160">{__("sphinx_holidays.last_synced")}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$destinations item=dest}
        <tr>
            <td>{$dest.destination_id}</td>
            <td>
                {$dest.name|escape:html}
                {if $dest.parent_id > 0}
                    <a href="{"sphinx_holidays.destinations?parent_id=`$dest.destination_id`"|fn_url}" class="btn btn-micro" title="View children">
                        <i class="icon-sitemap"></i>
                    </a>
                {/if}
            </td>
            <td>
                <span class="status-badge status-{if $dest.type == 'country'}ok{elseif $dest.type == 'continent'}ask{elseif $dest.type == 'city'}pending{else}ok{/if}">
                    {$dest.type|escape:html}
                </span>
            </td>
            <td>{$dest.country_code|escape:html}</td>
            <td>{$dest.hotel_count|default:0}</td>
            <td>{$dest.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("sphinx_holidays.no_destinations")}</p>
{/if}

{include file="common/pagination.tpl"}

{/capture}

{include file="common/mainbox.tpl" title="{__("sphinx_holidays.destinations")}" content=$smarty.capture.mainbox}
