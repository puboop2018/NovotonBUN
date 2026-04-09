{** Novoton Holidays - Enhanced SEO Templates settings section **}
{** Adds: clickable placeholder sidebar, modifiers reference, click-to-insert JS, bulk-apply button **}

<style>
.seo-tpl-wrapper {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}
.seo-tpl-sidebar {
    flex: 0 0 260px;
    min-width: 220px;
}
.seo-tpl-sidebar .well {
    margin-bottom: 12px;
}
.seo-tpl-sidebar h5 {
    margin-top: 0;
    padding-bottom: 6px;
    border-bottom: 1px solid #dee2e6;
}
.seo-ph-badge {
    display: inline-block;
    cursor: pointer;
    font-family: monospace;
    font-size: 11px;
    padding: 2px 6px;
    margin: 2px 3px 2px 0;
    border-radius: 3px;
    background: #d9edf7;
    color: #31708f;
    border: 1px solid #bce8f1;
    transition: background 0.15s;
}
.seo-ph-badge:hover {
    background: #31708f;
    color: #fff;
}
.seo-mod-badge {
    display: inline-block;
    cursor: pointer;
    font-family: monospace;
    font-size: 11px;
    padding: 2px 6px;
    margin: 2px 3px 2px 0;
    border-radius: 3px;
    background: #dff0d8;
    color: #3c763d;
    border: 1px solid #d6e9c6;
    transition: background 0.15s;
}
.seo-mod-badge:hover {
    background: #3c763d;
    color: #fff;
}
.seo-ph-desc {
    font-size: 11px;
    color: #666;
    margin-left: 2px;
}
.seo-ph-item {
    margin-bottom: 4px;
    font-size: 12px;
    line-height: 1.6;
}
.seo-tips {
    background: #fff3cd;
    border-color: #ffc107;
}
.seo-tips h5 {
    border-bottom-color: #ffc107;
    color: #856404;
}
.seo-tips ul {
    font-size: 11px;
    color: #856404;
    padding-left: 18px;
    margin-bottom: 0;
}
.seo-tips li {
    margin-bottom: 4px;
}
.seo-bulk-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #f0f4f8;
    border-radius: 6px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.seo-bulk-bar p {
    font-size: 11px;
    margin: 0;
    flex-basis: 100%;
    color: #6c757d;
}
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

<div class="seo-tpl-wrapper" id="seo_tpl_novoton">

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

{* ── Click-to-insert JavaScript ── *}
<script>
{literal}
(function() {
    var wrapper = document.getElementById('seo_tpl_novoton');
    if (!wrapper) return;

    var section = wrapper.closest('.addon-settings-seo_templates') || wrapper.parentNode;

    var lastField = null;
    var lastPos = 0;

    section.addEventListener('focus', function(e) {
        if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
            lastField = e.target;
            lastPos = e.target.selectionStart || 0;
        }
    }, true);

    section.addEventListener('click', function(e) {
        if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
            lastField = e.target;
            setTimeout(function() { lastPos = e.target.selectionStart || 0; }, 0);
        }
    }, true);

    section.addEventListener('keyup', function(e) {
        if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
            lastField = e.target;
            lastPos = e.target.selectionStart || 0;
        }
    }, true);

    function insertAtCursor(text) {
        if (!lastField) {
            lastField = section.querySelector('input[type="text"], textarea');
            if (!lastField) return;
            lastPos = lastField.value.length;
        }

        lastField.focus();
        var val = lastField.value;
        var selStart = lastField.selectionStart;
        var selEnd = lastField.selectionEnd;

        lastField.value = val.substring(0, selStart) + text + val.substring(selEnd);

        var newPos = selStart + text.length;
        lastField.selectionStart = newPos;
        lastField.selectionEnd = newPos;
        lastPos = newPos;

        lastField.style.transition = 'background-color 0.15s';
        lastField.style.backgroundColor = '#d4edda';
        setTimeout(function() { lastField.style.backgroundColor = ''; }, 300);
    }

    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.seo-ph-badge');
        if (badge && wrapper.contains(badge)) {
            e.preventDefault();
            insertAtCursor(badge.getAttribute('data-insert'));
            return;
        }

        var mod = e.target.closest('.seo-mod-badge');
        if (mod && wrapper.contains(mod)) {
            e.preventDefault();
            var modName = mod.getAttribute('data-modifier');

            if (!lastField) return;

            var val = lastField.value;
            var pos = lastField.selectionStart || lastPos;
            var before = val.substring(0, pos);

            var openIdx = before.lastIndexOf('{{');
            if (openIdx === -1) {
                insertAtCursor('|' + modName);
                return;
            }

            var closeIdx = val.indexOf('}}', openIdx);
            if (closeIdx === -1) {
                insertAtCursor('|' + modName);
                return;
            }

            var tokenContent = val.substring(openIdx + 2, closeIdx);
            if (tokenContent.indexOf('|' + modName) !== -1) return;

            lastField.selectionStart = closeIdx;
            lastField.selectionEnd = closeIdx;
            insertAtCursor('|' + modName);
        }
    });
})();
{/literal}
</script>
