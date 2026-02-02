{* Novoton Holidays - Customer Order Details - Simple text display *}

{if !empty($product.extra.novoton_booking)}
<div style="margin:10px 0;font-size:13px;line-height:1.8;">
    
    {if $product.extra.hotel_name}
    <strong>Hotel:</strong> {$product.extra.hotel_name}<br>
    {/if}
    
    <strong>Check-in:</strong> {$product.extra.check_in|date_format:"%d.%m.%Y"} |
    <strong>Check-out:</strong> {$product.extra.check_out|date_format:"%d.%m.%Y"} |
    <strong>{__("novoton_holidays.nights")|default:"Nopți"}:</strong> {$product.extra.nights}<br>

    {if $product.extra.package_name}
    <strong>{__("novoton_holidays.package")|default:"Pachet"}:</strong> {$product.extra.package_name}<br>
    {/if}
    
    {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
        <strong>Rooms ({$product.extra.num_rooms}):</strong><br>
        {foreach from=$product.extra.rooms_data item=room key=idx}
            &nbsp;&nbsp;- <strong>Room {$idx+1}:</strong> {$room.room_type_display|default:$room.room_name|default:$room.room_id} | {$room.board_display|default:$room.board_name} | {$room.adults} {__("novoton_holidays.adults")}{if $room.children}, {$room.children} {__("novoton_holidays.children")} ({$room.children_ages_str}){/if} | {$room.price} EUR<br>
        {/foreach}
    {else}
        <strong>{__("novoton_holidays.room_type")}:</strong> {$product.extra.room_type_display|default:$product.extra.room_name|default:$product.extra.room_id}<br>
        <strong>{__("novoton_holidays.board")}:</strong> {$product.extra.board_display|default:$product.extra.board_name}<br>
        <strong>{__("novoton_holidays.guests")}:</strong> {$product.extra.adults} {__("novoton_holidays.adults")}{if $product.extra.children}, {$product.extra.children} {__("novoton_holidays.children")} ({$product.extra.children_ages}){/if}<br>
    {/if}
    
    {* Guest Names *}
    {if $product.extra.guests_data}
        {$guests = $product.extra.guests_data|@json_decode:true}
        {if $guests}
            {$adult_guests = []}
            {$child_guests = []}
            {foreach from=$guests item=guest}
                {if $guest.type == 'child'}
                    {$child_guests[] = $guest}
                {else}
                    {$adult_guests[] = $guest}
                {/if}
            {/foreach}
            
            <strong>{__("novoton_holidays.guests")}:</strong><br>
            {if $adult_guests}
                &nbsp;&nbsp;{__("novoton_holidays.adults")}: {foreach from=$adult_guests item=guest name=adults}{$guest.name}{if $guest.room} ({__("novoton_holidays.room")} {$guest.room}){/if}{if !$smarty.foreach.adults.last}, {/if}{/foreach}<br>
            {/if}
            {if $child_guests}
                &nbsp;&nbsp;{__("novoton_holidays.children")}: {foreach from=$child_guests item=guest name=children}{$guest.name} ({$guest.age}){if $guest.room} ({__("novoton_holidays.room")} {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
            {/if}
        {/if}
    {elseif $product.extra.holder_name}
        <strong>{__("novoton_holidays.holder")}:</strong> {$product.extra.holder_name}<br>
    {/if}
    
    {if $product.extra.special_requests}
    <strong>{__("novoton_holidays.special_requests")}:</strong> {$product.extra.special_requests}<br>
    {/if}

    {* Terms of Payment *}
    {$pt = $product.extra.terms_of_payment_formatted|default:$product.extra.terms_of_payment|default:''}
    {if $pt}
    <br>
    <strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plata"}</strong><br>
    <span style="white-space:pre-line;">{$pt|strip_tags|trim}</span><br>
    {/if}

    {* Terms of Cancellation *}
    {$ct = $product.extra.terms_of_cancellation_formatted|default:$product.extra.terms_of_cancellation|default:''}
    {if $ct}
    {if !$pt}<br>{/if}
    <strong>{__("novoton_holidays.cancellation_policy")|default:"Politica de anulare"}</strong><br>
    <span style="white-space:pre-line;">{$ct|strip_tags|trim}</span><br>
    {/if}

    {* DEBUG *}
    {if $smarty.request.debug_novoton}
    <div style="margin-top:10px;padding:10px;background:#fff3cd;font-size:11px;">
        <strong>DEBUG product.extra:</strong><pre>{foreach from=$product.extra key=k item=v}{$k}: {if is_array($v)}{$v|@json_encode}{else}{$v|truncate:300}{/if}
{/foreach}</pre>
    </div>
    {/if}
    
</div>
{/if}
