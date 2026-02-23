{if !empty($product.extra.novoton_booking)}
{* Calculate totals for multi-room *}
{$total_adults = 0}
{$total_children = 0}
{$total_rooms = $product.extra.num_rooms|default:1}
{$check_in_display = $product.extra.check_in}
{$check_out_display = $product.extra.check_out}
{$nights_count = $product.extra.nights|default:7}

{if $product.extra.rooms_data}
    {foreach from=$product.extra.rooms_data item=room}
        {$total_adults = $total_adults + ($room.adults|default:2)}
        {$total_children = $total_children + ($room.children|default:0)}
    {/foreach}
{else}
    {$total_adults = $product.extra.adults|default:2}
    {$total_children = $product.extra.children|default:0}
{/if}

<div class="novoton-booking-card" style="margin-top: 15px; border: 1px solid #e0e7ef; border-radius: 8px; overflow: hidden; font-size: 13px;">
    
    {* Separator line *}
    <div style="height: 4px; background: linear-gradient(90deg, #003580, #0071c2);"></div>
    
    {* Header: Your booking details *}
    <div style="padding: 14px; background: #f8fafc; border-bottom: 1px solid #e0e7ef;">
        <div style="font-size: 15px; font-weight: 600; color: #1a1a1a; margin-bottom: 10px;">{__("novoton_holidays.your_booking_details")}</div>
        
        {* Check-in / Check-out dates *}
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div>
                <div style="font-size: 11px; color: #666; margin-bottom: 3px;">{__("novoton_holidays.check_in")}</div>
                <div style="font-weight: 600; color: #003580; font-size: 13px;">
                    {$check_in_display|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 11px; color: #666; margin-bottom: 3px;">{__("novoton_holidays.check_out")}</div>
                <div style="font-weight: 600; color: #003580; font-size: 13px;">
                    {$check_out_display|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 11px; color: #666; margin-bottom: 3px;">{__("novoton_holidays.total_stay")}</div>
                <div style="font-weight: 600; color: #1a1a1a; font-size: 13px;">
                    {$nights_count} {if $nights_count == 1}{__("novoton_holidays.night")}{else}{__("novoton_holidays.nights")}{/if}
                </div>
            </div>
        </div>
    </div>
    
    {* Separator and room summary *}
    <div style="padding: 10px 14px; background: #fff; border-bottom: 1px solid #e0e7ef;">
        <div style="font-weight: 600; color: #1a1a1a; font-size: 13px;">
            {$total_rooms} {if $total_rooms == 1}{__("novoton_holidays.room")}{else}{__("novoton_holidays.rooms")}{/if} {__("novoton_holidays.for")} {$total_adults} {if $total_adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $total_children > 0}, {$total_children} {if $total_children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
        </div>
    </div>
    
    {* Room cards *}
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        {* Multi-room: First expanded, rest collapsed *}
        {* Parse guests data once *}
        {$all_guests_parsed = null}
        {if $product.extra.guests_data}
            {$all_guests_parsed = $product.extra.guests_data|@json_decode:true}
        {/if}
        
        {foreach from=$product.extra.rooms_data item=room key=idx}
            {$room_number = $idx + 1}
            {$is_first = ($idx == 0)}
            {$collapse_id = "checkout_room_`$key`_`$idx`"}
            
            <div class="novoton-room-card" style="border-bottom: 1px solid #e0e7ef;">
                {* Room header - clickable for collapse *}
                <div onclick="toggleCheckoutRoom('{$collapse_id}')" style="padding: 10px 14px; background: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="font-weight: 600; color: #1a1a1a; font-size: 13px;">{__("novoton_holidays.room")} {$room_number}: {$room.room_type_display|default:$room.room_name|escape:html}</span>
                        <span style="color: #666; margin-left: 8px; font-size: 12px;">{$room.price|default:0|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
                    </div>
                    <span id="{$collapse_id}_icon" style="font-size: 16px; color: #666;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s; transform: {if $is_first}rotate(180deg){else}rotate(0deg){/if}"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
                </div>
                
                {* Room details - collapsible *}
                <div id="{$collapse_id}" style="padding: 0 14px 14px; background: #fff; {if !$is_first}display: none;{/if}">
                    {* Occupancy *}
                    <div style="margin-bottom: 8px; font-size: 12px;">
                        <span style="color: #666; text-transform: uppercase;">{__("novoton_holidays.occupancy")}:</span>
                        <span style="margin-left: 6px;">{$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0}, {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if} ({$room.children_ages_str}){/if}</span>
                    </div>
                    
                    {* Guest names for this room - match by room property *}
                    {if $all_guests_parsed}
                        <div style="margin-bottom: 8px; font-size: 12px;">
                            <div style="color: #666; text-transform: uppercase; margin-bottom: 4px;">{__("novoton_holidays.guest_names")}:</div>
                            {$guest_num = 1}
                            {foreach from=$all_guests_parsed item=guest}
                                {if $guest.room == $room_number}
                                    <div style="margin-left: 8px; margin-bottom: 3px;">
                                        {$guest_num}. <strong>{$guest.last_name|default:''}{if $guest.last_name && $guest.first_name}, {/if}{$guest.first_name|default:$guest.name|default:''}</strong>
                                        {if $guest.type == 'child'}
                                            <span style="color: #666;">({__("novoton_holidays.child")}, {$guest.age} {__("novoton_holidays.years_old")})</span>
                                        {else}
                                            <span style="color: #666;">({__("novoton_holidays.adult")})</span>
                                        {/if}
                                        {if $guest.is_holder}
                                            <span style="background: #003580; color: #fff; font-size: 9px; padding: 1px 4px; border-radius: 2px; margin-left: 4px;">{__("novoton_holidays.holder")}</span>
                                        {/if}
                                    </div>
                                    {$guest_num = $guest_num + 1}
                                {/if}
                            {/foreach}
                        </div>
                    {/if}
                    
                    {* Meal plan *}
                    <div style="background: #f0f7ff; padding: 8px; border-radius: 4px; display: flex; align-items: center; gap: 8px; font-size: 12px;">
                        <span style="font-size: 16px;"></span>
                        <span><strong>{__("novoton_holidays.meal_plan")}:</strong> {$room.board_name|escape:html}</span>
                    </div>
                </div>
            </div>
        {/foreach}
    {else}
        {* Single room - always expanded *}
        <div class="novoton-room-card" style="padding: 14px; background: #fff;">
            {* Room type *}
            <div style="font-weight: 600; color: #1a1a1a; margin-bottom: 10px; font-size: 14px;">
                {$product.extra.room_type_display|default:$product.extra.room_name|escape:html}
            </div>
            
            {* Occupancy *}
            <div style="margin-bottom: 8px; font-size: 12px;">
                <span style="color: #666; text-transform: uppercase;">{__("novoton_holidays.occupancy")}:</span>
                <span style="margin-left: 6px;">{$product.extra.adults|default:2} {__("novoton_holidays.adults")}{if $product.extra.children > 0}, {$product.extra.children} {__("novoton_holidays.children")} ({$product.extra.children_ages}){/if}</span>
            </div>
            
            {* Guest names *}
            {if $product.extra.guests_data}
                {$guests = $product.extra.guests_data|@json_decode:true}
                {if $guests}
                    <div style="margin-bottom: 8px; font-size: 12px;">
                        <div style="color: #666; text-transform: uppercase; margin-bottom: 4px;">{__("novoton_holidays.guest_names")}:</div>
                        {$guest_num = 1}
                        {foreach from=$guests item=guest}
                            <div style="margin-left: 8px; margin-bottom: 3px;">
                                {$guest_num}. <strong>{$guest.last_name|default:''}{if $guest.last_name && $guest.first_name}, {/if}{$guest.first_name|default:$guest.name|default:''}</strong>
                                {if $guest.type == 'child'}
                                    <span style="color: #666;">({__("novoton_holidays.child")}, {$guest.age} {__("novoton_holidays.years_old")})</span>
                                {else}
                                    <span style="color: #666;">({__("novoton_holidays.adult")})</span>
                                {/if}
                                {if $guest.is_holder}
                                    <span style="background: #003580; color: #fff; font-size: 9px; padding: 1px 4px; border-radius: 2px; margin-left: 4px;">{__("novoton_holidays.holder")}</span>
                                {/if}
                            </div>
                            {$guest_num = $guest_num + 1}
                        {/foreach}
                    </div>
                {/if}
            {elseif $product.extra.holder_name}
                <div style="margin-bottom: 8px; font-size: 12px;">
                    <span style="color: #666; text-transform: uppercase;">{__("novoton_holidays.guest_names")}:</span>
                    <span style="margin-left: 6px;">{$product.extra.holder_name|escape:html}</span>
                </div>
            {/if}
            
            {* Meal plan *}
            <div style="background: #f0f7ff; padding: 8px; border-radius: 4px; display: flex; align-items: center; gap: 8px; font-size: 12px;">
                <span style="font-size: 16px;"></span>
                <span><strong>{__("novoton_holidays.meal_plan")}:</strong> {$product.extra.board_name|escape:html}</span>
            </div>
        </div>
    {/if}
    
    {* Edit link *}
    {if $product.extra.novoton_booking_id}
        <div style="padding: 10px 14px; background: #f8fafc; border-top: 1px solid #e0e7ef; text-align: center;">
            <a href="{"novoton_booking.edit_booking?booking_id=`$product.extra.novoton_booking_id`&cart_id=`$key`"|fn_url}" style="color: #0071c2; font-size: 12px; text-decoration: none;">
                 {__("novoton_holidays.edit_guest_details")}
            </a>
        </div>
    {/if}
</div>

{* JavaScript for room collapse toggle - only add once *}
{if !$novoton_checkout_js_added}
{$novoton_checkout_js_added = true}
{literal}
<script>
function toggleCheckoutRoom(id) {
    var content = document.getElementById(id);
    var icon = document.getElementById(id + '_icon');
    var svg = icon.querySelector('svg');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        if (svg) svg.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        if (svg) svg.style.transform = 'rotate(0deg)';
    }
}
</script>
{/literal}
{/if}
{/if}
