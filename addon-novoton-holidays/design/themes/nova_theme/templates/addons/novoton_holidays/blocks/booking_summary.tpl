{* 
 * Booking Summary Block
 * Include this in products_in_cart.tpl to display booking details
 * Usage: {include file="addons/novoton_holidays/blocks/booking_summary.tpl" product=$product key=$key}
 *}

{if !empty($product.extra.novoton_booking)}
<div class="ty-novoton-booking-summary" style="margin: 10px 0; padding: 12px; background: linear-gradient(to right, #f5f9fc, #fff); border-radius: 6px; border-left: 4px solid var(--nvt-primary, #003580);">

    {* Hotel name if available *}
    {if $product.extra.hotel_name}
    <div style="font-weight: 600; color: var(--nvt-primary, #003580); font-size: 14px; margin-bottom: 8px;">
         {$product.extra.hotel_name|escape:html}
    </div>
    {/if}

    {* Date info *}
    <div style="margin-bottom: 6px; font-size: 13px;">
        {$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"}
        <span style="color: #666;">({$product.extra.nights} {__("novoton_holidays.nights")})</span>
    </div>

    {* Room info *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.room_name|default:$product.extra.room_id|replace:'%2b':'+'|replace:'%2B':'+'|escape:html}
    </div>

    {* Board type *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.board_id|default:$product.extra.board_name|escape:html}
    </div>

    {* Guests *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {__("novoton_holidays.n_adults", [$product.extra.adults|default:2])}{if $product.extra.children > 0}, {__("novoton_holidays.n_children", [$product.extra.children])}{/if}
    </div>

    {* Number of rooms if multi-room *}
    {if $product.extra.num_rooms > 1}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.num_rooms} {__("novoton_holidays.rooms")}
    </div>
    {/if}

    {* Guest name *}
    {if $product.extra.holder_name}
    <div style="padding-top: 6px; border-top: 1px dashed #ddd; margin-top: 6px; font-size: 13px;">
        {$product.extra.holder_name|escape:html}
    </div>
    {/if}

    {* Edit booking link *}
    {if $product.extra.novoton_booking_id}
    <div style="margin-top: 10px;">
        <a href="{"novoton_booking.edit_booking?booking_id=`$product.extra.novoton_booking_id`&cart_id=`$key`"|fn_url}"
           style="color: #fff; background: var(--nvt-btn-primary-bg, #003580); font-size: 12px; text-decoration: none; display: inline-block; padding: 6px 12px; border-radius: 4px;">
             {__("novoton_holidays.edit_guest_details")}
        </a>
    </div>
    {/if}
</div>
{/if}
