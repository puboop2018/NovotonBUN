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
                    {* Format room type - fix + sign and expand codes *}
                    {$room_raw = $room.room_type_display|default:$room.room_name|default:$room.room_id}
                    {$room_raw = $room_raw|replace:'%2b':'+'|replace:'%2B':'+'}
                    {$room_raw = $room_raw|regex_replace:"/(\d)\s+(\d)/":"$1+$2"}
                    {* Expand room type codes to full names *}
                    {if $room_raw|strpos:'DBL' !== false}{$room_display = $room_raw|replace:'DBL':'Camera Dubla'}
                    {elseif $room_raw|strpos:'SGL' !== false}{$room_display = $room_raw|replace:'SGL':'Camera Single'}
                    {elseif $room_raw|strpos:'TRP' !== false || $room_raw|strpos:'TRPL' !== false}{$room_display = $room_raw|replace:'TRP':'Camera Tripla'|replace:'TRPL':'Camera Tripla'}
                    {elseif $room_raw|strpos:'QUAD' !== false || $room_raw|strpos:'QUA' !== false}{$room_display = $room_raw|replace:'QUAD':'Camera Cvadrupla'|replace:'QUA':'Camera Cvadrupla'}
                    {elseif $room_raw|strpos:'FAM' !== false}{$room_display = $room_raw|replace:'FAM':'Camera Familiala'}
                    {elseif $room_raw|strpos:'APT' !== false}{$room_display = $room_raw|replace:'APT':'Apartament'}
                    {elseif $room_raw|strpos:'STU' !== false}{$room_display = $room_raw|replace:'STU':'Studio'}
                    {elseif $room_raw|strpos:'SUITE' !== false || $room_raw|strpos:'SUI' !== false}{$room_display = $room_raw|replace:'SUITE':'Suita'|replace:'SUI':'Suita'}
                    {else}{$room_display = $room_raw}{/if}
                    {* Format board name *}
                    {$board_raw = $room.board_display|default:$room.board_name|default:$room.board_id}
                    {if $board_raw == 'AI' || $board_raw == 'ALL INCL' || $board_raw == 'ALLINC'}{$board_disp = 'All Inclusive'}
                    {elseif $board_raw == 'UAI' || $board_raw == 'ULTRA ALL'}{$board_disp = 'Ultra All Inclusive'}
                    {elseif $board_raw == 'FB' || $board_raw == 'FULL BOARD'}{$board_disp = 'Full Board'}
                    {elseif $board_raw == 'HB' || $board_raw == 'HALF BOARD'}{$board_disp = 'Half Board'}
                    {elseif $board_raw == 'BB' || $board_raw == 'B&B' || $board_raw == 'BED BREAKFAST'}{$board_disp = 'Bed & Breakfast'}
                    {elseif $board_raw == 'RO' || $board_raw == 'ROOM ONLY'}{$board_disp = 'Room Only'}
                    {else}{$board_disp = $board_raw}{/if}
                    &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {$room_display} | {$board_disp} | {$room.adults} adults{if $room.children}, {$room.children} children ({$room.children_ages_str}){/if} | {$room.price} EUR<br>
                {/foreach}
            {else}
                {* Format room type - fix + sign and expand codes *}
                {$room_raw = $oi.extra.room_type_display|default:$oi.extra.room_name|default:$oi.extra.room_id}
                {$room_raw = $room_raw|replace:'%2b':'+'|replace:'%2B':'+'}
                {$room_raw = $room_raw|regex_replace:"/(\d)\s+(\d)/":"$1+$2"}
                {* Expand room type codes to full names *}
                {if $room_raw|strpos:'DBL' !== false}{$room_display = $room_raw|replace:'DBL':'Camera Dubla'}
                {elseif $room_raw|strpos:'SGL' !== false}{$room_display = $room_raw|replace:'SGL':'Camera Single'}
                {elseif $room_raw|strpos:'TRP' !== false || $room_raw|strpos:'TRPL' !== false}{$room_display = $room_raw|replace:'TRP':'Camera Tripla'|replace:'TRPL':'Camera Tripla'}
                {elseif $room_raw|strpos:'QUAD' !== false || $room_raw|strpos:'QUA' !== false}{$room_display = $room_raw|replace:'QUAD':'Camera Cvadrupla'|replace:'QUA':'Camera Cvadrupla'}
                {elseif $room_raw|strpos:'FAM' !== false}{$room_display = $room_raw|replace:'FAM':'Camera Familiala'}
                {elseif $room_raw|strpos:'APT' !== false}{$room_display = $room_raw|replace:'APT':'Apartament'}
                {elseif $room_raw|strpos:'STU' !== false}{$room_display = $room_raw|replace:'STU':'Studio'}
                {elseif $room_raw|strpos:'SUITE' !== false || $room_raw|strpos:'SUI' !== false}{$room_display = $room_raw|replace:'SUITE':'Suita'|replace:'SUI':'Suita'}
                {else}{$room_display = $room_raw}{/if}
                {* Format board name *}
                {$board_raw = $oi.extra.board_display|default:$oi.extra.board_name|default:$oi.extra.board_id}
                {if $board_raw == 'AI' || $board_raw == 'ALL INCL' || $board_raw == 'ALLINC'}{$board_disp = 'All Inclusive'}
                {elseif $board_raw == 'UAI' || $board_raw == 'ULTRA ALL'}{$board_disp = 'Ultra All Inclusive'}
                {elseif $board_raw == 'FB' || $board_raw == 'FULL BOARD'}{$board_disp = 'Full Board'}
                {elseif $board_raw == 'HB' || $board_raw == 'HALF BOARD'}{$board_disp = 'Half Board'}
                {elseif $board_raw == 'BB' || $board_raw == 'B&B' || $board_raw == 'BED BREAKFAST'}{$board_disp = 'Bed & Breakfast'}
                {elseif $board_raw == 'RO' || $board_raw == 'ROOM ONLY'}{$board_disp = 'Room Only'}
                {else}{$board_disp = $board_raw}{/if}
                <strong>Room:</strong> {$room_display}<br>
                <strong>Board:</strong> {$board_disp}<br>
                <strong>Guests:</strong> {$oi.extra.adults} adults{if $oi.extra.children}, {$oi.extra.children} children{/if}<br>
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
                        &nbsp;&nbsp;Children: {foreach from=$child_list item=guest name=children}{$guest.name}{if $guest.age && $guest.age <= 17} ({$guest.age} yrs){/if}{if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
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
            
        </div>
    </td>
</tr>
{/if}
