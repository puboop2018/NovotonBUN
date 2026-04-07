{if !empty($product.extra.travel_booking)}
<div class="travel-booking-cart-info" style="margin-top:10px;padding:12px;background:#f5f9fc;border-radius:6px;font-size:13px;border-left:4px solid #003580;">
    {if $product.extra.hotel_name}<div style="font-weight:600;color:#003580;margin-bottom:6px;">{$product.extra.hotel_name|escape:html}</div>{/if}
    <div style="margin-bottom:6px;">{$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"} ({$product.extra.nights} {__("travel_core.nights")|default:"nights"})</div>
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        <div style="font-weight:600;color:#003580;margin:8px 0 6px;">{$product.extra.num_rooms} rooms:</div>
        {foreach from=$product.extra.rooms_data item=room key=idx}
            <div style="margin-left:10px;margin-bottom:6px;padding:6px 8px;background:#fff;border-radius:4px;border:1px solid #e0e7ef;display:flex;justify-content:space-between;align-items:flex-start;">
                <div style="flex:1;">
                    <strong>Room {$idx+1}:</strong> {$room.room_type_display|default:$room.room_name|default:$room.room_id|escape:html}
                    <br><span style="color:#666;">{$room.board_name|default:$room.board_id|escape:html}</span>
                    <br><span style="color:#666;">{$room.adults} adults{if $room.children}, {$room.children} children{if $room.children_ages_str} ({$room.children_ages_str}){/if}{/if}</span>
                </div>
                {if $room.price}<div style="text-align:right;font-weight:600;color:#003580;white-space:nowrap;margin-left:10px;">{$room.price|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</div>{/if}
            </div>
        {/foreach}
    {else}
        <div style="margin-bottom:4px;">{$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id|escape:html}</div>
        <div style="margin-bottom:4px;">{$product.extra.board_name|default:$product.extra.board_id|escape:html}</div>
        <div style="margin-bottom:4px;">{$product.extra.adults} adults{if $product.extra.children}, {$product.extra.children} children{if $product.extra.children_ages} ({$product.extra.children_ages}){/if}{/if}</div>
    {/if}

    {if $product.extra.guests_data}
        <div style="margin-top:8px;border-top:1px dashed #ddd;padding-top:8px;">
            <strong>{__("travel_core.guests")|default:"Guests"}:</strong>
            {assign var="guests" value=null}
            {if is_string($product.extra.guests_data)}
                {assign var="guests" value=$product.extra.guests_data|json_decode:true}
            {else}
                {assign var="guests" value=$product.extra.guests_data}
            {/if}
            {if $guests && is_array($guests)}
                <div style="margin-left:10px;margin-top:4px;">
                {foreach from=$guests item=guest name=guestloop}
                    {if $guest.type == 'child'}
                        {$guest.name|escape:html} ({$guest.age} y/o){if !$smarty.foreach.guestloop.last}, {/if}
                    {else}
                        {$guest.name|escape:html}{if !$smarty.foreach.guestloop.last}, {/if}
                    {/if}
                {/foreach}
                </div>
            {/if}
        </div>
    {elseif $product.extra.holder_name}
        <div style="margin-top:6px;border-top:1px dashed #ddd;padding-top:6px;">{$product.extra.holder_name|escape:html}</div>
    {/if}
</div>
{/if}
