{*
 * Novoton Feature Mapping - Edit Single Mapping
 *}

{capture name="mainbox"}

<form action="{"novoton_feature_mappings.update"|fn_url}" method="post" class="form-horizontal">
    <input type="hidden" name="security_hash" value="{$security_hash}">
    <input type="hidden" name="mapping_id" value="{$mapping.mapping_id}">

    {* Read-only Info *}
    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_mapping_id")}:</label>
        <div class="controls">
            <span class="uneditable-input">{$mapping.mapping_id}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_provider")}:</label>
        <div class="controls">
            <span class="uneditable-input">{$mapping.provider}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_feature_type")}:</label>
        <div class="controls">
            <span class="uneditable-input">{$mapping.feature_type}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_provider_code")}:</label>
        <div class="controls">
            <span class="uneditable-input"><code>{$mapping.provider_code}</code></span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_source")}:</label>
        <div class="controls">
            <span class="label {if $mapping.mapping_source == 'auto'}label-warning{elseif $mapping.mapping_source == 'manual'}label-info{/if}">
                {$mapping.mapping_source}
            </span>
        </div>
    </div>

    <hr>

    {* CS-Cart Feature Info *}
    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_cs_cart_feature")}:</label>
        <div class="controls">
            {if $feature_info}
                <strong>{$feature_info.description}</strong> (ID: {$feature_info.feature_id}, Type: {$feature_info.feature_type})
            {elseif $mapping.cs_cart_feature_id > 0}
                <span class="label label-important">Feature #{$mapping.cs_cart_feature_id} {__("novoton_holidays.fm_not_found_in_cscart")}</span>
            {else}
                <span class="muted">{__("novoton_holidays.fm_not_configured")}</span>
            {/if}
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_variant_id")}:</label>
        <div class="controls">
            {if $variant_info}
                <span class="label label-success">#{$variant_info.variant_id}</span> &mdash; {$variant_info.variant}
            {elseif $mapping.cs_cart_variant_id > 0}
                <span class="label label-warning">#{$mapping.cs_cart_variant_id} ({__("novoton_holidays.fm_variant_missing")})</span>
            {else}
                <span class="muted">{__("novoton_holidays.fm_will_be_created")}</span>
            {/if}
        </div>
    </div>

    <hr>

    {* Editable Fields *}
    <div class="control-group">
        <label class="control-label" for="display_name_en">{__("novoton_holidays.fm_display_en")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[display_name_en]" id="display_name_en" value="{$mapping.display_name_en}" size="50" />
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="display_name_ro">{__("novoton_holidays.fm_display_ro")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[display_name_ro]" id="display_name_ro" value="{$mapping.display_name_ro}" size="50" />
            <p class="muted">{__("novoton_holidays.fm_display_ro_hint")}</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="cs_cart_feature_id">{__("novoton_holidays.fm_cs_cart_feature_id")}:</label>
        <div class="controls">
            <input type="text" name="mapping_data[cs_cart_feature_id]" id="cs_cart_feature_id" value="{$mapping.cs_cart_feature_id}" size="10" />
            <p class="muted">{__("novoton_holidays.fm_feature_id_hint")}</p>
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
            <select name="mapping_data[is_active]">
                <option value="Y" {if $mapping.is_active == 'Y'}selected{/if}>{__("active")}</option>
                <option value="N" {if $mapping.is_active == 'N'}selected{/if}>{__("disabled")}</option>
            </select>
        </div>
    </div>

    <hr>

    {* Timestamps *}
    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_created_at")}:</label>
        <div class="controls">
            <span class="muted">{$mapping.created_at}</span>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label">{__("novoton_holidays.fm_last_synced")}:</label>
        <div class="controls">
            <span class="muted">{$mapping.last_synced_at|default:__("never")}</span>
        </div>
    </div>

    <div class="buttons-container">
        <button type="submit" class="btn btn-primary">
            <i class="icon-ok"></i> {__("save")}
        </button>
        <a href="{"novoton_feature_mappings.manage"|fn_url}" class="btn">{__("cancel")}</a>
    </div>
</form>

{/capture}

{include file="common/mainbox.tpl"
    title="{__('novoton_holidays.fm_edit_mapping')}: {$mapping.provider_code}"
    content=$smarty.capture.mainbox
}
