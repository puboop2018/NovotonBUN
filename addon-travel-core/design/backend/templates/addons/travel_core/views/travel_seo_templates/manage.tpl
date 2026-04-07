{** Travel Core - SEO Templates Management **}

{capture name="mainbox"}

{if $seo_addons}

<form method="post" action="{"travel_seo_templates.save"|fn_url}" name="seo_templates_form" id="seo_templates_form" class="form-horizontal form-edit">
    <input type="hidden" name="security_hash" value="{$security_hash}" />

    <div class="row-fluid">
        {* ── LEFT: Template form ── *}
        <div class="span8">

            {* Tabs for each addon *}
            {if $seo_addons|count > 1}
            <ul class="nav nav-tabs">
                {foreach $seo_addons as $addon_id => $addon}
                <li class="{if $addon_id == $active_tab}active{/if}">
                    <a href="#tab_{$addon_id}" data-toggle="tab">{$addon.label}</a>
                </li>
                {/foreach}
            </ul>
            {/if}

            <div class="tab-content">
                {foreach $seo_addons as $addon_id => $addon}
                <div class="tab-pane {if $addon_id == $active_tab}active{/if}" id="tab_{$addon_id}">

                    <h4 style="margin-top: 15px; margin-bottom: 5px;">{$addon.label} &mdash; {__("travel_core.seo_templates")}</h4>
                    <p class="muted" style="margin-bottom: 20px; font-size: 12px;">{__("travel_core.seo_templates_hint")}</p>

                    {* Product Name *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_product_name">
                            {__("travel_core.seo_product_name")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_product_name"
                                      name="{$addon_id}[seo_product_name]"
                                      class="input-large"
                                      rows="2"
                                      style="width: 95%;">{$addon.settings.seo_product_name}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_product_name_desc")|escape:"html"}</p>
                        </div>
                    </div>

                    {* Page Title *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_page_title">
                            {__("travel_core.seo_page_title")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_page_title"
                                      name="{$addon_id}[seo_page_title]"
                                      class="input-large"
                                      rows="2"
                                      style="width: 95%;">{$addon.settings.seo_page_title}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_page_title_desc")|escape:"html"}</p>
                        </div>
                    </div>

                    {* Meta Description *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_meta_description">
                            {__("travel_core.seo_meta_description")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_meta_description"
                                      name="{$addon_id}[seo_meta_description]"
                                      class="input-large"
                                      rows="3"
                                      style="width: 95%;">{$addon.settings.seo_meta_description}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_meta_description_desc")|escape:"html"}</p>
                        </div>
                    </div>

                    {* Meta Keywords *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_meta_keywords">
                            {__("travel_core.seo_meta_keywords")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_meta_keywords"
                                      name="{$addon_id}[seo_meta_keywords]"
                                      class="input-large"
                                      rows="2"
                                      style="width: 95%;">{$addon.settings.seo_meta_keywords}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_meta_keywords_desc")|escape:"html"}</p>
                        </div>
                    </div>

                    {* SEO Name (slug) *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_name_slug">
                            {__("travel_core.seo_name_slug")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_name_slug"
                                      name="{$addon_id}[seo_name_slug]"
                                      class="input-large"
                                      rows="2"
                                      style="width: 95%;">{$addon.settings.seo_name_slug}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_name_slug_desc")|escape:"html"}</p>
                        </div>
                    </div>

                    {* Full description template (optional) *}
                    <div class="control-group">
                        <label class="control-label" for="{$addon_id}_seo_full_description">
                            {__("travel_core.seo_full_description")}
                        </label>
                        <div class="controls">
                            <textarea id="{$addon_id}_seo_full_description"
                                      name="{$addon_id}[seo_full_description]"
                                      class="input-large"
                                      rows="4"
                                      style="width: 95%;">{$addon.settings.seo_full_description}</textarea>
                            <p class="muted" style="font-size: 11px;">{__("travel_core.seo_full_description_desc")|escape:"html"}</p>
                        </div>
                    </div>

                </div>
                {/foreach}
            </div>

        </div>

        {* ── RIGHT: Sidebar with placeholders & modifiers ── *}
        <div class="span4">

            {* Placeholders panel *}
            <div class="well well-small" style="background: #f8f9fa; margin-bottom: 15px;">
                <h5 style="margin-top: 0; padding-bottom: 6px; border-bottom: 1px solid #dee2e6;">
                    {__("travel_core.seo_placeholders_title")}
                </h5>

                {foreach $seo_addons as $addon_id => $addon}
                {if $seo_addons|count > 1}
                <div class="seo-placeholder-group" data-addon="{$addon_id}" style="margin-bottom: 12px;">
                    <strong style="font-size: 11px; text-transform: uppercase; color: #6c757d;">{$addon.label}</strong>
                </div>
                {/if}
                <div class="seo-placeholder-list" data-addon="{$addon_id}" style="margin-bottom: 15px;">
                    {foreach $addon.placeholders as $ph_key => $ph_desc}
                    <div style="margin-bottom: 4px; font-size: 12px; line-height: 1.5;">
                        <span class="label label-info seo-insert-tag" style="cursor: pointer; font-family: monospace; font-size: 11px;"
                              data-insert="{ldelim}{ldelim}{$ph_key}{rdelim}{rdelim}"
                              title="{__("travel_core.seo_click_to_insert")}">{$ph_key}</span>
                        <span class="muted" style="font-size: 11px;"> - {$ph_desc}</span>
                    </div>
                    {/foreach}
                </div>
                {/foreach}
            </div>

            {* Modifiers panel *}
            <div class="well well-small" style="background: #f8f9fa;">
                <h5 style="margin-top: 0; padding-bottom: 6px; border-bottom: 1px solid #dee2e6;">
                    {__("travel_core.seo_modifiers_title")}
                </h5>

                <div style="margin-bottom: 10px; font-size: 12px; color: #6c757d;">
                    {__("travel_core.seo_modifiers_example")}:
                    <code style="background: #e9ecef; padding: 2px 5px; border-radius: 3px; font-size: 11px;">{ldelim}{ldelim} name|lower {rdelim}{rdelim}</code>
                </div>

                {foreach $seo_modifiers as $mod_name => $mod_desc}
                <div style="margin-bottom: 4px; font-size: 12px; line-height: 1.5;">
                    <span class="label label-success seo-insert-modifier" style="cursor: pointer; font-family: monospace; font-size: 11px;"
                          data-modifier="{$mod_name}"
                          title="{__("travel_core.seo_click_to_insert_modifier")}">{$mod_name}</span>
                    <span class="muted" style="font-size: 11px;"> - {$mod_desc}</span>
                </div>
                {/foreach}
            </div>

            {* Quick tips *}
            <div class="well well-small" style="background: #fff3cd; border-color: #ffc107;">
                <h5 style="margin-top: 0; padding-bottom: 6px; border-bottom: 1px solid #ffc107; color: #856404;">
                    <i class="icon-lightbulb"></i> {__("travel_core.seo_tips_title")}
                </h5>
                <ul style="font-size: 11px; color: #856404; padding-left: 18px; margin-bottom: 0;">
                    <li style="margin-bottom: 4px;">{__("travel_core.seo_tip_1")}</li>
                    <li style="margin-bottom: 4px;">{__("travel_core.seo_tip_2")}</li>
                    <li style="margin-bottom: 4px;">{__("travel_core.seo_tip_3")}</li>
                    <li>{__("travel_core.seo_tip_4")}</li>
                </ul>
            </div>

        </div>
    </div>

</form>

{else}
    <div class="alert alert-warning">
        <strong>{__("warning")}</strong>: {__("travel_core.seo_no_addons")}
    </div>
{/if}

{* ── Click-to-insert JavaScript ── *}
<script>
{literal}
(function() {
    // Track the last focused textarea and its cursor position
    var lastField = null;
    var lastPos = 0;

    // Listen for focus/click/keyup on all SEO textareas to track cursor
    var form = document.getElementById('seo_templates_form');
    if (!form) return;

    form.addEventListener('focus', function(e) {
        if (e.target.tagName === 'TEXTAREA') {
            lastField = e.target;
            lastPos = e.target.selectionStart || 0;
        }
    }, true);

    form.addEventListener('click', function(e) {
        if (e.target.tagName === 'TEXTAREA') {
            lastField = e.target;
            // Delay to let browser update selection
            setTimeout(function() { lastPos = e.target.selectionStart || 0; }, 0);
        }
    }, true);

    form.addEventListener('keyup', function(e) {
        if (e.target.tagName === 'TEXTAREA') {
            lastField = e.target;
            lastPos = e.target.selectionStart || 0;
        }
    }, true);

    // Insert text at cursor position in the tracked textarea
    function insertAtCursor(text) {
        if (!lastField) {
            // No field focused yet — focus the first textarea in the active tab
            var activeTab = form.querySelector('.tab-pane.active') || form;
            lastField = activeTab.querySelector('textarea');
            if (!lastField) return;
            lastPos = lastField.value.length;
        }

        lastField.focus();
        var val = lastField.value;
        var selStart = lastField.selectionStart;
        var selEnd = lastField.selectionEnd;

        // If there's a selection, replace it; otherwise insert at cursor
        lastField.value = val.substring(0, selStart) + text + val.substring(selEnd);

        // Move cursor to end of inserted text
        var newPos = selStart + text.length;
        lastField.selectionStart = newPos;
        lastField.selectionEnd = newPos;
        lastPos = newPos;

        // Flash the field briefly to confirm insertion
        lastField.style.transition = 'background-color 0.15s';
        lastField.style.backgroundColor = '#d4edda';
        setTimeout(function() { lastField.style.backgroundColor = ''; }, 300);
    }

    // ── Placeholder badges: click to insert {{placeholder}} at cursor ──
    document.addEventListener('click', function(e) {
        var tag = e.target.closest('.seo-insert-tag');
        if (tag) {
            e.preventDefault();
            insertAtCursor(tag.getAttribute('data-insert'));
            return;
        }

        // ── Modifier badges: click to append |modifier to nearest {{placeholder}} ──
        var mod = e.target.closest('.seo-insert-modifier');
        if (mod) {
            e.preventDefault();
            var modName = mod.getAttribute('data-modifier');

            if (!lastField) return;

            // Find the placeholder token nearest (before) the cursor and append the modifier
            var val = lastField.value;
            var pos = lastField.selectionStart || lastPos;
            var before = val.substring(0, pos);

            // Find last {{ before cursor that doesn't already have this modifier
            var openIdx = before.lastIndexOf('{{');
            if (openIdx === -1) {
                // No placeholder before cursor — insert standalone
                insertAtCursor('|' + modName);
                return;
            }

            // Find the closing }} after the opening {{
            var closeIdx = val.indexOf('}}', openIdx);
            if (closeIdx === -1) {
                insertAtCursor('|' + modName);
                return;
            }

            // Extract the token content between {{ and }}
            var tokenContent = val.substring(openIdx + 2, closeIdx);

            // Check if modifier already present
            if (tokenContent.indexOf('|' + modName) !== -1) return;

            // Insert |modifier right before the }}
            var insertPos = closeIdx;
            lastField.selectionStart = insertPos;
            lastField.selectionEnd = insertPos;
            insertAtCursor('|' + modName);
            return;
        }
    });
})();
{/literal}
</script>

{/capture}

{capture name="buttons"}
    {if $seo_addons}
    <button type="submit" form="seo_templates_form" name="dispatch[travel_seo_templates.save]" class="btn btn-primary">
        {__("save")}
    </button>
    {/if}
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.seo_templates_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
