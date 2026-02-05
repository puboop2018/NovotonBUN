{* Novoton Holidays - Order Email Product Info Hook *}
{* Hook: orders:product_info — adds booking details to each product in order emails *}

{if !empty($oi.extra.novoton_booking)}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 5px; font-size: 12px; color: #666;">
    <tr>
        <td>
            {if $oi.extra.check_in && $oi.extra.check_out}
            <strong>Check-in:</strong> {$oi.extra.check_in|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"} |
            <strong>Check-out:</strong> {$oi.extra.check_out|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"} |
            <strong>{__("novoton_holidays.nights")|default:"Nopți"}:</strong> {$oi.extra.nights}<br>
            {/if}

            {if $oi.extra.package_name}
            <strong>{__("novoton_holidays.package")|default:"Pachet"}:</strong> {$oi.extra.package_name}<br>
            {/if}

            {if $oi.extra.room_type_display || $oi.extra.room_name}
            <strong>{__("novoton_holidays.room_type")|default:"Tip Cameră"}:</strong> {$oi.extra.room_type_display|default:$oi.extra.room_name}<br>
            {/if}

            {if $oi.extra.board_name}
            <strong>{__("novoton_holidays.board")|default:"Masă"}:</strong> {$oi.extra.board_name}<br>
            {/if}

            <strong>{__("novoton_holidays.guests")|default:"Oaspeți"}:</strong>
            {$oi.extra.adults|default:2} {if $oi.extra.adults == 1}{__("novoton_holidays.adult")|default:"Adult"}{else}{__("novoton_holidays.adults")|default:"Adulți"}{/if}{if $oi.extra.children > 0}, {$oi.extra.children} {if $oi.extra.children == 1}{__("novoton_holidays.child")|default:"Copil"}{else}{__("novoton_holidays.children")|default:"Copii"}{/if}{/if}
            <br>

            {* Guest Names List *}
            {if $oi.extra.guests_data}
                {if is_string($oi.extra.guests_data)}
                    {$guests_list = $oi.extra.guests_data|@json_decode:true}
                {else}
                    {$guests_list = $oi.extra.guests_data}
                {/if}

                {if $guests_list && is_array($guests_list)}
                    {$num_rooms = $oi.extra.num_rooms|default:1}
                    {$guest_number = 0}

                    {if $num_rooms > 1}
                        {* Multiple rooms - group by room *}
                        {for $room_num=1 to $num_rooms}
                            <br><strong>{__("novoton_holidays.room")|default:"Camera"} {$room_num}:</strong><br>
                            {foreach from=$guests_list item=guest}
                                {if $guest.room == $room_num}
                                    {$guest_number = $guest_number + 1}
                                    {$guest_name = $guest.name|default:"`$guest.last_name` `$guest.first_name`"|trim}
                                    &nbsp;&nbsp;{$guest_number}. {$guest_name}{if $guest.is_holder} ({__("novoton_holidays.holder")}){elseif $guest.type == 'child'} ({__("novoton_holidays.child")|default:"copil"}, {$guest.age} {__("novoton_holidays.years")}){/if}<br>
                                {/if}
                            {/foreach}
                        {/for}
                    {else}
                        {* Single room - simple list *}
                        {foreach from=$guests_list item=guest}
                            {$guest_number = $guest_number + 1}
                            {$guest_name = $guest.name|default:"`$guest.last_name` `$guest.first_name`"|trim}
                            &nbsp;&nbsp;{$guest_number}. {$guest_name}{if $guest.is_holder} ({__("novoton_holidays.holder")}){elseif $guest.type == 'child'} ({__("novoton_holidays.child")|default:"copil"}, {$guest.age} {__("novoton_holidays.years")}){/if}<br>
                        {/foreach}
                    {/if}
                {/if}
            {/if}

            {* Payment Terms with Amounts *}
            {$payment_terms_xml = $oi.extra.terms_of_payment|default:$oi.extra.payment_terms|default:''}
            {if $payment_terms_xml}
                {$booking_price = $oi.extra.price|default:$oi.price|default:0}
                {$currency = $oi.extra.currency|default:'EUR'}
                {capture name="payment_terms_formatted"}{fn_novoton_format_payment_terms_with_amounts($payment_terms_xml, $booking_price, $currency)}{/capture}
                {if $smarty.capture.payment_terms_formatted}
                    <br>
                    <strong>{__("novoton_holidays.payment_terms")|default:"Termeni de plată"}:</strong><br>
                    {$smarty.capture.payment_terms_formatted|escape:'html'|nl2br nofilter}
                {/if}
            {/if}
        </td>
    </tr>
</table>
{/if}
