{if !empty($product.extra.novoton_booking)}
{assign var="total_adults" value=0}
{assign var="total_children" value=0}
{assign var="total_rooms" value=$product.extra.num_rooms|default:1}
{assign var="check_in_display" value=$product.extra.check_in}
{assign var="check_out_display" value=$product.extra.check_out}
{assign var="nights_count" value=$product.extra.nights|default:7}

{if $product.extra.rooms_data}
    {foreach from=$product.extra.rooms_data item=room}
        {assign var="total_adults" value=$total_adults+$room.adults|default:2}
        {assign var="total_children" value=$total_children+$room.children|default:0}
    {/foreach}
{else}
    {assign var="total_adults" value=$product.extra.adults|default:2}
    {assign var="total_children" value=$product.extra.children|default:0}
{/if}

<div class="novoton-booking-card" style="margin-top: 15px; border: 1px solid #e0e7ef; border-radius: 8px; overflow: hidden; font-size: 14px;">
    
    {* Separator line *}
    <div style="height: 4px; background: linear-gradient(90deg, #003580, #0071c2);"></div>
    
    {* Header: Your booking details *}
    <div style="padding: 16px; background: #f8fafc; border-bottom: 1px solid #e0e7ef;">
        <div style="font-size: 16px; font-weight: 600; color: #1a1a1a; margin-bottom: 12px;">{__("novoton_holidays.your_booking_details")|default:"Your booking details"}</div>
        
        {* Check-in / Check-out dates *}
        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.check_in")|default:"Check-in"}</div>
                <div style="font-weight: 600; color: #003580;">
                    {$check_in_display|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.check_out")|default:"Check-out"}</div>
                <div style="font-weight: 600; color: #003580;">
                    {$check_out_display|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.total_stay")|default:"Total stay"}</div>
                <div style="font-weight: 600; color: #1a1a1a;">
                    {$nights_count} {if $nights_count == 1}{__("novoton_holidays.night")|default:"night"}{else}{__("novoton_holidays.nights")|default:"nights"}{/if}
                </div>
            </div>
        </div>
    </div>
    
    {* Separator and room summary *}
    <div style="padding: 12px 16px; background: #fff; border-bottom: 1px solid #e0e7ef;">
        <div style="font-weight: 600; color: #1a1a1a;">
            {$total_rooms} {if $total_rooms == 1}{__("novoton_holidays.room")|default:"room"}{else}{__("novoton_holidays.rooms")|default:"rooms"}{/if} {__("novoton_holidays.for")|default:"for"} {$total_adults} {if $total_adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")|default:"adults"}{/if}{if $total_children > 0}, {$total_children} {if $total_children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")|default:"children"}{/if}{/if}
        </div>
    </div>
    
    {* Room cards *}
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        {* Multi-room: First expanded, rest collapsed *}
        {foreach from=$product.extra.rooms_data item=room key=idx}
            {assign var="room_number" value=$idx+1}
            {assign var="is_first" value=($idx == 0)}
            {assign var="collapse_id" value="room_collapse_`$key`_`$idx`"}
            
            <div class="novoton-room-card" style="border-bottom: 1px solid #e0e7ef;">
                {* Room header - clickable for collapse *}
                <div onclick="toggleRoomCard('{$collapse_id}')" style="padding: 12px 16px; background: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex: 1;">
                        <span style="font-weight: 600; color: #1a1a1a;">{__("novoton_holidays.room")|default:"Room"} {$room_number}: {$room.room_type_display|default:$room.room_name|default:$room.room_id}</span>
                    </div>
                    {* Price aligned to right *}
                    {if $product.extra.num_rooms > 1}
                        <span style="color: #003580; font-weight: 600; margin-left: 15px; margin-right: 10px; white-space: nowrap;">{$room.price|default:0|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
                    {/if}
                    <span id="{$collapse_id}_icon" style="font-size: 18px; color: #666; transition: transform 0.3s;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s; transform: {if $is_first}rotate(180deg){else}rotate(0deg){/if}"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
                </div>
                
                {* Room details - collapsible *}
                <div id="{$collapse_id}" style="padding: 0 16px 16px; background: #fff; {if !$is_first}display: none;{/if}">
                    {* Occupancy *}
                    <div style="margin-bottom: 10px;">
                        <span style="font-size: 12px; color: #666; text-transform: uppercase;">{__("novoton_holidays.occupancy")|default:"Occupancy"}:</span>
                        <span style="margin-left: 8px;">{$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")|default:"adults"}{/if}{if $room.children > 0}, {$room.children} {if $room.children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")|default:"children"}{/if} ({$room.children_ages_str}){/if}</span>
                    </div>
                    
                    {* Guest names for this room *}
                    {if $product.extra.guests_data}
                        {assign var="all_guests" value=null}
                        {if is_string($product.extra.guests_data)}
                            {assign var="all_guests" value=$product.extra.guests_data|json_decode:true}
                        {else}
                            {assign var="all_guests" value=$product.extra.guests_data}
                        {/if}
                        {if $all_guests && is_array($all_guests)}
                            <div style="margin-bottom: 10px;">
                                <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 6px;">{__("novoton_holidays.guest_names")|default:"Guest names"}:</div>
                                {assign var="guest_num" value=1}
                                {foreach from=$all_guests item=guest}
                                    {if isset($guest.room) && $guest.room == $room_number}
                                        {assign var="display_name" value=$guest.name|default:"`$guest.last_name` `$guest.first_name`"|trim|default:"Guest `$guest_num`"}
                                        <div style="margin-left: 10px; margin-bottom: 4px;">
                                            {$guest_num}. <strong>{$display_name}</strong>
                                            {if $guest.type == 'child'}
                                                {if $guest.age > 0 && $guest.age < 18}
                                                    <span style="color: #666;">({__("novoton_holidays.child")|default:"child"}, {$guest.age} {__("novoton_holidays.years_old")|default:"years old"})</span>
                                                {else}
                                                    <span style="color: #666;">({__("novoton_holidays.child")|default:"child"})</span>
                                                {/if}
                                            {else}
                                                <span style="color: #666;">({__("novoton_holidays.adult")|default:"adult"})</span>
                                            {/if}
                                            {if $guest.is_holder}
                                                <span style="background: #003580; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 3px; margin-left: 5px;">{__("novoton_holidays.holder")|default:"Holder"}</span>
                                            {/if}
                                        </div>
                                        {assign var="guest_num" value=$guest_num+1}
                                    {/if}
                                {/foreach}
                            </div>
                        {/if}
                    {/if}
                    
                    {* Meal plan *}
                    <div style="background: #f0f7ff; padding: 10px; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 18px;"></span>
                        <span><strong>{__("novoton_holidays.meal_plan")|default:"Meal plan"}:</strong> {$room.board_name|default:$room.board_id}</span>
                    </div>
                </div>
            </div>
        {/foreach}
    {else}
        {* Single room - always expanded *}
        <div class="novoton-room-card" style="padding: 16px; background: #fff;">
            {* Room type *}
            <div style="font-weight: 600; color: #1a1a1a; margin-bottom: 12px; font-size: 15px;">
                {$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id}
            </div>
            
            {* Occupancy *}
            <div style="margin-bottom: 10px;">
                <span style="font-size: 12px; color: #666; text-transform: uppercase;">{__("novoton_holidays.occupancy")|default:"Occupancy"}:</span>
                <span style="margin-left: 8px;">{$product.extra.adults|default:2} {__("novoton_holidays.adults")|default:"adults"}{if $product.extra.children > 0}, {$product.extra.children} {__("novoton_holidays.children")|default:"children"} ({$product.extra.children_ages}){/if}</span>
            </div>
            
            {* Guest names *}
            {if $product.extra.guests_data}
                {assign var="guests" value=null}
                {if is_string($product.extra.guests_data)}
                    {assign var="guests" value=$product.extra.guests_data|json_decode:true}
                {else}
                    {assign var="guests" value=$product.extra.guests_data}
                {/if}
                {if $guests && is_array($guests)}
                    <div style="margin-bottom: 10px;">
                        <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 6px;">{__("novoton_holidays.guest_names")|default:"Guest names"}:</div>
                        {assign var="guest_num" value=1}
                        {foreach from=$guests item=guest}
                            <div style="margin-left: 10px; margin-bottom: 4px;">
                                {assign var="display_name" value=$guest.name|default:"`$guest.last_name` `$guest.first_name`"|trim|default:"Guest `$guest_num`"}
                                {$guest_num}. <strong>{$display_name}</strong>
                                {if $guest.type == 'child'}
                                    {if $guest.age > 0 && $guest.age < 18}
                                        <span style="color: #666;">({__("novoton_holidays.child")|default:"child"}, {$guest.age} {__("novoton_holidays.years_old")|default:"years old"})</span>
                                    {else}
                                        <span style="color: #666;">({__("novoton_holidays.child")|default:"child"})</span>
                                    {/if}
                                {else}
                                    <span style="color: #666;">({__("novoton_holidays.adult")|default:"adult"})</span>
                                {/if}
                                {if $guest.is_holder}
                                    <span style="background: #003580; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 3px; margin-left: 5px;">{__("novoton_holidays.holder")|default:"Holder"}</span>
                                {/if}
                                {if $guest.room}
                                    <span style="color: #999; font-size: 11px; margin-left: 5px;">({__("novoton_holidays.room")|default:"Room"} {$guest.room})</span>
                                {/if}
                            </div>
                            {assign var="guest_num" value=$guest_num+1}
                        {/foreach}
                    </div>
                {/if}
            {elseif $product.extra.holder_name}
                <div style="margin-bottom: 10px;">
                    <span style="font-size: 12px; color: #666; text-transform: uppercase;">{__("novoton_holidays.guest_names")|default:"Guest names"}:</span>
                    <span style="margin-left: 8px;">{$product.extra.holder_name}</span>
                </div>
            {/if}
            
            {* Meal plan *}
            <div style="background: #f0f7ff; padding: 10px; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 18px;"></span>
                <span><strong>{__("novoton_holidays.meal_plan")|default:"Meal plan"}:</strong> {$product.extra.board_name|default:$product.extra.board_id}</span>
            </div>
        </div>
    {/if}
    
    {* Edit link *}
    {if $product.extra.novoton_booking_id}
        <div style="padding: 12px 16px; background: #f8fafc; border-top: 1px solid #e0e7ef; text-align: center;">
            <a href="{"novoton_booking.edit_booking?booking_id=`$product.extra.novoton_booking_id`&cart_id=`$key`"|fn_url}" style="color: var(--nvt-info, #0071c2); font-size: 13px; text-decoration: none;">
                 {__("novoton_holidays.edit_guest_details")|default:"Edit guest details"}
            </a>
        </div>
    {/if}
</div>

{* JavaScript for room collapse toggle *}
{literal}
<script>
function toggleRoomCard(id) {
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
