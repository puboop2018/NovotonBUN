{** Novoton Holidays - Enhanced SEO Templates settings section **}

{style src="addons/travel_core/seo-templates.css"}

<style>
.addon-settings-seo_templates input[type="text"],
.addon-settings-seo_templates textarea {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}
.addon-settings-seo_templates .control-group .controls {
    max-width: 100%;
}
</style>

<div class="seo-tpl-wrapper" data-seo-wrapper>

    {* ── Sidebar: Placeholders + Modifiers + Tips ── *}
    <div class="seo-tpl-sidebar">

        {* Placeholders *}
        <div class="well well-small" style="background: #f8f9fa;">
            <h5>{__("travel_core.seo_placeholders_title")}</h5>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}name{rdelim}{rdelim}">name</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_name")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}raw_name{rdelim}{rdelim}">raw_name</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_raw_name")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}city{rdelim}{rdelim}">city</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_city")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}country{rdelim}{rdelim}">country</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_country")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}region{rdelim}{rdelim}">region</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_region")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}star_rating{rdelim}{rdelim}">star_rating</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_star_rating")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}stars_emoji{rdelim}{rdelim}">stars_emoji</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_stars_emoji")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}hotel_type{rdelim}{rdelim}">hotel_type</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_hotel_type")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}property_type{rdelim}{rdelim}">property_type</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_property_type")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}year{rdelim}{rdelim}">year</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_year")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}description{rdelim}{rdelim}">description</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_description")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}facilities{rdelim}{rdelim}">facilities</span> <span class="seo-ph-desc">- {__("novoton_holidays.ph_facilities")}</span></div>
            <div class="seo-ph-item"><span class="seo-ph-badge" data-insert="{ldelim}{ldelim}latitude{rdelim}{rdelim}">latitude</span> / <span class="seo-ph-badge" data-insert="{ldelim}{ldelim}longitude{rdelim}{rdelim}">longitude</span> <span class="seo-ph-desc">- GPS</span></div>
        </div>

        {* Modifiers *}
        <div class="well well-small" style="background: #f8f9fa;">
            <h5>{__("travel_core.seo_modifiers_title")}</h5>
            <div style="margin-bottom: 8px; font-size: 11px; color: #6c757d;">
                {__("travel_core.seo_modifiers_example")}:
                <code style="background: #e9ecef; padding: 2px 5px; border-radius: 3px; font-size: 11px;">{ldelim}{ldelim}name|lower{rdelim}{rdelim}</code>
            </div>
            <span class="seo-mod-badge" data-modifier="lower">lower</span>
            <span class="seo-mod-badge" data-modifier="upper">upper</span>
            <span class="seo-mod-badge" data-modifier="title">title</span>
            <span class="seo-mod-badge" data-modifier="capitalize">capitalize</span>
            <span class="seo-mod-badge" data-modifier="trim">trim</span>
            <span class="seo-mod-badge" data-modifier="slug">slug</span>
            <span class="seo-mod-badge" data-modifier="strip_tags">strip_tags</span>
            <span class="seo-mod-badge" data-modifier="first">first</span>
            <span class="seo-mod-badge" data-modifier="last">last</span>
            <span class="seo-mod-badge" data-modifier="abs">abs</span>
            <span class="seo-mod-badge" data-modifier="round">round</span>
        </div>

        {* Tips *}
        <div class="well well-small seo-tips">
            <h5><i class="icon-lightbulb"></i> {__("travel_core.seo_tips_title")}</h5>
            <ul>
                <li>{__("travel_core.seo_tip_1")}</li>
                <li>{__("travel_core.seo_tip_2")}</li>
                <li>{__("travel_core.seo_tip_3")}</li>
                <li>{__("travel_core.seo_tip_4")}</li>
            </ul>
        </div>

    </div>
</div>

{* ── Bulk Apply bar ── *}
<div class="seo-bulk-bar">
    <form action="{"novoton_admin.bulk_seo_apply"|fn_url}" method="post" style="display: inline; margin: 0;">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <button type="submit" class="btn btn-warning cm-comet"
                onclick="return confirm('{__("travel_core.seo_bulk_apply_confirm")|escape:"javascript"}');">
            <i class="icon-refresh"></i> {__("travel_core.seo_bulk_apply_button")}
        </button>
    </form>
    <p>{__("travel_core.seo_bulk_apply_desc")}</p>
</div>

{script src="addons/travel_core/seo-click-insert.js"}
