{* Novoton Holidays - Admin Order Details (product_info hook) - Simple text display *}

{if $oi.extra.novoton_booking}
<div style="margin:10px 0;font-size:13px;line-height:1.8;">
    
    {if $oi.extra.hotel_name}
    <strong>Hotel:</strong> {$oi.extra.hotel_name|escape:'html'}<br>
    {/if}
    
    <strong>Check-in:</strong> {$oi.extra.check_in|date_format:"%d.%m.%Y"} | 
    <strong>Check-out:</strong> {$oi.extra.check_out|date_format:"%d.%m.%Y"} | 
    <strong>Nights:</strong> {$oi.extra.nights}<br>
    
    {if $oi.extra.package_name}
    <strong>Package:</strong> {$oi.extra.package_name|escape:'html'}<br>
    {/if}
    
    {* Pre-assign room type translations *}
    {$_rt_dbl = {__('novoton_holidays.room_type_dbl')}}
    {$_rt_sgl = {__('novoton_holidays.room_type_sgl')}}
    {$_rt_trp = {__('novoton_holidays.room_type_trp')}}
    {$_rt_quad = {__('novoton_holidays.room_type_quad')}}
    {$_rt_fam = {__('novoton_holidays.room_type_fam')}}
    {$_rt_app = {__('novoton_holidays.room_type_app')}}
    {$_rt_stu = {__('novoton_holidays.room_type_stu')}}
    {$_rt_sui = {__('novoton_holidays.room_type_sui')}}

    {if $oi.extra.num_rooms > 1 && $oi.extra.rooms_data}
        <strong>Rooms ({$oi.extra.num_rooms}):</strong><br>
        {foreach from=$oi.extra.rooms_data item=room key=idx}
            {* Format room type - fix + sign and expand codes *}
            {$room_raw = $room.room_type_display|default:$room.room_name|default:$room.room_id}
            {$room_raw = $room_raw|replace:'%2b':'+'|replace:'%2B':'+'}
            {$room_raw = $room_raw|regex_replace:"/(\d)\s+(\d)/":"$1+$2"}
            {* Expand room type codes to full names *}
            {if $room_raw|strpos:'DBL' !== false}{$room_display = $room_raw|replace:'DBL':$_rt_dbl}
            {elseif $room_raw|strpos:'SGL' !== false}{$room_display = $room_raw|replace:'SGL':$_rt_sgl}
            {elseif $room_raw|strpos:'TRP' !== false || $room_raw|strpos:'TRPL' !== false}{$room_display = $room_raw|replace:'TRP':$_rt_trp|replace:'TRPL':$_rt_trp}
            {elseif $room_raw|strpos:'QUAD' !== false || $room_raw|strpos:'QUA' !== false}{$room_display = $room_raw|replace:'QUAD':$_rt_quad|replace:'QUA':$_rt_quad}
            {elseif $room_raw|strpos:'FAM' !== false}{$room_display = $room_raw|replace:'FAM':$_rt_fam}
            {elseif $room_raw|strpos:'APT' !== false}{$room_display = $room_raw|replace:'APT':$_rt_app}
            {elseif $room_raw|strpos:'STU' !== false}{$room_display = $room_raw|replace:'STU':$_rt_stu}
            {elseif $room_raw|strpos:'SUITE' !== false || $room_raw|strpos:'SUI' !== false}{$room_display = $room_raw|replace:'SUITE':$_rt_sui|replace:'SUI':$_rt_sui}
            {else}{$room_display = $room_raw}{/if}
            {$board_disp = $room.board_display|default:$room.board_name|default:$room.board_id}
            &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {$room_display} | {$board_disp} | {$room.adults} adults{if $room.children}, {$room.children} children ({$room.children_ages_str}){/if} | {$room.price} {$smarty.const.CART_PRIMARY_CURRENCY}<br>
        {/foreach}
    {else}
        {* Format room type - fix + sign and expand codes *}
        {$room_raw = $oi.extra.room_type_display|default:$oi.extra.room_name|default:$oi.extra.room_id}
        {$room_raw = $room_raw|replace:'%2b':'+'|replace:'%2B':'+'}
        {$room_raw = $room_raw|regex_replace:"/(\d)\s+(\d)/":"$1+$2"}
        {* Expand room type codes to full names *}
        {if $room_raw|strpos:'DBL' !== false}{$room_display = $room_raw|replace:'DBL':$_rt_dbl}
        {elseif $room_raw|strpos:'SGL' !== false}{$room_display = $room_raw|replace:'SGL':$_rt_sgl}
        {elseif $room_raw|strpos:'TRP' !== false || $room_raw|strpos:'TRPL' !== false}{$room_display = $room_raw|replace:'TRP':$_rt_trp|replace:'TRPL':$_rt_trp}
        {elseif $room_raw|strpos:'QUAD' !== false || $room_raw|strpos:'QUA' !== false}{$room_display = $room_raw|replace:'QUAD':$_rt_quad|replace:'QUA':$_rt_quad}
        {elseif $room_raw|strpos:'FAM' !== false}{$room_display = $room_raw|replace:'FAM':$_rt_fam}
        {elseif $room_raw|strpos:'APT' !== false}{$room_display = $room_raw|replace:'APT':$_rt_app}
        {elseif $room_raw|strpos:'STU' !== false}{$room_display = $room_raw|replace:'STU':$_rt_stu}
        {elseif $room_raw|strpos:'SUITE' !== false || $room_raw|strpos:'SUI' !== false}{$room_display = $room_raw|replace:'SUITE':$_rt_sui|replace:'SUI':$_rt_sui}
        {else}{$room_display = $room_raw}{/if}
        {$board_disp = $oi.extra.board_display|default:$oi.extra.board_name|default:$oi.extra.board_id}
        <strong>Room:</strong> {$room_display}<br>
        <strong>Board:</strong> {$board_disp}<br>
        <strong>Guests:</strong> {$oi.extra.adults} adults{if $oi.extra.children}, {$oi.extra.children} children{/if}<br>
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
                &nbsp;&nbsp;Children: {foreach from=$child_list item=guest name=children}{$guest.name|escape:'html'}{if $guest.age && $guest.age <= 17} ({$guest.age} yrs){/if}{if $guest.room} (Room {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
            {/if}
        {/if}
    {elseif $oi.extra.holder_name}
        <strong>Holder:</strong> {$oi.extra.holder_name|escape:'html'}<br>
    {/if}
    
    {* Payment and Cancellation Terms — use pre-formatted data from PHP hook *}
    {$_payment_display = $oi.extra.terms_of_payment_with_amounts|default:$oi.extra.terms_of_payment_formatted|default:''}
    {$_cancel_display = $oi.extra.terms_of_cancellation_formatted|default:''}

    {if $_payment_display}
    <strong>Terms of Payment:</strong> {$_payment_display|escape:'html'|nl2br nofilter}<br>
    {/if}

    {if $_cancel_display}
    <strong>Cancellation Policy:</strong> {$_cancel_display|escape:'html'|nl2br nofilter}<br>
    {/if}
    
    {if $oi.extra.novoton_reservation_id}
    <strong>Novoton Reservation:</strong> NT {$oi.extra.novoton_reservation_id}{if $oi.extra.novoton_reservation_status} ({$oi.extra.novoton_reservation_status}){/if}<br>
    {/if}
    
    {if $oi.extra.novoton_booking_id}
    <a href="{"novoton_bookings.view?booking_id=`$oi.extra.novoton_booking_id`"|fn_url}">View Booking #{$oi.extra.novoton_booking_id}</a>
    {/if}
    
</div>
{/if}
