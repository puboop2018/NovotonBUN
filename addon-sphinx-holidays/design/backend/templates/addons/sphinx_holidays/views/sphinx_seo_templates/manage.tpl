{**
 * Sphinx Holidays — SEO Templates admin page
 *
 * Dedicated page for managing hotel SEO template strings, placeholders,
 * and the bulk-apply action. Layout is split: form on the left, shared
 * placeholder / modifier sidebar on the right.
 *}

{style src="addons/travel_core/seo-templates.css"}

<style>
#sphinx_seo_form input[type="text"],
#sphinx_seo_form textarea {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
#sphinx_seo_form .control-group { margin-bottom: 18px; }
#sphinx_seo_form .control-label {
    text-align: left;
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}
#sphinx_seo_form .control-label .field-toggle {
    margin-right: 6px;
    vertical-align: middle;
}
#sphinx_seo_form .help-block {
    font-size: 11px;
    color: #6c757d;
    margin-top: 2px;
}
#sphinx_seo_form .seo-lang-section { border-top: 2px solid #e3e6ea; margin-top: 24px; padding-top: 14px; }
#sphinx_seo_form .seo-lang-heading { margin: 0 0 12px; }
#sphinx_seo_form .seo-lang-code { color: #6c757d; font-weight: 400; font-size: 13px; text-transform: uppercase; }
#sphinx_seo_form .seo-field-toggles .seo-field-toggle { margin-right: 14px; }
#sphinx_seo_form .seo-field-toggles-hint { font-size: 11px; margin: 4px 0 8px; }
#sphinx_seo_form .seo-lang-fallback-hint { font-size: 12px; }
</style>

{capture name="mainbox"}

<form method="post"
      action="{"sphinx_seo_templates.save"|fn_url}"
      name="sphinx_seo_form"
      id="sphinx_seo_form"
      class="form-horizontal form-edit">
    <input type="hidden" name="security_hash" value="{$security_hash}" />

    <div class="row-fluid">

        {* ── LEFT: Form ── *}
        <div class="span8">

            <h4 style="margin-top: 0;">{__("travel_core.seo_templates")|default:"SEO Templates"}</h4>
            <p class="muted" style="font-size: 12px; margin-bottom: 20px;">
                {__("travel_core.seo_templates_hint")|default:"Use the placeholders listed in the sidebar to build reusable SEO templates. Values are applied when products are created or via Bulk Apply."}
            </p>

            {* Overwrite mode + bulk apply bar *}
            {*
             * The bulk-apply button uses HTML5 formaction to override the
             * parent form's action, so we don't need a nested <form> (which
             * is invalid HTML and was causing the bulk button to submit the
             * save action instead of bulk_apply).
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
                        formaction="{"sphinx_seo_templates.bulk_apply"|fn_url}"
                        formmethod="post"
                        onclick="return confirm('{__("travel_core.seo_bulk_apply_confirm")|default:"Re-apply SEO templates to all existing Sphinx products?"|escape:"javascript"}');">
                    <i class="icon-refresh"></i>
                    {__("travel_core.seo_bulk_apply_button")|default:"Bulk Apply to Existing Products"}
                </button>
                <p>{__("travel_core.seo_bulk_apply_desc")|default:"Re-renders the templates for all existing hotel products that are already linked to Sphinx records."}</p>
            </div>

            {* Per-language template fields + global field toggles — shared
               travel_core component: ONE implementation for both providers,
               posting seo_lang[<lang>][<key>] per storefront language. *}
            {include file="addons/travel_core/components/seo_lang_fields.tpl"}

        </div>

        {* ── RIGHT: Placeholder + Modifier sidebar (sticky on scroll) ── *}
        <div class="span4 seo-tpl-wrapper" data-seo-wrapper>
            <div class="seo-tpl-sidebar seo-tpl-sidebar-sticky">

                {* Placeholders *}
                <div class="well well-small" style="background: #f8f9fa;">
                    <h5>{__("sphinx_holidays.seo_placeholders_title")|default:"Sphinx placeholders to use"}</h5>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}name{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_name")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}classification{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_classification")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}city{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_city")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}country{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_country")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}region{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_region")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}property_type{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_property_type")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}facilities{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_facilities")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}boards{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_boards")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}rating{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_rating")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}description{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_description")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}address{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_address")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}phone{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_phone")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}email{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_email")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}website{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_website")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}image_url{rdelim}{rdelim}</span> <span class="seo-ph-desc">- {__("sphinx_holidays.ph_image_url")}</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}stars_emoji{rdelim}{rdelim}</span> <span class="seo-ph-desc">- Stars (e.g. ★★★★)</span></div>
                    <div class="seo-ph-item"><span class="seo-ph-badge">{ldelim}{ldelim}year{rdelim}{rdelim}</span> <span class="seo-ph-desc">- Current year</span></div>
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

{* Load the editor script INSIDE the capture: this page is reached via the
   admin top-nav (an AJAX navigation), and CS-Cart's AJAX response only
   returns the captured mainbox content. A {script src=} placed before the
   capture is dropped on AJAX nav, so the per-field enable/disable toggles
   never wire up. Keeping it here mirrors the novoton SEO page. *}
{script src="addons/travel_core/seo-click-insert.js"}

{/capture}

{capture name="buttons"}
    <button type="submit"
            form="sphinx_seo_form"
            name="dispatch[sphinx_seo_templates.save]"
            class="btn btn-primary">
        {__("save")}
    </button>
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.seo_templates")|default:"SEO Templates"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
