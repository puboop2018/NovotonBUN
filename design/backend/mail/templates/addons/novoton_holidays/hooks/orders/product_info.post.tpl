{* Novoton Holidays - Order Email Product Info Hook *}
{* Hook: orders:product_info — adds booking details to each product in order emails *}
{* Terms are displayed once per package (not per room) *}

{if !empty($oi.extra.novoton_booking)}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 5px; font-size: 12px; color: #666;">
    <tr>
        <td>
            {* 1. Hotel name with location - bold *}
            {if $oi.extra.hotel_name}
                {$hotel_display = $oi.extra.hotel_name|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'}
                {$location_parts = []}
                {if $oi.extra.hotel_city}
                    {$location_parts[] = $oi.extra.hotel_city|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'}
                {/if}
                {if $oi.extra.hotel_region}
                    {$location_parts[] = $oi.extra.hotel_region|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'}
                {/if}
                {if $oi.extra.hotel_country}
                    {$location_parts[] = $oi.extra.hotel_country|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'}
                {/if}
                <strong>{$hotel_display}{if $location_parts}, {", "|implode:$location_parts}{/if}</strong><br>
            {/if}

            {* 2. Dates and nights - bold values, format: "05 Jul 2026, 12 Jul 2026, 7" *}
            {if $oi.extra.check_in && $oi.extra.check_out}
                <strong>{$oi.extra.check_in|date_format:"%d %b %Y"}, {$oi.extra.check_out|date_format:"%d %b %Y"}, {$oi.extra.nights}</strong><br>
            {/if}

            {* 3. Room type + Board on one line - bold *}
            {if $oi.extra.room_type_display || $oi.extra.room_name || $oi.extra.board_name}
                <strong>{$oi.extra.room_type_display|default:$oi.extra.room_name}{if $oi.extra.board_name}, {$oi.extra.board_name}{/if}</strong><br>
            {/if}

            {* 4. Guest count - bold *}
            <strong>{__("novoton_holidays.n_adults", [$oi.extra.adults|default:2])}{if $oi.extra.children > 0}, {__("novoton_holidays.n_children", [$oi.extra.children])}{/if}</strong><br>

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

                    <strong>{__("novoton_holidays.guest_names")|default:"Nume turiști"}:</strong><br>

                    {if $num_rooms > 1}
                        {* Multiple rooms - group by room *}
                        {for $room_num=1 to $num_rooms}
                            <strong>{__("novoton_holidays.room")|default:"Camera"} {$room_num}:</strong><br>
                            {foreach from=$guests_list item=guest}
                                {if $guest.room == $room_num}
                                    {$guest_number = $guest_number + 1}
                                    {$guest_name = $guest.name|default:"`$guest.last_name`, `$guest.first_name`"|trim}
                                    &nbsp;&nbsp;{$guest_name}{if $guest.is_holder} ({__("novoton_holidays.holder")|default:"Holder"}){elseif $guest.type == 'child'} ({__("novoton_holidays.child")|default:"copil"}, {__("novoton_holidays.n_years", [$guest.age])}){/if}<br>
                                {/if}
                            {/foreach}
                        {/for}
                    {else}
                        {* Single room - simple list *}
                        {foreach from=$guests_list item=guest}
                            {$guest_number = $guest_number + 1}
                            {$guest_name = $guest.name|default:"`$guest.last_name`, `$guest.first_name`"|trim}
                            &nbsp;&nbsp;{$guest_name}{if $guest.is_holder} ({__("novoton_holidays.holder")|default:"Holder"}){elseif $guest.type == 'child'} ({__("novoton_holidays.child")|default:"copil"}, {__("novoton_holidays.n_years", [$guest.age])}){/if}<br>
                        {/foreach}
                    {/if}
                {/if}
            {/if}

            {* Payment and Cancellation Terms - show once per package *}
            {* Create unique key based on package_name to track which packages have shown terms *}
            {$package_key = $oi.extra.package_name|default:'default'}
            {$payment_terms_raw = $oi.extra.terms_of_payment_raw|default:$oi.extra.terms_of_payment|default:''}
            {$cancel_terms_raw = $oi.extra.terms_of_cancellation_raw|default:$oi.extra.terms_of_cancellation|default:''}

            {* Initialize tracking array if not exists *}
            {if !isset($novoton_shown_packages)}
                {$novoton_shown_packages = []}
            {/if}

            {* Only show terms if this package hasn't been shown yet *}
            {if ($payment_terms_raw || $cancel_terms_raw) && !in_array($package_key, $novoton_shown_packages)}
                {* Mark this package as shown *}
                {$novoton_shown_packages[] = $package_key}

                {* Payment Terms with Amounts *}
                {if $payment_terms_raw}
                    {$booking_price = $oi.extra.price|default:$oi.price|default:0}
                    {$currency = $oi.extra.currency|default:'EUR'}
                    {capture name="payment_terms_formatted"}{fn_novoton_format_payment_terms_with_amounts($payment_terms_raw, $booking_price, $currency)}{/capture}
                    {if $smarty.capture.payment_terms_formatted}
                        <br>
                        <strong>{__("novoton_holidays.terms_of_payment")|default:"Condiții de plată"}</strong><br>
                        {$smarty.capture.payment_terms_formatted|escape:'html'|nl2br nofilter}
                    {/if}
                {/if}

                {* Cancellation Terms *}
                {if $cancel_terms_raw}
                    {$check_in = $oi.extra.check_in|default:''}
                    {capture name="cancel_terms_formatted"}{fn_novoton_format_cancellation_terms($cancel_terms_raw, $check_in)}{/capture}
                    {if $smarty.capture.cancel_terms_formatted}
                        <br>
                        <strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}</strong><br>
                        {$smarty.capture.cancel_terms_formatted|escape:'html'|nl2br nofilter}
                    {/if}
                {/if}
            {/if}
        </td>
    </tr>
</table>
{/if}
