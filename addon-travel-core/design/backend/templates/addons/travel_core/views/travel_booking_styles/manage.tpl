{** Travel Core - Booking Form Appearance Settings **}

{capture name="mainbox"}

<form method="post" action="{"travel_booking_styles.save"|fn_url}" name="appearance_form" class="form-horizontal form-edit">
    <input type="hidden" name="security_hash" value="{$security_hash}" />

    {foreach $color_groups as $group_key => $group}
        {if $group.colors}
        <div class="control-group-wrapper" style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #e5e5e5;">{$group.title}</h4>

            {foreach $group.colors as $color}
            <div class="control-group">
                <label class="control-label" for="appearance_{$color.id}">
                    {$color.label}
                </label>
                <div class="controls">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="color"
                               id="picker_{$color.id}"
                               data-css-var="{$color.var}"
                               value="{$color.value|default:$color.default|default:'#000000'}"
                               style="width: 40px; height: 34px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; padding: 2px;"
                               oninput="document.getElementById('appearance_{$color.id}').value = this.value; travelUpdatePreview();"
                               onchange="document.getElementById('appearance_{$color.id}').value = this.value; travelUpdatePreview();" />

                        <input type="text"
                               id="appearance_{$color.id}"
                               name="appearance[{$color.id}]"
                               data-css-var="{$color.var}"
                               value="{$color.value}"
                               placeholder="{$color.default|default:__("travel_core.theme_default")}"
                               class="input-medium"
                               maxlength="7"
                               style="width: 100px; font-family: monospace;"
                               oninput="var v = this.value.trim(); if (v && /^#[0-9a-fA-F]{ldelim}6{rdelim}$/.test(v)) {ldelim} document.getElementById('picker_{$color.id}').value = v; travelUpdatePreview(); {rdelim}" />

                        <a href="#" class="btn btn-mini"
                           title="{__("travel_core.reset_to_default")}"
                           onclick="document.getElementById('appearance_{$color.id}').value = ''; document.getElementById('picker_{$color.id}').value = '{$color.default|default:"#000000"}'; travelUpdatePreview(); return false;">
                            <i class="icon-refresh"></i>
                        </a>
                    </div>
                    <p class="muted" style="margin-top: 4px; font-size: 11px;">
                        CSS: <code>{$color.var}</code>
                        {if $color.default} &middot; {__("travel_core.default")}: <code>{$color.default}</code>{/if}
                        {if !$color.default} &middot; <em>{__("travel_core.inherited_from_theme")}</em>{/if}
                    </p>
                </div>
            </div>
            {/foreach}
        </div>
        {/if}
    {/foreach}

    {* ── Live Preview ── *}
    <div style="margin-top: 20px; padding: 20px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 6px;">
        <h4 style="margin-top: 0;">{__("travel_core.appearance_preview")}</h4>
        <div id="travel-color-preview" style="
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            background: var(--preview-bg, #fff);
            border-radius: 8px;
            padding: 16px;
        ">
            {* Availability header mockup *}
            <div style="margin-bottom: 10px;">
                <span id="preview-title" style="font-size: 16px; font-weight: 700; color: var(--preview-primary, #003580);">
                    {__("travel_core.availability")|default:"Check availability"}
                </span>
            </div>

            {* Form row mockup *}
            <div id="preview-form-row" style="
                display: flex; gap: 3px; padding: 3px;
                border: 3px solid var(--preview-accent, #febb02);
                border-radius: 8px;
                background: var(--preview-accent, #febb02);
            ">
                <div style="flex: 2; background: var(--preview-bg, #fff); border-radius: 5px; padding: 8px 12px;">
                    <span style="font-size: 10px; text-transform: uppercase; color: var(--preview-text-light, #6b6b6b); font-weight: 600;">
                        {__("travel_core.check_in")|default:"Check-in"} &mdash; {__("travel_core.check_out")|default:"Check-out"}
                    </span><br>
                    <span style="font-size: 13px; color: var(--preview-text, #1a1a1a);">15 Jul &rarr; 22 Jul</span>
                </div>
                <div style="flex: 1; background: var(--preview-bg, #fff); border-radius: 5px; padding: 8px 12px;">
                    <span style="font-size: 10px; text-transform: uppercase; color: var(--preview-text-light, #6b6b6b); font-weight: 600;">
                        {__("travel_core.rooms")|default:"Rooms"}
                    </span><br>
                    <span style="font-size: 13px; color: var(--preview-text, #1a1a1a);">2 {__("travel_core.adults")|default:"adults"}</span>
                </div>
                <div style="flex: 0.5; display: flex; align-items: stretch;">
                    <div id="preview-btn" style="
                        display: flex; align-items: center; justify-content: center;
                        width: 100%; border-radius: 5px; font-weight: 600; font-size: 14px;
                        background: var(--preview-search-btn-bg, #006ce4);
                        color: var(--preview-search-btn-text, #fff);
                        padding: 8px 16px;
                    ">
                        {__("travel_core.search")|default:"Search"}
                    </div>
                </div>
            </div>

            {* Calendar price mockup *}
            <div style="margin-top: 12px; display: flex; gap: 12px; font-size: 12px;">
                <span style="color: var(--preview-cal-cheapest, #2e7d32);">&#9679; 245 &euro; ({__("travel_core.default")|default:"lowest"})</span>
                <span style="color: var(--preview-cal-price, #4B5563);">&#9679; 380 &euro;</span>
                <span style="color: var(--preview-danger, #d32f2f);">&#9679; {__("travel_core.color_danger")|default:"Error text"}</span>
            </div>
        </div>
    </div>

</form>

<script>
function travelUpdatePreview() {ldelim}
    var preview = document.getElementById('travel-color-preview');
    if (!preview) return;
    var map = {ldelim}
        '--nvt-primary': '--preview-primary',
        '--nvt-accent': '--preview-accent',
        '--nvt-text': '--preview-text',
        '--nvt-text-light': '--preview-text-light',
        '--nvt-bg': '--preview-bg',
        '--nvt-border': '--preview-border',
        '--nvt-search-btn-bg': '--preview-search-btn-bg',
        '--nvt-search-btn-hover': '--preview-search-btn-hover',
        '--nvt-search-btn-text': '--preview-search-btn-text',
        '--nvt-cal-cheapest-color': '--preview-cal-cheapest',
        '--nvt-cal-price-color': '--preview-cal-price',
        '--nvt-danger': '--preview-danger'
    {rdelim};
    document.querySelectorAll('input[type="color"][data-css-var]').forEach(function(picker) {ldelim}
        var cssVar = picker.getAttribute('data-css-var');
        var textInput = picker.parentNode.querySelector('input[type="text"]');
        var value = textInput && textInput.value.trim() ? textInput.value.trim() : picker.value;
        var previewVar = map[cssVar];
        if (previewVar && value) {ldelim}
            preview.style.setProperty(previewVar, value);
        {rdelim}
    {rdelim});
{rdelim}
// Initialize preview on page load
document.addEventListener('DOMContentLoaded', travelUpdatePreview);
</script>

{/capture}

{capture name="buttons"}
    {include file="buttons/save.tpl" but_name="dispatch[travel_booking_styles.save]" but_role="submit-button"}
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.appearance_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
