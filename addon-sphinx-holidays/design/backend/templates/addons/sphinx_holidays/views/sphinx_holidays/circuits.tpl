{* Sphinx circuits — read-only admin grid (paginated, sortable, filterable).
   Data is populated by the circuits static sync (cron_mode=circuits / the
   "Sync now" button). Mirrors the hotels grid without the bulk-action bar. *}

{* ── Sidebar: Filter Panel ── *}
{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="sphinx_circuits_filter_form" id="sphinx_circuits_filter_form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.circuits" />

        <div class="sidebar-field">
            <label>{__("name")}:</label>
            <input type="text" name="q" value="{$search.q|escape:html}" placeholder="{__("name")}" />
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.transport_type")}:</label>
            <select name="transport_type">
                <option value="">{__("sphinx_holidays.all")}</option>
                <option value="flight" {if $search.transport_type == "flight"}selected{/if}>{__("sphinx_holidays.transport_flight")}</option>
                <option value="bus" {if $search.transport_type == "bus"}selected{/if}>{__("sphinx_holidays.transport_bus")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.sync_status")}:</label>
            <select name="sync_status">
                <option value="">{__("sphinx_holidays.all_statuses")}</option>
                <option value="active" {if $search.sync_status == "active"}selected{/if}>{__("sphinx_holidays.active")}</option>
                <option value="inactive" {if $search.sync_status == "inactive"}selected{/if}>{__("sphinx_holidays.inactive")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}


{* ── Main Content ── *}
{capture name="mainbox"}

{* Header: freshness line (left) + "Sync now" (right) — the per-page contextual
   sync control; the same action is available on the Sphinx Dashboard. *}
<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
    <span class="muted">
        {if $last_synced_at}
            {__("sphinx_holidays.last_synced")}: {$last_synced_at} &middot;
        {/if}
        {$total_count|default:0} {__("sphinx_holidays.circuits")}
    </span>
    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="sphinx_holidays.sync_circuits" />
        <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}>
            <i class="icon-refresh"></i> {__("sphinx_holidays.sync_now")}
        </button>
    </form>
</div>

{include file="common/pagination.tpl" save_current_url=true}

{if $circuits}
<table class="table table-middle table-striped">
    <thead>
        <tr>
            <th width="90">
                <a href="{"`$sort_url_base`&sort_by=circuit_id&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    ID {if $search.sort_by == 'circuit_id'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th>
                <a href="{"`$sort_url_base`&sort_by=name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("name")} {if $search.sort_by == 'name'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="110">
                <a href="{"`$sort_url_base`&sort_by=transport_type&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.transport_type")} {if $search.sort_by == 'transport_type'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="120">
                <a href="{"`$sort_url_base`&sort_by=duration_nights&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.duration")} {if $search.sort_by == 'duration_nights'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th>{__("sphinx_holidays.destinations")}</th>
            <th width="110">
                <a href="{"`$sort_url_base`&sort_by=min_price&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.min_price")} {if $search.sort_by == 'min_price'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="80">{__("sphinx_holidays.product")}</th>
            <th width="80">
                <a href="{"`$sort_url_base`&sort_by=sync_status&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.sync_status")} {if $search.sort_by == 'sync_status'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=last_synced_at&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.last_synced")} {if $search.sort_by == 'last_synced_at'}{if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}{/if}
                </a>
            </th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$circuits item=circuit}
        <tr>
            <td><code style="font-size:11px;">{$circuit.circuit_id}</code></td>
            <td>
                <div style="display:flex; align-items:center; gap:8px;">
                    {if $circuit.image_url}
                        <img src="{$circuit.image_url|escape:html}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:3px; flex-shrink:0;" loading="lazy" />
                    {/if}
                    <span>{$circuit.name|escape:html}</span>
                </div>
            </td>
            <td>{$circuit.transport_type|default:"-"|escape:html}</td>
            <td>
                {if $circuit.duration_nights > 0 || $circuit.duration_days > 0}
                    {$circuit.duration_nights} {__("sphinx_holidays.nights_short")} / {$circuit.duration_days} {__("sphinx_holidays.days_short")}
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>
            <td>{$circuit.destination_names|default:"-"|escape:html|truncate:60}</td>
            <td>
                {if $circuit.min_price}
                    {$circuit.min_price} {$circuit.currency|escape:html}
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>
            <td>
                {if $circuit.product_id > 0}
                    <a href="{"products.update?product_id=`$circuit.product_id`"|fn_url}" title="{__("sphinx_holidays.product")} #{$circuit.product_id}">#{$circuit.product_id}</a>
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>
            <td>
                {if $circuit.sync_status == 'active'}
                    <span class="label label-success">{__("sphinx_holidays.active")}</span>
                {else}
                    <span class="label label-warning">{__("sphinx_holidays.inactive")}</span>
                {/if}
            </td>
            <td>{$circuit.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("sphinx_holidays.no_circuits")}</p>
{/if}

{include file="common/pagination.tpl"}

{/capture}

{include file="common/mainbox.tpl"
    title="{__('sphinx_holidays.circuits')}"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
