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
            {$oi.extra.adults|default:2} {if $oi.extra.adults == 1}{__("novoton_holidays.adult")|default:"Adult"}{else}{__("novoton_holidays.adults")|default:"Adulți"}{/if}
            {if $oi.extra.children > 0}, {$oi.extra.children} {if $oi.extra.children == 1}{__("novoton_holidays.child")|default:"Copil"}{else}{__("novoton_holidays.children")|default:"Copii"}{/if} ({$oi.extra.children_ages}){/if}
            <br>

            {if $oi.extra.holder_name}
            <strong>{__("novoton_holidays.holder")|default:"Titular"}:</strong> {$oi.extra.holder_name}<br>
            {/if}
        </td>
    </tr>
</table>
{/if}
