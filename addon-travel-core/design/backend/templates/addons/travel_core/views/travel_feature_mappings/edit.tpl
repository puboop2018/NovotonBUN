{*
 * Travel Core - Feature Mapping Edit Page
 * Includes CS-Cart feature dropdown and AJAX variant loading
 *}

{capture name="mainbox"}

<form action="{"travel_feature_mappings.update"|fn_url}" method="post" class="form-horizontal">
    <input type="hidden" name="security_hash" value="{$security_hash}">
    <input type="hidden" name="map_id" value="{$mapping.map_id}">

    {* Read-only Info *}
    <div class="control-group">
        <label class="control-label">{__("travel_core.fm_map_id")}:</label>
        <div class="controls">
            <span class="uneditable-input">{$mapping.map_id}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("travel_core.fm_feature_type")}:</label>
        <div class="controls">
            <span class="uneditable-input">{$mapping.feature_type}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("travel_core.fm_canonical_code")}:</label>
        <div class="controls">
            <span class="uneditable-input"><code>{$mapping.canonical_code}</code></span>
        </div>
    </div>

    <hr>

    {* Editable Fields *}
    <div class="control-group">
        <label class="control-label" for="display_name_en">{__("travel_core.fm_display_en")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[display_name_en]" id="display_name_en" value="{$mapping.display_name_en|escape:'html'}" size="50" />
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="display_name_ro">{__("travel_core.fm_display_ro")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[display_name_ro]" id="display_name_ro" value="{$mapping.display_name_ro|escape:'html'}" size="50" />
        </div>
    </div>

    <hr>

    {* CS-Cart Feature Dropdown *}
    <div class="control-group">
        <label class="control-label" for="cscart_feature_id">{__("travel_core.fm_cs_feature")}:</label>
        <div class="controls">
            <select name="mapping_data[cscart_feature_id]" id="cscart_feature_id" onchange="loadVariants(this.value)">
                <option value="0">-- {__("travel_core.fm_not_mapped")} --</option>
                {foreach from=$all_features item=f}
                    {assign var="type_label" value=""}
                    {if $f.feature_type == 'M'}{assign var="type_label" value="Multi"}
                    {elseif $f.feature_type == 'S'}{assign var="type_label" value="Select"}
                    {elseif $f.feature_type == 'C'}{assign var="type_label" value="Checkbox"}
                    {elseif $f.feature_type == 'T'}{assign var="type_label" value="Text"}
                    {elseif $f.feature_type == 'N'}{assign var="type_label" value="Number"}
                    {elseif $f.feature_type == 'O'}{assign var="type_label" value="Date"}
                    {else}{assign var="type_label" value=$f.feature_type}{/if}
                    <option value="{$f.feature_id}" {if $mapping.cscart_feature_id == $f.feature_id}selected{/if}>
                        {$f.description|escape:'html'|default:"Feature"} #{$f.feature_id} ({$type_label})
                    </option>
                {/foreach}
            </select>
            <p class="muted">{__("travel_core.fm_feature_hint")}</p>
        </div>
    </div>

    {* CS-Cart Variant Dropdown (AJAX-loaded) *}
    <div class="control-group">
        <label class="control-label" for="cscart_variant_id">{__("travel_core.fm_variant")}:</label>
        <div class="controls">
            <select name="mapping_data[cscart_variant_id]" id="cscart_variant_id">
                <option value="0">-- {__("travel_core.fm_not_mapped")} --</option>
                {if $feature_variants}
                    {foreach from=$feature_variants item=v}
                        <option value="{$v.variant_id}" {if $mapping.cscart_variant_id == $v.variant_id}selected{/if}>
                            #{$v.variant_id} &mdash; {$v.name}
                        </option>
                    {/foreach}
                {/if}
            </select>
            <p class="muted">{__("travel_core.fm_variant_hint")}</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="position">{__("position")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[position]" id="position" value="{$mapping.position}" size="5" />
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("status")}:</label>
        <div class="controls">
            <select name="mapping_data[status]">
                <option value="A" {if $mapping.status == 'A'}selected{/if}>{__("active")}</option>
                <option value="D" {if $mapping.status == 'D'}selected{/if}>{__("disabled")}</option>
            </select>
        </div>
    </div>

    <hr>

    {* Metadata (read-only) *}
    <div class="control-group">
        <label class="control-label">Source:</label>
        <div class="controls">
            <span class="label {if $mapping.mapping_source == 'seed'}label-info{elseif $mapping.mapping_source == 'auto'}label-warning{else}label-success{/if}">
                {$mapping.mapping_source|default:'seed'}
            </span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">Variant Lock:</label>
        <div class="controls">
            {if $mapping.variant_source == 'manual'}
                <span class="label label-important"><i class="icon-lock"></i> Manual — auto-resolve will not overwrite</span>
            {else}
                <span class="label label-default"><i class="icon-unlock"></i> Auto — can be auto-resolved</span>
            {/if}
        </div>
    </div>

    {if $mapping.last_used_at}
    <div class="control-group">
        <label class="control-label">Last Used:</label>
        <div class="controls">
            <span class="muted">{$mapping.last_used_at|date_format:"%Y-%m-%d %H:%M"}</span>
        </div>
    </div>
    {/if}

    <div class="buttons-container">
        <button type="submit" class="btn btn-primary">
            <i class="icon-ok"></i> {__("save")}
        </button>
        <a href="{"travel_feature_mappings.manage?feature_type=`$mapping.feature_type`"|fn_url}" class="btn">{__("cancel")}</a>
    </div>
</form>

<hr>

{* Aliases Section *}
<h4>{__("travel_core.fm_aliases")} ({$aliases|@count})</h4>

{if $aliases}
<table class="table table-striped table-condensed">
    <thead>
        <tr>
            <th width="50">ID</th>
            <th>{__("travel_core.fm_api_source")}</th>
            <th>{__("travel_core.fm_api_value")}</th>
            <th>{__("travel_core.fm_match_type")}</th>
            <th width="80">{__("tools")}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$aliases item=alias}
        <tr>
            <td>{$alias.alias_id}</td>
            <td><span class="label">{$alias.api_source|escape:'html'}</span></td>
            <td><code>{$alias.api_value|escape:'html'}</code></td>
            <td>{$alias.match_type}</td>
            <td>
                <form action="{"travel_feature_mappings.delete_alias"|fn_url}" method="post" style="display:inline;">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <input type="hidden" name="alias_id" value="{$alias.alias_id}">
                    <input type="hidden" name="map_id" value="{$mapping.map_id}">
                    <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Delete this alias?');" title="{__("delete")}">
                        <i class="icon-trash"></i>
                    </button>
                </form>
            </td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
<p class="muted">{__("travel_core.fm_no_aliases")}</p>
{/if}

{* Add Alias Form *}
<form action="{"travel_feature_mappings.add_alias"|fn_url}" method="post" class="form-inline" style="margin-top: 10px;">
    <input type="hidden" name="security_hash" value="{$security_hash}">
    <input type="hidden" name="map_id" value="{$mapping.map_id}">
    <input type="text" name="api_source" placeholder="{__("travel_core.fm_api_source")}" size="15" required />
    <input type="text" name="api_value" placeholder="{__("travel_core.fm_api_value")}" size="30" required />
    <select name="match_type">
        <option value="exact">exact</option>
        <option value="prefix">prefix</option>
        <option value="contains">contains</option>
    </select>
    <button type="submit" class="btn btn-mini btn-primary">
        <i class="icon-plus"></i> {__("travel_core.fm_add_alias")}
    </button>
</form>

{/capture}

{$_fm_label = {__('travel_core.fm_edit_mapping')}}
{$_fm_title = "`$_fm_label`: `$mapping.canonical_code`"}

{include file="common/mainbox.tpl"
    title=$_fm_title
    content=$smarty.capture.mainbox
}

<script>
function loadVariants(featureId) {
    var select = document.getElementById('cscart_variant_id');
    select.innerHTML = '<option value="0">Loading...</option>';

    if (!featureId || featureId == '0') {
        select.innerHTML = '<option value="0">-- {__("travel_core.fm_not_mapped")} --</option>';
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '{"travel_feature_mappings.get_variants"|fn_url}' + '&feature_id=' + featureId, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var variants = JSON.parse(xhr.responseText);
            select.innerHTML = '';
            var defaultOpt = document.createElement('option');
            defaultOpt.value = '0';
            defaultOpt.textContent = '-- {__("travel_core.fm_not_mapped")|escape:'javascript'} --';
            select.appendChild(defaultOpt);
            for (var i = 0; i < variants.length; i++) {
                var opt = document.createElement('option');
                opt.value = variants[i].variant_id;
                opt.textContent = '#' + variants[i].variant_id + ' \u2014 ' + variants[i].name;
                select.appendChild(opt);
            }
        }
    };
    xhr.send();
}
</script>
