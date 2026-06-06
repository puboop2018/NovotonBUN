{* Novoton Holidays - Admin Order Details - Simple text display *}
{''|novoton_trace:"ENTER orders/order_product_info.post.tpl"}

{if $oi.extra.novoton_booking}
{* rooms_data is pre-decoded to an array in fn_novoton_holidays_get_order_info *}
{$_nvt_rooms = $oi.extra.rooms_data|default:[]}
<tr>
    <td colspan="7">
        <div style="margin:10px 0;font-size:13px;line-height:1.8;">
            
            {if $oi.extra.hotel_name}
            <strong>Hotel:</strong> {$oi.extra.hotel_name|escape:'html'}{if $oi.extra.hotel_city} ({$oi.extra.hotel_city|escape:'html'}){/if}<br>
            {/if}
            
            <strong>Check-in:</strong> {$oi.extra.check_in_short|default:$oi.extra.check_in|default:''} |
            <strong>Check-out:</strong> {$oi.extra.check_out_short|default:$oi.extra.check_out|default:''} |
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
            
            {* Guest Names — guests_data is pre-decoded to an array in fn_novoton_holidays_get_order_info *}
            {if $oi.extra.guests_data && is_array($oi.extra.guests_data)}
                {$guests_parsed = $oi.extra.guests_data}
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
            
            {* Payment Terms — pre-formatted in fn_novoton_holidays_get_order_info *}
            {$_payment_terms_formatted = $oi.extra.terms_of_payment_with_amounts|default:$oi.extra.terms_of_payment_formatted|default:''}
            {if $_payment_terms_formatted}
                <strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>
                &nbsp;&nbsp;{$_payment_terms_formatted|escape:'html'|nl2br nofilter}<br>
            {/if}

            {* Cancellation Terms — pre-formatted in fn_novoton_holidays_get_order_info *}
            {$_cancel_terms_formatted = $oi.extra.terms_of_cancellation_formatted|default:''}
            {if $_cancel_terms_formatted}
                <strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>
                &nbsp;&nbsp;{$_cancel_terms_formatted|escape:'html'|nl2br nofilter}<br>
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
{''|novoton_trace:"EXIT orders/order_product_info.post.tpl"}
