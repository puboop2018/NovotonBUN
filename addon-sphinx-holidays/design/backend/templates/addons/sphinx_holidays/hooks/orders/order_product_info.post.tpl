{* Sphinx Holidays — admin order-detail per-line booking block.
   Injects a full-width row into the admin order products table (matching
   novoton's order_product_info.post.tpl), rendering the shared travel_core
   booking-detail block. booking_view_id is the unified travel_bookings
   surrogate resolved in fn_sphinx_holidays_get_order_info (NOT the sphinx PK,
   which is a different id-space). *}
{if !empty($oi.extra.sphinx_booking)}
<tr class="sphinx-order-booking-row">
    <td colspan="7" style="padding:8px 15px;background:#f9f9f9;font-size:13px;line-height:1.8;">
        {include file="addons/travel_core/components/order_booking_details.tpl"
                 booking_extra=$oi.extra
                 booking_view_id=$oi.extra.travel_surrogate_id|default:0}
    </td>
</tr>
{/if}
