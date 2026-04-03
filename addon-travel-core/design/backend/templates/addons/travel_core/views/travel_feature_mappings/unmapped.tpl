{*
 * Travel Core - Unmapped API Values
 *
 * Shows API values that had no match in travel_api_alias.
 * Admin can promote them to real mappings via the [Map] button.
 *}

{capture name="mainbox"}

<div class="travel-unmapped-values">

    {* Back link *}
    <div style="margin-bottom: 15px;">
        <a href="{"travel_feature_mappings.manage"|fn_url}" class="btn btn-default btn-mini">
            <i class="icon-arrow-left"></i> {__("travel_core.fm_back_dashboard")}
        </a>
    </div>

    {* Filters *}
    <form action="{""|fn_url}" method="get" class="form-inline" style="margin-bottom: 10px;">
        <input type="hidden" name="dispatch" value="travel_feature_mappings.unmapped" />

        <select name="api_source" style="width: 120px; margin-right: 8px;">
            <option value="">Provider: All</option>
            <option value="sphinx" {if $search.api_source == 'sphinx'}selected{/if}>Sphinx</option>
            <option value="novoton" {if $search.api_source == 'novoton'}selected{/if}>Novoton</option>
        </select>

        <select name="feature_type" style="width: 120px; margin-right: 8px;">
            <option value="">Type: All</option>
            <option value="facility" {if $search.feature_type == 'facility'}selected{/if}>Facility</option>
            <option value="resort" {if $search.feature_type == 'resort'}selected{/if}>Resort</option>
            <option value="board" {if $search.feature_type == 'board'}selected{/if}>Board</option>
        </select>

        <button type="submit" class="btn btn-primary btn-mini">{__("search")}</button>
        {if $search.api_source || $search.feature_type}
            <a href="{"travel_feature_mappings.unmapped"|fn_url}" class="btn btn-mini">{__("reset")}</a>
        {/if}
    </form>

    {* Pagination *}
    {include file="common/pagination.tpl" save_current_url=true}

    {if $unmapped_values}
    <table class="table table-striped table-hover table-condensed">
        <thead>
            <tr>
                <th width="90">Provider</th>
                <th width="90">Type</th>
                <th>API Value</th>
                <th>API Name</th>
                <th width="70">Hotels</th>
                <th width="100">First Seen</th>
                <th width="100">Last Seen</th>
                <th width="70">Action</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$unmapped_values item=u}
            <tr>
                <td>
                    <span class="label {if $u.api_source == 'sphinx'}label-info{elseif $u.api_source == 'novoton'}label-warning{else}label-default{/if}">
                        {$u.api_source|escape:'html'}
                    </span>
                </td>
                <td><code>{$u.feature_type|escape:'html'}</code></td>
                <td><code>{$u.api_value|escape:'html'}</code></td>
                <td>{$u.api_label|escape:'html'|default:'-'}</td>
                <td><span class="label">{$u.hotel_count}</span></td>
                <td style="font-size: 11px; color: #999;">{$u.first_seen_at|date_format:"%Y-%m-%d"}</td>
                <td style="font-size: 11px; color: #999;">{$u.last_seen_at|date_format:"%Y-%m-%d"}</td>
                <td>
                    <form action="{"travel_feature_mappings.map_unmapped"|fn_url}" method="post" style="display: inline;">
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <input type="hidden" name="unmapped_id" value="{$u.unmapped_id}">
                        <button type="submit" class="btn btn-mini btn-success" title="Create mapping + alias" onclick="return confirm('Create mapping for {$u.api_source|escape:'javascript'} {$u.feature_type|escape:'javascript'} = {$u.api_value|escape:'javascript'}?');">
                            <i class="icon-plus"></i> Map
                        </button>
                    </form>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    {else}
    <p class="no-items">
        <i class="icon-ok text-success"></i> No unmapped API values. All resolved.
    </p>
    {/if}

    {* Bottom Pagination *}
    {include file="common/pagination.tpl"}

</div>

{/capture}

{capture name="buttons"}
    <a href="{"travel_feature_mappings.manage"|fn_url}" class="btn">
        <i class="icon-th-large"></i> {__("travel_core.feature_mappings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.fm_unmapped_values")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
