{*
    Shared cart/checkout booking-details card — the ONE template that renders
    a travel booking's details on cart lines for BOTH providers (novoton +
    sphinx). Ported from novoton's hooks/checkout/product_info.post.tpl and
    made provider-neutral:

    - gated on the provider-neutral $product.extra.travel_booking flag
      (both providers' add_to_cart set it);
    - every field renders only when its extra exists, so a provider that
      lacks a detail (API doesn't supply it) simply omits the line;
    - the edit link derives its dispatch from extra.travel_provider;
    - labels come from travel_core.* keys (self-seeded);
    - the room-collapse toggle lives in js/addons/travel_core/
      cart-booking-details.js (no inline JS — InlineScriptRatchet).

    Usage (from travel_core's checkout hooks):
      {include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key}
*}
{if !empty($product.extra.travel_booking)}
{$total_adults = 0}
{$total_children = 0}
{$total_rooms = $product.extra.num_rooms|default:1}
{$nights_count = $product.extra.nights|default:7}

{if $product.extra.rooms_data}
    {foreach from=$product.extra.rooms_data item=room}
        {$total_adults = $total_adults+$room.adults|default:2}
        {$total_children = $total_children+$room.children|default:0}
    {/foreach}
{else}
    {$total_adults = $product.extra.adults|default:2}
    {$total_children = $product.extra.children|default:0}
{/if}

{if $product.extra.travel_provider == 'sphinx'}
    {$_edit_dispatch = 'sphinx_booking.edit_booking'}
{else}
    {$_edit_dispatch = 'novoton_booking.edit_booking'}
{/if}
{$_edit_booking_id = $product.extra.travel_booking_id|default:$product.extra.novoton_booking_id|default:0}

<div class="travel-booking-card" style="margin-top: 15px; border: 1px solid #e0e7ef; border-radius: 8px; overflow: hidden; font-size: 14px;">

    {* Separator line *}
    <div style="height: 4px; background: linear-gradient(90deg, var(--nvt-primary, #003580), #0071c2);"></div>

    {* Header: Your booking details *}
    <div style="padding: 16px; background: #f8fafc; border-bottom: 1px solid #e0e7ef;">
        <div style="font-size: 16px; font-weight: 600; color: #1a1a1a; margin-bottom: 12px;">{__("travel_core.your_booking_details")|default:"Your booking details"}</div>

        {* Check-in / Check-out dates *}
        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("travel_core.check_in")|default:"Check-in"}</div>
                <div style="font-weight: 600; color: var(--nvt-primary, #003580);">
                    {$product.extra.check_in|default:''|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("travel_core.check_out")|default:"Check-out"}</div>
                <div style="font-weight: 600; color: var(--nvt-primary, #003580);">
                    {$product.extra.check_out|default:''|date_format:"%a %d %b %Y"}
                </div>
            </div>
            <div>
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">{__("travel_core.total_stay")|default:"Total stay"}</div>
                <div style="font-weight: 600; color: #1a1a1a;">
                    {$nights_count} {if $nights_count == 1}{__("travel_core.night")|default:"night"}{else}{__("travel_core.nights")|default:"nights"}{/if}
                </div>
            </div>
        </div>
    </div>

    {* Room + party summary *}
    <div style="padding: 12px 16px; background: #fff; border-bottom: 1px solid #e0e7ef;">
        <div style="font-weight: 600; color: #1a1a1a;">
            {$total_rooms} {if $total_rooms == 1}{__("travel_core.room")|default:"room"}{else}{__("travel_core.rooms")|default:"rooms"}{/if} {__("travel_core.for")|default:"for"} {$total_adults} {if $total_adults == 1}{__("travel_core.adult")|default:"adult"}{else}{__("travel_core.adults")|default:"adults"}{/if}{if $total_children > 0}, {$total_children} {if $total_children == 1}{__("travel_core.child")|default:"child"}{else}{__("travel_core.children")|default:"children"}{/if}{/if}
        </div>
    </div>

    {* Room cards *}
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        {* Multi-room: first expanded, rest collapsed *}
        {foreach from=$product.extra.rooms_data item=room key=idx}
            {$room_number = $idx+1}
            {$is_first = ($idx == 0)}
            {$collapse_id = "room_collapse_`$key`_`$idx`"}

            <div class="travel-room-card" style="border-bottom: 1px solid #e0e7ef;">
                {* Room header — clickable for collapse (handler in cart-booking-details.js) *}
                <div onclick="travelToggleRoomCard('{$collapse_id}')" style="padding: 12px 16px; background: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex: 1;">
                        <span style="font-weight: 600; color: #1a1a1a;">{__("travel_core.room")|default:"Room"} {$room_number}: {$room.room_type_display|default:$room.room_name|default:$room.room_id}</span>
                    </div>
                    <span style="color: var(--nvt-primary, #003580); font-weight: 600; margin-left: 15px; margin-right: 10px; white-space: nowrap;">{$room.price|default:0|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
                    <span id="{$collapse_id}_icon" style="font-size: 18px; color: #666;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s; transform: {if $is_first}rotate(180deg){else}rotate(0deg){/if}"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
                </div>

                {* Room details — collapsible *}
                <div id="{$collapse_id}" style="padding: 0 16px 16px; background: #fff; {if !$is_first}display: none;{/if}">
                    {include file="addons/travel_core/components/cart_booking_room_body.tpl"
                        cbd_room=$room
                        cbd_room_number=$room_number
                        cbd_extra=$product.extra}
                </div>
            </div>
        {/foreach}
    {else}
        {* Single room — always expanded *}
        <div class="travel-room-card" style="padding: 16px; background: #fff;">
            {if $product.extra.room_type_display || $product.extra.room_name || $product.extra.room_id}
            <div style="font-weight: 600; color: #1a1a1a; margin-bottom: 12px; font-size: 15px;">
                {$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id}
            </div>
            {/if}
            {include file="addons/travel_core/components/cart_booking_room_body.tpl"
                cbd_room=null
                cbd_room_number=0
                cbd_extra=$product.extra}
        </div>
    {/if}

    {* Edit link *}
    {if $_edit_booking_id}
        <div style="padding: 12px 16px; background: #f8fafc; border-top: 1px solid #e0e7ef; text-align: center;">
            <a href="{"`$_edit_dispatch`?booking_id=`$_edit_booking_id`&cart_id=`$key`"|fn_url}" style="color: var(--nvt-info, #0071c2); font-size: 13px; text-decoration: none;">
                {__("travel_core.edit_guest_details")|default:"Edit guest details"}
            </a>
        </div>
    {/if}
</div>

{script src="js/addons/travel_core/cart-booking-details.js"}
{/if}
