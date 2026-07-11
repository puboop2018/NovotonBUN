{* Sphinx Holidays — admin order-detail block (alternate hook name).
   Some CS-Cart admin order-details templates expose orders:product_info ($oi)
   instead of orders:order_product_info; ship both so the block renders
   regardless of which the core exposes (mirrors novoton). Rendered as a <div>
   (this hook is not inside the products-table row context). Core Smarty
   modifiers only (__/escape/default/fn_url) — a compile error here would abort
   the admin {capture name="mainbox"} and mask as a capture-mismatch crash. *}
{if !empty($oi.extra.sphinx_booking)}
    <div class="sphinx-order-booking-details travel-order-booking-details" style="margin:10px 0;font-size:13px;line-height:1.8;">
        {include file="addons/travel_core/components/order_booking_details.tpl"
                 booking_extra=$oi.extra
                 booking_view_id=$oi.extra.travel_surrogate_id|default:0
                 show_rooms_breakdown=true}
    </div>
{/if}
