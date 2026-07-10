{* Novoton Holidays — admin order-detail per-line booking block.
   Full-width row in the admin order products table, rendering the SHARED
   travel_core booking-detail block. This and its sibling product_info.post.tpl
   are the two admin hooks; both include the shared partial with identical
   params so they cannot drift (they previously disagreed on city + link).
   booking_prefer_short keeps novoton's dotted DD.MM.YYYY admin dates.
   Core Smarty modifiers only — the shared partial uses none of the custom
   modifiers that would crash the {capture name="mainbox"} at compile time. *}
{if $oi.extra.novoton_booking}
    {if $oi.extra.novoton_reservation_id}
        {$_nvt_ref = "Novoton Reservation: NT "|cat:$oi.extra.novoton_reservation_id}
        {if $oi.extra.novoton_reservation_status}{$_nvt_ref = $_nvt_ref|cat:" ("|cat:$oi.extra.novoton_reservation_status|cat:")"}{/if}
    {else}
        {$_nvt_ref = ''}
    {/if}
<tr>
    <td colspan="7">
        <div style="margin:10px 0;font-size:13px;line-height:1.8;">
            {include file="addons/travel_core/components/order_booking_details.tpl"
                     booking_extra=$oi.extra
                     booking_label_prefix="travel_core"
                     booking_view_id=$oi.extra.travel_surrogate_id|default:0
                     booking_fallback_url="novoton_bookings.view?booking_id=`$oi.extra.novoton_booking_id|default:0`"
                     booking_location_style="paren"
                     booking_prefer_short=true
                     show_package=true
                     show_rooms_breakdown=true
                     show_terms=true
                     guest_grouping="flat"
                     booking_ref_line=$_nvt_ref}
        </div>
    </td>
</tr>
{/if}
