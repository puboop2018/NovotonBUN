{* Sphinx Holidays — storefront order-detail block (alternate hook name).
   Some CS-Cart storefront order-details templates expose
   orders:order_product_info ($oi) instead of orders:product_info ($product);
   ship both so the block renders regardless of which the core exposes (mirrors
   novoton). No admin "View Booking" link on the storefront. Core modifiers only. *}
{if !empty($oi.extra.sphinx_booking)}
<tr class="sphinx-order-booking-row">
    <td colspan="7" style="padding:8px 15px;background:#f9f9f9;font-size:13px;line-height:1.8;">
        {include file="addons/travel_core/components/order_booking_details.tpl"
                 booking_extra=$oi.extra
                 booking_view_id=0
                 show_rooms_breakdown=true}
    </td>
</tr>
{/if}
