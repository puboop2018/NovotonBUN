{* Sphinx Holidays — Order Email Product Info Hook
 * Hook: orders:product_info (mail area) — adds the booking-stay block to each
 * sphinx product line in order-confirmation emails, reusing travel_core's
 * shared order_booking_details partial (same block the order pages render).
 * booking_view_id=0: no admin links in customer emails. *}
{if !empty($oi.extra.sphinx_booking)}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 5px; font-size: 12px; color: #666;">
    <tr>
        <td>
            {include file="addons/travel_core/components/order_booking_details.tpl"
                     booking_extra=$oi.extra
                     booking_view_id=0
                     booking_label_prefix="travel_core"}
        </td>
    </tr>
</table>
{/if}
