{*
    Room body of the shared cart booking-details card: occupancy line, guest
    names (with child ages + holder badge) and the meal plan. Used by
    cart_booking_details.tpl for both layouts:
      cbd_room=<room array>, cbd_room_number=N  — multi-room (guests filtered to N)
      cbd_room=null,        cbd_room_number=0  — single room (fields from extras)
    Every line renders only when its data exists — providers that don't
    supply a detail simply omit it.
*}
{if $cbd_room}
    {$_cbd_adults = $cbd_room.adults|default:2}
    {$_cbd_children = $cbd_room.children|default:0}
    {$_cbd_ages = $cbd_room.children_ages_str|default:''}
    {$_cbd_board = $cbd_room.board_name|default:$cbd_room.board_id|default:''}
{else}
    {$_cbd_adults = $cbd_extra.adults|default:2}
    {$_cbd_children = $cbd_extra.children|default:0}
    {$_cbd_ages = $cbd_extra.children_ages|default:''}
    {$_cbd_board = $cbd_extra.board_name|default:$cbd_extra.board_id|default:''}
{/if}

{* Occupancy *}
<div style="margin-bottom: 10px;">
    <span style="font-size: 12px; color: #666; text-transform: uppercase;">{__("travel_core.occupancy")|default:"Occupancy"}:</span>
    <span style="margin-left: 8px;">{$_cbd_adults} {if $_cbd_adults == 1}{__("travel_core.adult")|default:"adult"}{else}{__("travel_core.adults")|default:"adults"}{/if}{if $_cbd_children > 0}, {$_cbd_children} {if $_cbd_children == 1}{__("travel_core.child")|default:"child"}{else}{__("travel_core.children")|default:"children"}{/if}{if $_cbd_ages} ({$_cbd_ages}){/if}{/if}</span>
</div>

{* Guest names *}
{if $cbd_extra.guests_data}
    {$_cbd_guests = null}
    {if is_string($cbd_extra.guests_data)}
        {$_cbd_guests = $cbd_extra.guests_data|json_decode:true}
    {else}
        {$_cbd_guests = $cbd_extra.guests_data}
    {/if}
    {if $_cbd_guests && is_array($_cbd_guests)}
        <div style="margin-bottom: 10px;">
            <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 6px;">{__("travel_core.guest_names")|default:"Guest names"}:</div>
            {$_cbd_num = 1}
            {foreach from=$_cbd_guests item=guest}
                {if $cbd_room_number == 0 || (isset($guest.room) && $guest.room == $cbd_room_number)}
                    {$_cbd_name = $guest.name|default:"`$guest.last_name` `$guest.first_name`"|trim|default:"Guest `$_cbd_num`"}
                    <div style="margin-left: 10px; margin-bottom: 4px;">
                        {$_cbd_num}. <strong>{$_cbd_name|escape:html}</strong>
                        {if $guest.type == 'child' || $guest.type == 'Child'}
                            {if $guest.age > 0 && $guest.age < 18}
                                <span style="color: #666;">({__("travel_core.child")|default:"child"}, {$guest.age} {__("travel_core.years_old")|default:"years old"})</span>
                            {else}
                                <span style="color: #666;">({__("travel_core.child")|default:"child"})</span>
                            {/if}
                        {else}
                            <span style="color: #666;">({__("travel_core.adult")|default:"adult"})</span>
                        {/if}
                        {if $guest.is_holder}
                            <span style="background: var(--nvt-primary, #003580); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 3px; margin-left: 5px;">{__("travel_core.holder")|default:"Holder"}</span>
                        {/if}
                        {if $cbd_room_number == 0 && $guest.room}
                            <span style="color: #999; font-size: 11px; margin-left: 5px;">({__("travel_core.room")|default:"Room"} {$guest.room})</span>
                        {/if}
                    </div>
                    {$_cbd_num = $_cbd_num+1}
                {/if}
            {/foreach}
        </div>
    {/if}
{elseif $cbd_room_number == 0 && $cbd_extra.holder_name}
    <div style="margin-bottom: 10px;">
        <span style="font-size: 12px; color: #666; text-transform: uppercase;">{__("travel_core.guest_names")|default:"Guest names"}:</span>
        <span style="margin-left: 8px;">{$cbd_extra.holder_name|escape:html}</span>
    </div>
{/if}

{* Meal plan *}
{if $_cbd_board}
<div style="background: #f0f7ff; padding: 10px; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
    <span><strong>{__("travel_core.meal_plan")|default:"Meal plan"}:</strong> {$_cbd_board|escape:html}</span>
</div>
{/if}
