{* Sphinx package routes — read-only admin grid (paginated, sortable,
   filterable). Populated by the package-routes static sync
   (cron_mode=package_routes / the "Sync now" button). *}

{* ── Sidebar: Filter Panel ── *}
{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="sphinx_routes_filter_form" id="sphinx_routes_filter_form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.package_routes" />

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.transport_type")}:</label>
            <select name="transport_type">
                <option value="">{__("sphinx_holidays.all")}</option>
                <option value="flight" {if $search.transport_type == "flight"}selected{/if}>{__("sphinx_holidays.transport_flight")}</option>
                <option value="bus" {if $search.transport_type == "bus"}selected{/if}>{__("sphinx_holidays.transport_bus")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.departure")}:</label>
            <input type="text" name="departure" value="{$search.departure|escape:html}" placeholder="{__("sphinx_holidays.departure")}" />
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.arrival")}:</label>
            <input type="text" name="arrival" value="{$search.arrival|escape:html}" placeholder="{__("sphinx_holidays.arrival")}" />
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.duration")}:</label>
            <input type="text" name="duration" value="{$search.duration|escape:html}" placeholder="{__("sphinx_holidays.nights_short")}" />
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}


{* ── Main Content ── *}
{capture name="mainbox"}

{* Header: freshness line (left) + "Sync now" (right). *}
<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
    <span class="muted">
        {if $last_synced_at}
            {__("sphinx_holidays.last_synced")}: {$last_synced_at} &middot;
        {/if}
        {$total_count|default:0} {__("sphinx_holidays.package_routes")}
    </span>
    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="sphinx_holidays.sync_package_routes" />
        <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}>
            <i class="icon-refresh"></i> {__("sphinx_holidays.sync_now")}
        </button>
    </form>
</div>

{include file="common/pagination.tpl" save_current_url=true}

{if $package_routes}
<table class="table table-middle table-striped">
    <thead>
        <tr>
            <th width="70">
                <a href="{"`$sort_url_base`&sort_by=route_id&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    ID {if $search.sort_by == 'route_id'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="100">
                <a href="{"`$sort_url_base`&sort_by=transport_type&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.transport_type")} {if $search.sort_by == 'transport_type'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th>
                <a href="{"`$sort_url_base`&sort_by=departure_name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.departure")} {if $search.sort_by == 'departure_name'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th>
                <a href="{"`$sort_url_base`&sort_by=arrival_name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.arrival")} {if $search.sort_by == 'arrival_name'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="110">
                <a href="{"`$sort_url_base`&sort_by=duration&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.duration")} {if $search.sort_by == 'duration'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th>{__("sphinx_holidays.available_dates")}</th>
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=last_synced_at&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.last_synced")} {if $search.sort_by == 'last_synced_at'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$package_routes item=route}
        <tr>
            <td><code style="font-size:11px;">{$route.route_id}</code></td>
            <td>{$route.transport_type|default:"-"|escape:html}</td>
            <td>
                {$route.departure_name|escape:html}
                {if $route.departure_iata}<code style="font-size:11px;">{$route.departure_iata|escape:html}</code>{/if}
            </td>
            <td>
                {$route.arrival_name|escape:html}
                {if $route.arrival_iata}<code style="font-size:11px;">{$route.arrival_iata|escape:html}</code>{/if}
            </td>
            <td>{$route.duration} {__("sphinx_holidays.nights_short")}</td>
            <td>
                {if $route.available_dates && $route.available_dates != '[]'}
                    {$route.available_dates|replace:'["':''|replace:'"]':''|replace:'","':', '|truncate:80|escape:html}
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>
            <td>{$route.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("sphinx_holidays.no_package_routes")}</p>
{/if}

{include file="common/pagination.tpl"}

{/capture}

{include file="common/mainbox.tpl"
    title="{__('sphinx_holidays.package_routes')}"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
