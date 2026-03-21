{*
 * Travel Core - Shared Booking Summary Block
 *
 * Displays booking details in cart/checkout.
 *
 * Usage:
 *   {include file="addons/travel_core/blocks/booking_summary.tpl" product=$product key=$key}
 *
 * @package TravelCore
 * @since 1.0.0
 *}

{if !empty($product.extra.travel_booking)}

{* Determine provider and edit dispatch *}
{if $product.extra.travel_provider}
    {$booking_provider = $product.extra.travel_provider}
{else}
    {$booking_provider = 'unknown'}
{/if}

{if $booking_provider == 'sphinx'}
    {$edit_dispatch = 'sphinx_booking.edit_booking'}
{else}
    {$edit_dispatch = 'novoton_booking.edit_booking'}
{/if}

<div class="travel-booking-summary" style="margin: 10px 0; padding: 12px; background: linear-gradient(to right, #f5f9fc, #fff); border-radius: 6px; border-left: 4px solid var(--nvt-primary, #003580);">

    {* Hotel name if available *}
    {if $product.extra.hotel_name}
    <div style="font-weight: 600; color: var(--nvt-primary, #003580); font-size: 14px; margin-bottom: 8px;">
         {$product.extra.hotel_name|escape:html}
    </div>
    {/if}

    {* Date info *}
    <div style="margin-bottom: 6px; font-size: 13px;">
        {$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"}
        <span style="color: #666;">({$product.extra.nights} {__("travel_core.nights")|default:"nights"})</span>
    </div>

    {* Room info *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.room_name|default:$product.extra.room_id|replace:'%2b':'+'|replace:'%2B':'+'|escape:html}
    </div>

    {* Board type *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.board_id|default:$product.extra.board_name}
    </div>

    {* Guests *}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.adults|default:2} {__("travel_core.adults")|default:"adults"}{if $product.extra.children > 0}, {$product.extra.children} {__("travel_core.children")|default:"children"}{/if}
    </div>

    {* Number of rooms if multi-room *}
    {if $product.extra.num_rooms > 1}
    <div style="margin-bottom: 4px; font-size: 13px;">
        {$product.extra.num_rooms} {__("travel_core.rooms")|default:"rooms"}
    </div>
    {/if}

    {* Guest name *}
    {if $product.extra.holder_name}
    <div style="padding-top: 6px; border-top: 1px dashed #ddd; margin-top: 6px; font-size: 13px;">
        {$product.extra.holder_name|escape:html}
    </div>
    {/if}

    {* Edit booking link *}
    {if $product.extra.travel_booking_id}
    {$booking_id = $product.extra.travel_booking_id}
    <div style="margin-top: 10px;">
        <a href="{"`$edit_dispatch`?booking_id=`$booking_id`&cart_id=`$key`"|fn_url}"
           style="color: #fff; background: var(--nvt-btn-primary-bg, #003580); font-size: 12px; text-decoration: none; display: inline-block; padding: 6px 12px; border-radius: 4px;">
             {__("travel_core.edit_guest_details")|default:"Edit guest details"}
        </a>
    </div>
    {/if}
</div>
{/if}
