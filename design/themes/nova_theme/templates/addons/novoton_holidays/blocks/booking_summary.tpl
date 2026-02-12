{* 
 * Booking Summary Block
 * Include this in products_in_cart.tpl to display booking details
 * Usage: {include file="addons/novoton_holidays/blocks/booking_summary.tpl" product=$product key=$key}
 *}

{if !empty($product.extra.novoton_booking)}
<div class="ty-novoton-booking-summary" style="margin: 10px 0; padding: 12px; background: linear-gradient(to right, #f5f9fc, #fff); border-radius: 6px; border-left: 4px solid #003580;">
    
    {* Hotel name if available *}
    {if $product.extra.hotel_name}
    <div style="font-weight: 600; color: #003580; font-size: 14px; margin-bottom: 8px;">
         {$product.extra.hotel_name}
    </div>
    {/if}
    
    {* Date info *}
    <div style="margin-bottom: 6px; font-size: 13px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"}
        <span style="color: #666;">({$product.extra.nights} {__("novoton_holidays.nights")})</span>
    </div>
    
    {* Room info *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.room_name|default:$product.extra.room_id|replace:'%2b':'+'|replace:'%2B':'+'}
    </div>
    
    {* Board type *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        <strong style="color: #003580;"></strong> 
        {if $product.extra.board_id == 'AI' || $product.extra.board_id == 'ALL INCL'}All Inclusive
        {elseif $product.extra.board_id == 'FB'}Full Board
        {elseif $product.extra.board_id == 'HB'}Half Board
        {elseif $product.extra.board_id == 'BB'}Bed & Breakfast
        {elseif $product.extra.board_id == 'RO'}Room Only
        {else}{$product.extra.board_name|default:$product.extra.board_id|default:'All Inclusive'}{/if}
    </div>
    
    {* Guests *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.adults|default:2} {__("novoton_holidays.adults")}{if $product.extra.children > 0}, {$product.extra.children} {if $product.extra.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
    </div>
    
    {* Number of rooms if multi-room *}
    {if $product.extra.num_rooms > 1}
    <div style="margin-bottom: 4px; font-size: 13px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.num_rooms} {__("novoton_holidays.rooms")}
    </div>
    {/if}
    
    {* Guest name *}
    {if $product.extra.holder_name}
    <div style="padding-top: 6px; border-top: 1px dashed #ddd; margin-top: 6px; font-size: 13px;">
        <strong style="color: #003580;"></strong> {$product.extra.holder_name}
    </div>
    {/if}
    
    {* Edit booking link *}
    {if $product.extra.novoton_booking_id}
    <div style="margin-top: 10px;">
        <a href="{"novoton_booking.edit_booking?booking_id=`$product.extra.novoton_booking_id`&cart_id=`$key`"|fn_url}" 
           style="color: #fff; background: #003580; font-size: 12px; text-decoration: none; display: inline-block; padding: 6px 12px; border-radius: 4px;">
             {__("novoton_holidays.edit_guest_details")}
        </a>
    </div>
    {/if}
</div>
{/if}
