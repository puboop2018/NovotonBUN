{*
 * Novoton Feature Mappings Management Page
 *}

{capture name="mainbox"}

<div class="novoton-feature-mappings-manage">

    {* Stats Bar *}
    <div class="well well-small" style="margin-bottom: 15px;">
        <div class="row-fluid">
            <div class="span3">
                <strong>{__("novoton_holidays.fm_total")}:</strong> {$mapping_stats.total}
            </div>
            <div class="span3">
                <strong>{__("novoton_holidays.fm_active")}:</strong>
                <span class="label label-success">{$mapping_stats.active}</span>
            </div>
            <div class="span3">
                <strong>{__("novoton_holidays.fm_auto_registered")}:</strong>
                <span class="label label-warning">{$mapping_stats.auto}</span>
            </div>
            <div class="span3">
                <strong>{__("novoton_holidays.fm_unmapped")}:</strong>
                <span class="label {if $mapping_stats.unmapped > 0}label-important{/if}">{$mapping_stats.unmapped}</span>
            </div>
        </div>
    </div>

    {* Configured Feature IDs *}
    <div class="well well-small" style="margin-bottom: 15px;">
        <strong>{__("novoton_holidays.fm_configured_features")}:</strong>
        {foreach from=$feature_types item=ft}
            <span class="label {if $feature_settings.$ft > 0}label-info{else}label-default{/if}" style="margin-left: 5px;">
                {$ft}: {if $feature_settings.$ft > 0}#{$feature_settings.$ft}{else}{__("novoton_holidays.fm_not_set")}{/if}
            </span>
        {/foreach}
        <a href="{"addons.update?addon=novoton_holidays&selected_section=feature_mapping"|fn_url}" class="btn btn-mini" style="margin-left: 10px;">
            <i class="icon-cog"></i> {__("novoton_holidays.fm_configure")}
        </a>
    </div>

    {* Filter Form *}
    <form action="{""|fn_url}" method="get" class="form-horizontal form-inline search-form" style="margin-bottom: 15px;">
        <input type="hidden" name="dispatch" value="novoton_feature_mappings.manage" />

        <div class="control-group">
            <label class="control-label">{__("novoton_holidays.fm_feature_type")}:</label>
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
            <label class="control-label">{__("novoton_holidays.fm_source")}:</label>
            <div class="controls">
                <select name="source">
                    <option value="">{__("all")}</option>
                    <option value="seed" {if $search.source == 'seed'}selected{/if}>{__("novoton_holidays.fm_source_seed")}</option>
                    <option value="auto" {if $search.source == 'auto'}selected{/if}>{__("novoton_holidays.fm_source_auto")}</option>
                    <option value="manual" {if $search.source == 'manual'}selected{/if}>{__("novoton_holidays.fm_source_manual")}</option>
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("status")}:</label>
            <div class="controls">
                <select name="active">
                    <option value="">{__("all")}</option>
                    <option value="Y" {if $search.active == 'Y'}selected{/if}>{__("active")}</option>
                    <option value="N" {if $search.active == 'N'}selected{/if}>{__("disabled")}</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{__("search")}</button>
    </form>

    {* Action Buttons *}
    <div style="margin-bottom: 15px;">
        <form action="{"novoton_feature_mappings.reseed"|fn_url}" method="post" class="form-inline" style="display: inline-block;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <button type="submit" class="btn btn-default" onclick="return confirm('{__("novoton_holidays.fm_reseed_confirm")}');">
                <i class="icon-refresh"></i> {__("novoton_holidays.fm_reseed")}
            </button>
        </form>
    </div>

    {* Mappings Table *}
    {if $mappings}
    <form action="{"novoton_feature_mappings.bulk_update"|fn_url}" method="post" name="bulk_form">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="feature_type" value="{$search.feature_type}">
        <input type="hidden" name="source" value="{$search.source}">

        {foreach from=$grouped_mappings key=group_type item=group_items}
        <h4 style="margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
            {$group_type}
            <span class="muted">({$group_items|@count} {__("novoton_holidays.fm_mappings")})</span>
            {if $feature_settings.$group_type > 0}
                <small class="muted">Feature #{$feature_settings.$group_type}</small>
            {else}
                <small class="label label-important">{__("novoton_holidays.fm_not_configured")}</small>
            {/if}
        </h4>

        <table class="table table-striped table-hover table-condensed">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" class="select-all-{$group_type}" onclick="toggleGroupCheckboxes(this, '{$group_type}')"></th>
                    <th width="60">ID</th>
                    <th width="80">{__("novoton_holidays.fm_provider")}</th>
                    <th>{__("novoton_holidays.fm_provider_code")}</th>
                    <th>{__("novoton_holidays.fm_display_en")}</th>
                    <th>{__("novoton_holidays.fm_display_ro")}</th>
                    <th width="80">{__("novoton_holidays.fm_variant_id")}</th>
                    <th width="80">{__("novoton_holidays.fm_cs_type")}</th>
                    <th width="60">{__("novoton_holidays.fm_source")}</th>
                    <th width="60">{__("status")}</th>
                    <th width="120">{__("novoton_holidays.fm_last_synced")}</th>
                    <th width="80">{__("tools")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$group_items item=m}
                <tr class="{if $m.is_active == 'N'}muted{/if}">
                    <td><input type="checkbox" name="mapping_ids[]" value="{$m.mapping_id}" class="group-{$group_type}"></td>
                    <td>{$m.mapping_id}</td>
                    <td><span class="label">{$m.provider}</span></td>
                    <td><code>{$m.provider_code}</code></td>
                    <td>{$m.display_name_en|default:'-'}</td>
                    <td>{$m.display_name_ro|default:'-'}</td>
                    <td>
                        {if $m.cs_cart_variant_id > 0}
                            <span class="label label-success">#{$m.cs_cart_variant_id}</span>
                        {else}
                            <span class="label label-important">{__("novoton_holidays.fm_none")}</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.cs_cart_feature_type == 'M'}
                            <span title="Multiple Checkboxes">M</span>
                        {elseif $m.cs_cart_feature_type == 'S'}
                            <span title="Select Box">S</span>
                        {else}
                            {$m.cs_cart_feature_type}
                        {/if}
                    </td>
                    <td>
                        {if $m.mapping_source == 'seed'}
                            <span class="label">{__("novoton_holidays.fm_source_seed")}</span>
                        {elseif $m.mapping_source == 'auto'}
                            <span class="label label-warning">{__("novoton_holidays.fm_source_auto")}</span>
                        {else}
                            <span class="label label-info">{__("novoton_holidays.fm_source_manual")}</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.is_active == 'Y'}
                            <span class="label label-success">{__("active")}</span>
                        {else}
                            <span class="label">{__("disabled")}</span>
                        {/if}
                    </td>
                    <td>
                        {if $m.last_synced_at}
                            <small>{$m.last_synced_at|date_format:"%d.%m %H:%M"}</small>
                        {else}
                            <small class="muted">{__("never")}</small>
                        {/if}
                    </td>
                    <td>
                        <a href="{"novoton_feature_mappings.edit?mapping_id=`$m.mapping_id`"|fn_url}" class="btn btn-xs btn-default" title="{__("edit")}">
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
            <strong>{__("novoton_holidays.fm_with_selected")}:</strong>
            <a href="#" class="btn btn-mini btn-success" onclick="document.bulk_form.action = '{"novoton_feature_mappings.bulk_update.activate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ok"></i> {__("novoton_holidays.fm_activate")}
            </a>
            <a href="#" class="btn btn-mini btn-warning" onclick="document.bulk_form.action = '{"novoton_feature_mappings.bulk_update.deactivate"|fn_url}'; document.bulk_form.submit(); return false;">
                <i class="icon-ban-circle"></i> {__("novoton_holidays.fm_deactivate")}
            </a>
            <a href="#" class="btn btn-mini btn-danger" onclick="if(confirm('{__("novoton_holidays.fm_delete_confirm")}')) {ldelim} document.bulk_form.action = '{"novoton_feature_mappings.bulk_update.delete"|fn_url}'; document.bulk_form.submit(); {rdelim} return false;">
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
    <a href="{"addons.update?addon=novoton_holidays&selected_section=feature_mapping"|fn_url}" class="btn">
        <i class="icon-cog"></i> {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.feature_mappings")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
