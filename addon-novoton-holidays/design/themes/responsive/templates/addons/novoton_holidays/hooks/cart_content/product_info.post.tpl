{if !empty($product.extra.novoton_booking)}
<div style="margin-top:10px;padding:12px;background:#f5f9fc;border-radius:6px;font-size:13px;border-left:4px solid #003580;">
    {if $product.extra.hotel_name}<div style="font-weight:600;color:#003580;margin-bottom:6px;"> {$product.extra.hotel_name|escape:html}</div>{/if}
    <div style="margin-bottom:6px;"><strong></strong> {$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"} ({$product.extra.nights} {__("novoton_holidays.nights")|default:"nopți"})</div>
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        <div style="font-weight:600;color:#003580;margin:8px 0 6px;"> {$product.extra.num_rooms} rooms:</div>
        {foreach from=$product.extra.rooms_data item=room key=idx}
            <div style="margin-left:10px;margin-bottom:6px;padding:6px 8px;background:#fff;border-radius:4px;border:1px solid #e0e7ef;display:flex;justify-content:space-between;align-items:flex-start;">
                <div style="flex:1;">
                    <strong>Room {$idx+1}:</strong> {$room.room_type_display|default:$room.room_name|default:$room.room_id|escape:html}
                    <br><span style="color:#666;"> {$room.board_name|default:$room.board_id|escape:html}</span>
                    <br><span style="color:#666;"> {$room.adults} adults{if $room.children}, {$room.children} children ({$room.children_ages_str}){/if}</span>
                </div>
                <div style="text-align:right;font-weight:600;color:#003580;white-space:nowrap;margin-left:10px;">{$room.price|number_format:0} {$smarty.const.CART_PRIMARY_CURRENCY}</div>
            </div>
        {/foreach}
    {else}
        <div style="margin-bottom:4px;"><strong></strong> {$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id|escape:html}</div>
        <div style="margin-bottom:4px;"><strong></strong> {$product.extra.board_name|default:$product.extra.board_id|escape:html}</div>
        <div style="margin-bottom:4px;"><strong></strong> {$product.extra.adults} adults{if $product.extra.children}, {$product.extra.children} children ({$product.extra.children_ages}){/if}</div>
    {/if}
    
    {* Display all guests *}
    {if $product.extra.guests_data}
        <div style="margin-top:8px;border-top:1px dashed #ddd;padding-top:8px;">
            <strong> {__("novoton_holidays.guests")|default:"Guests"}:</strong>
            {assign var="guests" value=null}
            {if is_string($product.extra.guests_data)}
                {assign var="guests" value=$product.extra.guests_data|@json_decode:true}
            {else}
                {assign var="guests" value=$product.extra.guests_data}
            {/if}
            {if $guests && is_array($guests)}
                <div style="margin-left:10px; margin-top:4px;">
                {foreach from=$guests item=guest name=guestloop}
                    {if $guest.type == 'child'}
                        {if $guest.age > 0 && $guest.age < 18}
                            {$guest.name|escape:html} ({$guest.age} {__("novoton_holidays.years_old")|default:"years old"}){if !$smarty.foreach.guestloop.last}, {/if}
                        {else}
                            {$guest.name|escape:html} ({__("novoton_holidays.child")|default:"child"}){if !$smarty.foreach.guestloop.last}, {/if}
                        {/if}
                    {else}
                        {$guest.name|escape:html}{if !$smarty.foreach.guestloop.last}, {/if}
                    {/if}
                {/foreach}
                </div>
            {/if}
        </div>
    {elseif $product.extra.holder_name}
        <div style="margin-top:6px;border-top:1px dashed #ddd;padding-top:6px;"><strong></strong> {$product.extra.holder_name|escape:html}</div>
    {/if}
    
    {* Line-item price change display: cross out old price, show new *}
    {if !empty($product.extra.price_before_correction) && $product.extra.price_before_correction != $product.extra.total_price}
        <div class="nvt-line-item-price-change">
            <span class="nvt-line-item-price-change__old">{$product.extra.price_before_correction|fn_format_price} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
            <span class="nvt-line-item-price-change__arrow">&rarr;</span>
            <span class="nvt-line-item-price-change__new">{$product.extra.total_price|fn_format_price} {$smarty.const.CART_PRIMARY_CURRENCY}</span>
            {if $product.extra.total_price > $product.extra.price_before_correction}
                <span class="nvt-line-item-price-change__badge nvt-line-item-price-change__badge--warning">{__("novoton_holidays.price_updated_badge")|default:"Price Updated"}</span>
            {else}
                <span class="nvt-line-item-price-change__badge nvt-line-item-price-change__badge--success">{__("novoton_holidays.price_dropped_badge")|default:"Price Dropped!"}</span>
            {/if}
        </div>
    {/if}

    {if $product.extra.novoton_booking_id}<div style="margin-top:10px;"><a href="{"novoton_booking.edit_booking?booking_id=`$product.extra.novoton_booking_id`&cart_id=`$key`"|fn_url}" style="color:var(--nvt-info, #003580);font-size:12px;"> Edit Guest Details</a></div>{/if}
</div>
{/if}
