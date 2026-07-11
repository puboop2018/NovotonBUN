{* Sphinx Holidays — storefront order-detail per-line booking block.
   Renders the same hotel/dates/room/board/guests/guest-names block novoton
   shows, via the shared travel_core partial, gated on the sphinx_booking flag.
   No admin "View Booking" link on the storefront (booking_view_id omitted). *}
{if !empty($product.extra.sphinx_booking)}
    <div class="sphinx-order-booking-details travel-order-booking-details" style="margin:10px 0;font-size:13px;line-height:1.8;">
        {include file="addons/travel_core/components/order_booking_details.tpl"
                 booking_extra=$product.extra
                 booking_view_id=0
                 show_rooms_breakdown=true}
    </div>
{/if}
