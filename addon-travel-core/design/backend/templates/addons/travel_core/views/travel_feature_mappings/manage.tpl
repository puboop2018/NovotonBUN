{*
 * Travel Core - Feature Mappings Management Page
 *}

{capture name="mainbox"}

<div class="travel-feature-mappings-manage">

    {* Stats Bar *}
    <div class="well well-small" style="margin-bottom: 15px;">
        <div class="row-fluid">
            <div class="span3">
                <strong>{__("travel_core.fm_total")}:</strong> {$mapping_stats.total}
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_active")}:</strong>
                <span class="label label-success">{$mapping_stats.active}</span>
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_unmapped")}:</strong>
                <span class="label {if $mapping_stats.unmapped > 0}label-important{/if}">{$mapping_stats.unmapped}</span>
            </div>
            <div class="span3">
                <strong>{__("travel_core.fm_aliases")}:</strong>
                <span class="label label-info">{$mapping_stats.aliases}</span>
            </div>
        </div>
    </div>

    {* Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-horizontal form-inline search-form" style="margin-bottom: 15px;">
        <input type="hidden" name="dispatch" value="travel_feature_mappings.manage" />

        <div class="control-group">
            <label class="control-label">{__("travel_core.fm_feature_type")}:</label>
            <div class="controls">
                <select name="feature_type">
                    <option value="">{__("all")}</option>
                    {foreach from=$feature_types item=ft}
                        <option value="{$ft}" {if $search.feature_type == $ft}selected{/if}>{$ft}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("status")}:</label>
            <div class="controls">
                <select name="status">
                    <option value="">{__("all")}</option>
                    <option value="A" {if $search.status == 'A'}selected{/if}>{__("active")}</option>
                    <option value="D" {if $search.status == 'D'}selected{/if}>{__("disabled")}</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
    </form>

    {* Action Buttons *}
    <div style="margin-bottom: 15px;">
        <form action="{"travel_feature_mappings.reseed"|fn_url}" method="post" class="form-inline" style="display: inline-block;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-default" onclick="return confirm('{__("travel_core.fm_reseed_confirm")}');">
                <i class="icon-refresh"></i> {__("travel_core.fm_reseed")}
            </button>
        </form>
        <form action="{"travel_feature_mappings.resolve_variants"|fn_url}" method="post" class="form-inline" style="display: inline-block; margin-left: 5px;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-success" onclick="return confirm('{__("travel_core.fm_resolve_confirm")}');">
                <i class="icon-magic"></i> {__("travel_core.fm_resolve_variants")}
            </button>
        </form>
    </div>

    {* Mappings Table *}
    {if $mappings}
    <form action="{"travel_feature_mappings.bulk_update"|fn_url}" method="post" name="bulk_form">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="feature_type" value="{$search.feature_type}">

        {foreach from=$grouped_mappings key=group_type item=group_items}
        <h4 style="margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
            {$group_type}
            <span class="muted">({$group_items|@count} {__("travel_core.fm_entries")})</span>
        </h4>

        <table class="table table-striped table-hover table-condensed">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" class="select-all-{$group_type}" onclick="toggleGroupCheckboxes(this, '{$group_type}')"></th>
                    <th width="50">ID</th>
                    <th>{__("travel_core.fm_canonical_code")}</th>
                    <th>{__("travel_core.fm_display_en")}</th>
                    <th>{__("travel_core.fm_display_ro")}</th>
                    <th>{__("travel_core.fm_cs_feature")}</th>
                    <th width="80">{__("travel_core.fm_variant")}</th>
                    <th width="60">{__("travel_core.fm_aliases")}</th>
                    <th width="60">{__("status")}</th>
                    <th width="80">{__("tools")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$group_items item=m}
                <tr class="{if $m.status == 'D'}muted{/if}">
                    <td><input type="checkbox" name="map_ids[]" value="{$m.map_id}" class="group-{$group_type}"></td>
                    <td>{$m.map_id}</td>
                    <td><code>{$m.canonical_code}</code></td>
                    <td>{$m.display_name_en|default:'-'}</td>
                    <td>{$m.display_name_ro|default:'-'}</td>
                    <td>
                        {if $m.cscart_feature_id > 0}
                            <span class="label label-info">{$m.feature_name|default:"#`$m.cscart_feature_id`"}</span>
                        {else}
                            <span class="muted">-</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.cscart_variant_id > 0}
                            <span class="label label-success" title="{$m.variant_name}">#{$m.cscart_variant_id}</span>
                        {else}
                            <span class="label label-important">-</span>
                        {/if}
                    </td>
                    <td>
                        <span class="label">{$m.alias_count}</span>
                    </td>
                    <td>
                        {if $m.status == 'A'}
                            <span class="label label-success">{__("active")}</span>
                        {else}
                            <span class="label">{__("disabled")}</span>
                        {/if}
                    </td>
                    <td>
                        <a href="{"travel_feature_mappings.edit?map_id=`$m.map_id`"|fn_url}" class="btn btn-xs btn-default" title="{__("edit")}">
                            <i class="icon-pencil"></i>
                        </a>
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
        {/foreach}

        {* Bulk Action Buttons *}
        <div class="well well-small" style="margin-top: 10px;">
            <strong>{__("travel_core.fm_with_selected")}:</strong>
            <a href="#" class="btn btn-mini btn-success" onclick="document.bulk_form.action = '{"travel_feature_mappings.bulk_update.activate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ok"></i> {__("travel_core.fm_activate")}
            </a>
            <a href="#" class="btn btn-mini btn-warning" onclick="document.bulk_form.action = '{"travel_feature_mappings.bulk_update.deactivate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ban-circle"></i> {__("travel_core.fm_deactivate")}
            </a>
            <a href="#" class="btn btn-mini btn-danger" onclick="if(confirm('{__("travel_core.fm_delete_confirm")}')) {ldelim} document.bulk_form.action = '{"travel_feature_mappings.bulk_update.delete"|fn_url}'; document.bulk_form.submit(); {rdelim} return false;">
                <i class="icon-trash"></i> {__("delete")}
            </a>
        </div>
    </form>

    {else}
    <p class="no-items">{__("no_data")}</p>
    {/if}
</div>

<script>
function toggleGroupCheckboxes(source, group) {
    var checkboxes = document.querySelectorAll('.group-' + group);
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>

{/capture}

{capture name="buttons"}
    <a href="{"addons.update?addon=travel_core&selected_section=general"|fn_url}" class="btn">
        <i class="icon-cog"></i> {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.feature_mappings")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
