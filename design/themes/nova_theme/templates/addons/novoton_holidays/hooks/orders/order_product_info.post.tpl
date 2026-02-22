{* Novoton Holidays - Admin Order Details - Simple text display *}

{if $oi.extra.novoton_booking}
<tr>
    <td colspan="7">
        <div style="margin:10px 0;font-size:13px;line-height:1.8;">
            
            {if $oi.extra.hotel_name}
            <strong>Hotel:</strong> {$oi.extra.hotel_name}{if $oi.extra.hotel_city} ({$oi.extra.hotel_city}){/if}<br>
            {/if}
            
            <strong>Check-in:</strong> {$oi.extra.check_in|date_format:"%d.%m.%Y"} |
            <strong>Check-out:</strong> {$oi.extra.check_out|date_format:"%d.%m.%Y"} |
            <strong>{__("novoton_holidays.nights")|default:"Nopți"}:</strong> {$oi.extra.nights}<br>

            {if $oi.extra.package_name}
            <strong>{__("novoton_holidays.package")|default:"Pachet"}:</strong> {$oi.extra.package_name}<br>
            {/if}
            
            {if $oi.extra.num_rooms > 1 && $oi.extra.rooms_data}
                <strong>Rooms ({$oi.extra.num_rooms}):</strong><br>
                {foreach from=$oi.extra.rooms_data item=room key=idx}
                    &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {$room.room_type_display|default:$room.room_name|default:$room.room_id} | {$room.board_display|default:$room.board_name} | {$room.adults} adults{if $room.children}, {$room.children} children ({$room.children_ages_str}){/if} | {$room.price} {$smarty.const.CART_PRIMARY_CURRENCY}<br>
                {/foreach}
            {else}
                <strong>Room:</strong> {$oi.extra.room_type_display|default:$oi.extra.room_name|default:$oi.extra.room_id}<br>
                <strong>Board:</strong> {$oi.extra.board_display|default:$oi.extra.board_name}<br>
                <strong>Guests:</strong> {$oi.extra.adults} adults{if $oi.extra.children}, {$oi.extra.children} children ({$oi.extra.children_ages}){/if}<br>
            {/if}
            
            {* Guest Names *}
            {if $oi.extra.guests_data}
                {$guests_parsed = $oi.extra.guests_data|@json_decode:true}
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
                        &nbsp;&nbsp;Adults: {foreach from=$adult_list item=guest name=adults}{$guest.name}{if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.adults.last}, {/if}{/foreach}<br>
                    {/if}
                    {if $child_list}
                        &nbsp;&nbsp;Children: {foreach from=$child_list item=guest name=children}{$guest.name} ({$guest.age} yrs){if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
                    {/if}
                {/if}
            {elseif $oi.extra.holder_name}
                <strong>Holder:</strong> {$oi.extra.holder_name}<br>
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
            
            {if $oi.extra.special_requests}
            <strong>Special Requests:</strong> {$oi.extra.special_requests|escape}<br>
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
