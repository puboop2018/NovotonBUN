{* Novoton Holidays - Admin Order Details - Simple text display *}

{if $oi.extra.novoton_booking}
{$_nvt_rooms = $oi.extra.rooms_data|default:[]}
<tr>
    <td colspan="7">
        <div style="margin:10px 0;font-size:13px;line-height:1.8;">

            {if $oi.extra.hotel_name}
            <strong>Hotel:</strong> {$oi.extra.hotel_name|escape:'html'}{if $oi.extra.hotel_city} ({$oi.extra.hotel_city|escape:'html'}){/if}<br>
            {/if}

            {if $oi.extra.check_in}
            <strong>Check-in:</strong> {$oi.extra.check_in_short|default:$oi.extra.check_in|default:''} |
            <strong>Check-out:</strong> {$oi.extra.check_out_short|default:$oi.extra.check_out|default:''} |
            <strong>Nights:</strong> {$oi.extra.nights|default:'-'}<br>
            {/if}

            {if $oi.extra.package_name}
            <strong>Package:</strong> {$oi.extra.package_name|escape:'html'}<br>
            {/if}

            {if $oi.extra.num_rooms > 1 && $_nvt_rooms}
                <strong>Rooms ({$oi.extra.num_rooms}):</strong><br>
                {foreach from=$_nvt_rooms item=room key=idx}
                    {$room_display = $room.room_id|default:$room.room_name|default:''}
                    &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {if $room_display}{$room_display|novoton_format_room_type}{else}{$room.room_type_display|default:'Room'|escape:'html'}{/if} | {$room.board_id|default:$room.board_name|default:''|novoton_format_board} | {$room.adults|default:0} adults{if $room.children}, {$room.children} children{if $room.children_ages_str} ({$room.children_ages_str}){/if}{/if} | {$room.price|default:0} {$smarty.const.CART_PRIMARY_CURRENCY}<br>
                {/foreach}
            {else}
                {$room_id_raw = $oi.extra.room_id|default:''}
                {$room_display = $oi.extra.room_type_display|default:''}
                {$board_raw = $oi.extra.board_id|default:''}
                {if $room_id_raw || $room_display}<strong>Room:</strong> {if $room_id_raw}{$room_id_raw|novoton_format_room_type}{else}{$room_display|escape:'html'}{/if}<br>{/if}
                {if $board_raw}<strong>Board:</strong> {$board_raw|novoton_format_board}<br>{/if}
                <strong>Guests:</strong> {$oi.extra.adults|default:0} adults{if $oi.extra.children}, {$oi.extra.children} children{/if}<br>
            {/if}

            {* Guest Names *}
            {if $oi.extra.guests_data && is_array($oi.extra.guests_data)}
                <strong>Guest Names:</strong><br>
                {foreach from=$oi.extra.guests_data item=guest}
                    &nbsp;&nbsp;{$guest.name|default:'Guest'|escape:'html'} ({$guest.type|default:'adult'}){if $guest.room} — Room {$guest.room}{/if}<br>
                {/foreach}
            {elseif $oi.extra.holder_name}
                <strong>Holder:</strong> {$oi.extra.holder_name|escape:'html'}<br>
            {/if}

            {* Payment and Cancellation Terms *}
            {$_payment_display = $oi.extra.terms_of_payment_with_amounts|default:$oi.extra.terms_of_payment_formatted|default:''}
            {$_cancel_display = $oi.extra.terms_of_cancellation_formatted|default:''}

            {if $_payment_display}
                <strong>Terms of Payment:</strong><br>
                &nbsp;&nbsp;{$_payment_display|escape:'html'|nl2br nofilter}<br>
            {/if}

            {if $_cancel_display}
                <strong>Cancellation Policy:</strong><br>
                &nbsp;&nbsp;{$_cancel_display|escape:'html'|nl2br nofilter}<br>
            {/if}

            {if $oi.extra.novoton_reservation_id}
            <strong>Novoton Reservation:</strong> NT {$oi.extra.novoton_reservation_id}{if $oi.extra.novoton_reservation_status} ({$oi.extra.novoton_reservation_status}){/if}<br>
            {/if}

            {if $oi.extra.novoton_booking_id}
            <a href="{"novoton_bookings.view?booking_id=`$oi.extra.novoton_booking_id`"|fn_url}">View Booking #{$oi.extra.novoton_booking_id}</a>
            {/if}

        </div>
    </td>
</tr>
{/if}
