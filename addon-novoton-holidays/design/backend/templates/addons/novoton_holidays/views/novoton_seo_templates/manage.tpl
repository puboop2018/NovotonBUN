{**
 * Novoton Holidays — SEO Templates admin page
 *
 * Dedicated page for managing hotel SEO template strings, placeholders,
 * and the bulk-apply action.
 *}

{style src="addons/travel_core/seo-templates.css"}

<style>
#novoton_seo_form input[type="text"],
#novoton_seo_form textarea {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
#novoton_seo_form .control-group { margin-bottom: 18px; }
#novoton_seo_form .control-label {
    text-align: left;
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}
#novoton_seo_form .control-label .field-toggle {
    margin-right: 6px;
    vertical-align: middle;
}
#novoton_seo_form .help-block {
    font-size: 11px;
    color: #6c757d;
    margin-top: 2px;
}
</style>

{capture name="mainbox"}

<form method="post"
      action="{"novoton_seo_templates.save"|fn_url}"
      name="novoton_seo_form"
      id="novoton_seo_form"
      class="form-horizontal form-edit">
    <input type="hidden" name="security_hash" value="{$security_hash}" />

    <div class="row-fluid">

        {* ── LEFT: Form ── *}
        <div class="span8">

            <h4 style="margin-top: 0;">{__("travel_core.seo_templates")|default:"SEO Templates"}</h4>
            <p class="muted" style="font-size: 12px; margin-bottom: 20px;">
                {__("travel_core.seo_templates_hint")|default:"Use the placeholders listed in the sidebar to build reusable SEO templates."}
            </p>

            {* Overwrite mode + bulk apply bar *}
            {*
             * Uses HTML5 formaction to override the parent form's action,
             * so we don't nest <form> tags (which is invalid HTML).
             *}
            <div class="seo-bulk-bar">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label for="seo_overwrite_mode" style="font-weight: 600; white-space: nowrap; margin: 0;">
                        {__("travel_core.seo_overwrite_mode")|default:"Overwrite mode"}:
                    </label>
                    <select name="seo[seo_overwrite_mode]"
                            id="seo_overwrite_mode"
                            class="input-medium"
                            style="margin-bottom: 0;">
                        <option value="override_all"{if $seo_values.seo_overwrite_mode == 'override_all'} selected="selected"{/if}>
                            {__("travel_core.seo_override_all")|default:"Override all"}
                        </option>
                        <option value="fill_if_empty"{if $seo_values.seo_overwrite_mode == 'fill_if_empty'} selected="selected"{/if}>
                            {__("travel_core.seo_fill_if_empty")|default:"Fill if empty"}
                        </option>
                    </select>
                </div>
                <button type="submit"
                        class="btn btn-warning cm-comet"
                        formaction="{"novoton_seo_templates.bulk_apply"|fn_url}"
                        formmethod="post"
                        onclick="return confirm('{__("travel_core.seo_bulk_apply_confirm")|default:"Re-apply SEO templates to all existing Novoton products?"|escape:"javascript"}');">
                    <i class="icon-refresh"></i>
                    {__("travel_core.seo_bulk_apply_button")|default:"Bulk Apply to Existing Products"}
                </button>
                <p>{__("travel_core.seo_bulk_apply_desc")|default:"Re-renders the templates for all existing hotel products that are already linked to Novoton records."}</p>
            </div>

            {* Product Name *}
            <div class="control-group">
                <label class="control-label" for="seo_product_name">
                    <input type="checkbox"
                           name="seo[seo_field_product_name]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_product_name"
                           {if $seo_values.seo_field_product_name != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_product_name")|default:"Product name template"}
                </label>
                <div class="controls">
                    <input type="text"
                           id="seo_product_name"
                           name="seo[seo_product_name]"
                           data-seo-ideal="80"
                           data-seo-max="255"
                           value="{$seo_values.seo_product_name|escape:html}" />
                    <p class="help-block">{__("travel_core.seo_product_name_desc")|default:"Template for the product name."}</p>
                </div>
            </div>

            {* Page Title *}
            <div class="control-group">
                <label class="control-label" for="seo_page_title">
                    <input type="checkbox"
                           name="seo[seo_field_page_title]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_page_title"
                           {if $seo_values.seo_field_page_title != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_page_title")|default:"Page title template"}
                </label>
                <div class="controls">
                    <input type="text"
                           id="seo_page_title"
                           name="seo[seo_page_title]"
                           data-seo-ideal="60"
                           data-seo-max="255"
                           value="{$seo_values.seo_page_title|escape:html}" />
                    <p class="help-block">{__("travel_core.seo_page_title_desc")|default:"Template for the HTML page title. Google typically truncates around 60 characters."}</p>
                </div>
            </div>

            {* Meta Description *}
            <div class="control-group">
                <label class="control-label" for="seo_meta_description">
                    <input type="checkbox"
                           name="seo[seo_field_meta_description]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_meta_description"
                           {if $seo_values.seo_field_meta_description != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_meta_description")|default:"Meta description template"}
                </label>
                <div class="controls">
                    <textarea id="seo_meta_description"
                              name="seo[seo_meta_description]"
                              data-seo-ideal="160"
                              data-seo-max="500"
                              rows="3">{$seo_values.seo_meta_description|escape:html}</textarea>
                    <p class="help-block">{__("travel_core.seo_meta_description_desc")|default:"Template for the meta description tag. Google truncates around 160 characters."}</p>
                </div>
            </div>

            {* Meta Keywords *}
            <div class="control-group">
                <label class="control-label" for="seo_meta_keywords">
                    <input type="checkbox"
                           name="seo[seo_field_meta_keywords]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_meta_keywords"
                           {if $seo_values.seo_field_meta_keywords != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_meta_keywords")|default:"Meta keywords template"}
                </label>
                <div class="controls">
                    <input type="text"
                           id="seo_meta_keywords"
                           name="seo[seo_meta_keywords]"
                           data-seo-ideal="200"
                           data-seo-max="255"
                           value="{$seo_values.seo_meta_keywords|escape:html}" />
                    <p class="help-block">{__("travel_core.seo_meta_keywords_desc")|default:"Template for the meta keywords tag."}</p>
                </div>
            </div>

            {* SEO Name Slug *}
            <div class="control-group">
                <label class="control-label" for="seo_name_slug">
                    <input type="checkbox"
                           name="seo[seo_field_name_slug]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_name_slug"
                           {if $seo_values.seo_field_name_slug != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_name_slug")|default:"SEO URL slug template"}
                </label>
                <div class="controls">
                    <input type="text"
                           id="seo_name_slug"
                           name="seo[seo_name_slug]"
                           data-seo-ideal="80"
                           data-seo-max="255"
                           value="{$seo_values.seo_name_slug|escape:html}" />
                    <p class="help-block">{__("travel_core.seo_name_slug_desc")|default:"Template for the SEO-friendly URL slug."}</p>
                </div>
            </div>

            {* Full Description *}
            <div class="control-group">
                <label class="control-label" for="seo_full_description">
                    <input type="checkbox"
                           name="seo[seo_field_full_description]"
                           value="Y"
                           class="field-toggle"
                           data-seo-toggle="seo_full_description"
                           {if $seo_values.seo_field_full_description != 'N'}checked="checked"{/if} />
                    {__("travel_core.seo_full_description")|default:"Full description template (optional)"}
                </label>
                <div class="controls">
                    <textarea id="seo_full_description"
                              name="seo[seo_full_description]"
                              rows="4">{$seo_values.seo_full_description|escape:html}</textarea>
                    <p class="help-block">{__("travel_core.seo_full_description_desc")|default:"Optional template to wrap or replace the API description."}</p>
                </div>
            </div>

        </div>

        {* ── RIGHT: Placeholder + Modifier sidebar (sticky on scroll) ── *}
        <div class="span4 seo-tpl-wrapper" data-seo-wrapper>
            <div class="seo-tpl-sidebar seo-tpl-sidebar-sticky">

                {* Placeholders *}
                <div class="well well-small" style="background: #f8f9fa;">
                    <h5>{__("novoton_holidays.seo_placeholders_title")|default:"Novoton placeholders to use"}</h5>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}name{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_name")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}raw_name{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_raw_name")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}city{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_city")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}country{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_country")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}region{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_region")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}star_rating{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_star_rating")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}stars_emoji{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_stars_emoji")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}hotel_type{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_hotel_type")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}property_type{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_property_type")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}year{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_year")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}description{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_description")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}facilities{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_facilities")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}latitude{rdelim}{rdelim}</span> / <span class="seo-ph-badge">{ldelim}{ldelim}longitude{rdelim}{rdelim}</span> <span class="seo-ph-desc">- GPS</span></div>
                </div>

                {* Modifiers *}
                <div class="well well-small" style="background: #f8f9fa;">
                    <h5>{__("travel_core.seo_modifiers_title")|default:"Modifiers"}</h5>
                    <div style="margin-bottom: 8px; font-size: 11px; color: #6c757d;">
                        {__("travel_core.seo_modifiers_example")|default:"Example"}:
                        <code style="background: #e9ecef; padding: 2px 5px; border-radius: 3px; font-size: 11px;">{ldelim}{ldelim}name|lower{rdelim}{rdelim}</code>
                    </div>
                    <span class="seo-mod-badge">|lower</span>
                    <span class="seo-mod-badge">|upper</span>
                    <span class="seo-mod-badge">|title</span>
                    <span class="seo-mod-badge">|capitalize</span>
                    <span class="seo-mod-badge">|trim</span>
                    <span class="seo-mod-badge">|slug</span>
                    <span class="seo-mod-badge">|strip_tags</span>
                    <span class="seo-mod-badge">|first</span>
                    <span class="seo-mod-badge">|last</span>
                    <span class="seo-mod-badge">|abs</span>
                    <span class="seo-mod-badge">|round</span>
                </div>

                {* Tips *}
                <div class="well well-small seo-tips">
                    <h5><i class="icon-lightbulb"></i> {__("travel_core.seo_tips_title")|default:"Tips"}</h5>
                    <ul>
                        <li>{__("travel_core.seo_tip_1")|default:"Type a placeholder token from the list on the right into any field."}</li>
                        <li>{__("travel_core.seo_tip_2")|default:"Append a modifier after the placeholder name, inside the braces."}</li>
                        <li>{__("travel_core.seo_tip_3")|default:"Uncheck a field to skip it on import."}</li>
                        <li>{__("travel_core.seo_tip_4")|default:"Use Bulk Apply to re-render all existing products."}</li>
                    </ul>
                </div>

            </div>
        </div>
    </div>

</form>

{script src="addons/travel_core/seo-click-insert.js"}

{/capture}

{capture name="buttons"}
    <button type="submit"
            form="novoton_seo_form"
            name="dispatch[novoton_seo_templates.save]"
            class="btn btn-primary">
        {__("save")}
    </button>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.seo_templates")|default:"SEO Templates"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
