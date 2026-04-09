{* Novoton Holidays - Admin Order Details - Simple text display *}

{if $oi.extra.novoton_booking}
{if $oi.extra.rooms_data && is_string($oi.extra.rooms_data)}
    {$_nvt_rooms = $oi.extra.rooms_data|json_decode:true}
{else}
    {$_nvt_rooms = $oi.extra.rooms_data|default:[]}
{/if}
<tr>
    <td colspan="7">
        <div style="margin:10px 0;font-size:13px;line-height:1.8;">
            
            {if $oi.extra.hotel_name}
            <strong>Hotel:</strong> {$oi.extra.hotel_name|escape:'html'}{if $oi.extra.hotel_city} ({$oi.extra.hotel_city|escape:'html'}){/if}<br>
            {/if}
            
            <strong>Check-in:</strong> {$oi.extra.check_in|default:''|date_format:"%d.%m.%Y"} |
            <strong>Check-out:</strong> {$oi.extra.check_out|default:''|date_format:"%d.%m.%Y"} |
            <strong>{__("novoton_holidays.nights")|default:"Nopți"}:</strong> {$oi.extra.nights}<br>

            {if $oi.extra.package_name}
            <strong>{__("novoton_holidays.package")|default:"Pachet"}:</strong> {$oi.extra.package_name|escape:'html'}<br>
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
                <strong>Guests:</strong> {$oi.extra.adults|default:0} adults{if $oi.extra.children}, {$oi.extra.children} children{if $oi.extra.children_ages} ({$oi.extra.children_ages}){/if}{/if}<br>
            {/if}
            
            {* Guest Names *}
            {if $oi.extra.guests_data}
                {$guests_parsed = $oi.extra.guests_data|json_decode:true}
                {if $guests_parsed}
                    {$adult_list = []}
                    {$child_list = []}
                    {foreach from=$guests_parsed item=guest}
                        {if $guest.type == 'child'}
                            {$child_list[] = $guest}
                        {else}
                            {$adult_list[] = $guest}
                        {/if}
                    {/foreach}
                    
                    <strong>Guest Names:</strong><br>
                    {if $adult_list}
                        &nbsp;&nbsp;Adults: {foreach from=$adult_list item=guest name=adults}{$guest.name|escape:'html'}{if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.adults.last}, {/if}{/foreach}<br>
                    {/if}
                    {if $child_list}
                        &nbsp;&nbsp;Children: {foreach from=$child_list item=guest name=children}{$guest.name|escape:'html'} ({$guest.age} yrs){if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
                    {/if}
                {/if}
            {elseif $oi.extra.holder_name}
                <strong>Holder:</strong> {$oi.extra.holder_name|escape:'html'}<br>
            {/if}
            
            {* Payment Terms with Amounts - use raw XML for consistent date format *}
            {$payment_terms_raw = $oi.extra.terms_of_payment_raw|default:$oi.extra.terms_of_payment|default:''}
            {if $payment_terms_raw}
                {$booking_price = $oi.extra.price|default:$oi.price|default:0}
                {$currency = $oi.extra.currency|default:$smarty.const.CART_PRIMARY_CURRENCY}
                {$_payment_terms_formatted = fn_novoton_holidays_format_payment_terms_with_amounts($payment_terms_raw, $booking_price, $currency)}
                {if $_payment_terms_formatted}
                    <strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>
                    &nbsp;&nbsp;{$_payment_terms_formatted|escape:'html'|nl2br nofilter}<br>
                {/if}
            {/if}

            {* Cancellation Terms - use raw XML for consistent date format *}
            {$cancel_terms_raw = $oi.extra.terms_of_cancellation_raw|default:$oi.extra.terms_of_cancellation|default:''}
            {if $cancel_terms_raw}
                {$check_in = $oi.extra.check_in|default:''}
                {$_cancel_terms_formatted = fn_novoton_holidays_format_cancellation_terms($cancel_terms_raw, $check_in)}
                {if $_cancel_terms_formatted}
                    <strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>
                    &nbsp;&nbsp;{$_cancel_terms_formatted|escape:'html'|nl2br nofilter}<br>
                {/if}
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
