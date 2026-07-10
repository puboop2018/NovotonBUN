{* Novoton Holidays — admin order-detail per-line booking block (product_info
   hook variant; sibling of order_product_info.post.tpl). Both include the
   SHARED travel_core partial with IDENTICAL params so the two admin hooks
   cannot drift — this one previously omitted the hotel city and the unified
   travel_bookings link. <div> wrapper (no <tr>) for the hook points that
   render outside the products table. Core Smarty modifiers only. *}
{if $oi.extra.novoton_booking}
    {if $oi.extra.novoton_reservation_id}
        {$_nvt_ref = "Novoton Reservation: NT "|cat:$oi.extra.novoton_reservation_id}
        {if $oi.extra.novoton_reservation_status}{$_nvt_ref = $_nvt_ref|cat:" ("|cat:$oi.extra.novoton_reservation_status|cat:")"}{/if}
    {else}
        {$_nvt_ref = ''}
    {/if}
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
{/if}
