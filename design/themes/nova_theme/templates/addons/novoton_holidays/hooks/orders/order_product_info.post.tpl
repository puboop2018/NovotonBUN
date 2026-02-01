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
            <strong>Nights:</strong> {$oi.extra.nights}<br>
            
            {if $oi.extra.package_name}
            <strong>Package:</strong> {$oi.extra.package_name}<br>
            {/if}
            
            {if $oi.extra.num_rooms > 1 && $oi.extra.rooms_data}
                <strong>Rooms ({$oi.extra.num_rooms}):</strong><br>
                {foreach from=$oi.extra.rooms_data item=room key=idx}
                    &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {$room.room_type_display|default:$room.room_name|default:$room.room_id} | {$room.board_display|default:$room.board_name} | {$room.adults} adults{if $room.children}, {$room.children} children ({$room.children_ages_str}){/if} | {$room.price} EUR<br>
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
            
            {* Payment and Cancellation Terms *}
            {$payment_terms = $oi.extra.terms_of_payment|default:$oi.extra.payment_terms|default:$oi.extra.remark|default:''}
            {$cancel_terms = $oi.extra.terms_of_cancellation|default:$oi.extra.cancellation_terms|default:$oi.extra.important|default:''}
            
            {if $payment_terms}
            <strong>Terms of Payment:</strong> {$payment_terms|escape:'html'|nl2br nofilter}<br>
            {/if}
            
            {if $cancel_terms}
            <strong>Cancellation Policy:</strong> {$cancel_terms|escape:'html'|nl2br nofilter}<br>
            {/if}
            
            {if $oi.extra.special_requests}
            <strong>Special Requests:</strong> {$oi.extra.special_requests}<br>
            {/if}
            
            {if $oi.extra.novoton_reservation_id}
            <strong>Novoton Reservation:</strong> NT {$oi.extra.novoton_reservation_id}{if $oi.extra.novoton_reservation_status} ({$oi.extra.novoton_reservation_status}){/if}<br>
            {/if}
            
            {if $oi.extra.novoton_booking_id}
            <a href="{"novoton_bookings.view?booking_id=`$oi.extra.novoton_booking_id`"|fn_url}">View Booking #{$oi.extra.novoton_booking_id}</a>
            {/if}
            
            {* DEBUG *}
            {if $smarty.request.debug_novoton}
            <div style="margin-top:10px;padding:10px;background:#fff3cd;font-size:11px;">
                <strong>DEBUG oi.extra:</strong><pre>{foreach from=$oi.extra key=k item=v}{$k}: {if is_array($v)}{$v|@json_encode}{else}{$v|truncate:300}{/if}
{/foreach}</pre>
            </div>
            {/if}
            
        </div>
    </td>
</tr>
{/if}
