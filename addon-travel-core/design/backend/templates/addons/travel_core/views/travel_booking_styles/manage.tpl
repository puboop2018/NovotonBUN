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
                               value="{$color.value|default:$color.default|default:'#000000'}"
                               style="width: 40px; height: 34px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; padding: 2px;"
                               onchange="document.getElementById('appearance_{$color.id}').value = this.value;" />

                        <input type="text"
                               id="appearance_{$color.id}"
                               name="appearance[{$color.id}]"
                               value="{$color.value}"
                               placeholder="{$color.default|default:__("travel_core.theme_default")}"
                               class="input-medium"
                               maxlength="7"
                               style="width: 100px; font-family: monospace;"
                               onchange="var v = this.value.trim(); if (v && /^#[0-9a-fA-F]{ldelim}6{rdelim}$/.test(v)) document.getElementById('picker_{$color.id}').value = v;" />

                        {if $color.value}
                        <a href="#" class="btn btn-mini"
                           title="{__("travel_core.reset_to_default")}"
                           onclick="document.getElementById('appearance_{$color.id}').value = ''; document.getElementById('picker_{$color.id}').value = '{$color.default|default:"#000000"}'; return false;">
                            <i class="icon-refresh"></i>
                        </a>
                        {/if}
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

</form>

{/capture}

{capture name="buttons"}
    {include file="buttons/save.tpl" but_name="dispatch[travel_booking_styles.save]" but_role="submit-button"}
{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.appearance_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
