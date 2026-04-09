{* Novoton Holidays - Customer Order Details - Simple text display *}

{if !empty($product.extra.novoton_booking)}
{* Normalize rooms_data: may be JSON string or array *}
{if $product.extra.rooms_data && is_string($product.extra.rooms_data)}
    {$_nvt_rooms = $product.extra.rooms_data|json_decode:true}
{else}
    {$_nvt_rooms = $product.extra.rooms_data|default:[]}
{/if}
<div style="margin:10px 0;font-size:13px;line-height:1.8;">

    {if $product.extra.hotel_name}
    <strong>Hotel:</strong> {$product.extra.hotel_name|escape:'html'}<br>
    {/if}

    <strong>Check-in:</strong> {$product.extra.check_in|default:''|date_format:"%d.%m.%Y"} |
    <strong>Check-out:</strong> {$product.extra.check_out|default:''|date_format:"%d.%m.%Y"} |
    <strong>{__("novoton_holidays.nights")|default:"Nopți"}:</strong> {$product.extra.nights}<br>

    {if $product.extra.package_name}
    <strong>{__("novoton_holidays.package")|default:"Pachet"}:</strong> {$product.extra.package_name}<br>
    {/if}

    {if $product.extra.num_rooms > 1 && $_nvt_rooms}
        <strong>{__("novoton_holidays.n_rooms", [$product.extra.num_rooms])}:</strong><br>
        {foreach from=$_nvt_rooms item=room key=idx}
            {$room_display = $room.room_id|default:$room.room_name|default:''}
            &nbsp;&nbsp;- <strong>{__("novoton_holidays.room")} {$idx+1}:</strong> {if $room_display}{$room_display|novoton_format_room_type}{else}{$room.room_type_display|default:'Room'|escape:'html'}{/if} | {$room.board_id|default:$room.board_name|default:''|novoton_format_board} | {__("novoton_holidays.n_adults", [$room.adults|default:0])}{if $room.children}, {__("novoton_holidays.n_children", [$room.children])}{if $room.children_ages_str} ({$room.children_ages_str}){/if}{/if} | {$room.price|default:0} {$smarty.const.CART_PRIMARY_CURRENCY}<br>
        {/foreach}
    {else}
        {$room_id_raw = $product.extra.room_id|default:''}
        {$room_display = $product.extra.room_type_display|default:''}
        {$board_raw = $product.extra.board_id|default:''}
        {if $room_id_raw || $room_display}<strong>{__("novoton_holidays.room_type")}:</strong> {if $room_id_raw}{$room_id_raw|novoton_format_room_type}{else}{$room_display|escape:'html'}{/if}<br>{/if}
        {if $board_raw}<strong>{__("novoton_holidays.board")}:</strong> {$board_raw|novoton_format_board}<br>{/if}
        <strong>{__("novoton_holidays.guests")}:</strong> {__("novoton_holidays.n_adults", [$product.extra.adults|default:0])}{if $product.extra.children}, {__("novoton_holidays.n_children", [$product.extra.children])}{if $product.extra.children_ages} ({$product.extra.children_ages}){/if}{/if}<br>
    {/if}

    {* Guest Names *}
    {if $product.extra.guests_data}
        {$guests = $product.extra.guests_data|json_decode:true}
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
                &nbsp;&nbsp;{__("novoton_holidays.adults")}: {foreach from=$adult_guests item=guest name=adults}{$guest.name|escape:'html'}{if $guest.room} ({__("novoton_holidays.room")} {$guest.room}){/if}{if !$smarty.foreach.adults.last}, {/if}{/foreach}<br>
            {/if}
            {if $child_guests}
                &nbsp;&nbsp;{__("novoton_holidays.children")}: {foreach from=$child_guests item=guest name=children}{$guest.name|escape:'html'} ({$guest.age}){if $guest.room} ({__("novoton_holidays.room")} {$guest.room}){/if}{if !$smarty.foreach.children.last}, {/if}{/foreach}<br>
            {/if}
        {/if}
    {elseif $product.extra.holder_name}
        <strong>{__("novoton_holidays.holder")}:</strong> {$product.extra.holder_name}<br>
    {/if}

    {* Payment and Cancellation Terms Link *}
    {$payment_terms_raw = $product.extra.terms_of_payment_raw|default:$product.extra.terms_of_payment|default:''}
    {$cancel_terms_raw = $product.extra.terms_of_cancellation_raw|default:$product.extra.terms_of_cancellation|default:''}
    {if $payment_terms_raw || $cancel_terms_raw}
        {$modal_id = "terms-modal-`$product.product_id`-`$product.extra.item_id|default:$smarty.foreach.oi.index|default:0`"}
        <div style="margin-top: 8px;">
            <a href="#" onclick="document.getElementById('{$modal_id}').style.display='flex'; return false;"
               style="color: #0071c2; text-decoration: none; border-bottom: 1px dashed #0071c2; font-size: 13px;">
                📋 {__("novoton_holidays.payment_cancellation_terms_link")|default:"Condiții de Anulare și Plată"}
            </a>
        </div>

        {* Modal for Terms *}
        <div id="{$modal_id}" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: #fff; border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">
                    <h3 style="margin: 0; font-size: 18px; color: #333;">{__("novoton_holidays.payment_cancellation_terms_link")|default:"Condiții de Anulare și Plată"}</h3>
                    <button onclick="this.closest('[id^=terms-modal]').style.display='none'" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #666; padding: 0; line-height: 1;">&times;</button>
                </div>
                <div style="padding: 20px; font-size: 14px; line-height: 1.6; color: #333;">
                    {* Payment Terms *}
                    {if $payment_terms_raw}
                        {$booking_price = $product.extra.price|default:$product.price|default:0}
                        {$_payment_formatted = fn_novoton_holidays_format_payment_terms_with_amounts($payment_terms_raw, $booking_price, $smarty.const.CART_PRIMARY_CURRENCY)}
                        {if $_payment_formatted}
                            <div style="margin-bottom: 20px;">
                                <strong style="display: block; margin-bottom: 8px; color: #003580; font-size: 15px;">{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}</strong>
                                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 3px solid #0071c2;">
                                    {$_payment_formatted|escape:'html'|nl2br nofilter}
                                </div>
                            </div>
                        {/if}
                    {/if}

                    {* Cancellation Terms *}
                    {if $cancel_terms_raw}
                        {$check_in = $product.extra.check_in|default:''}
                        {$_cancel_formatted = fn_novoton_holidays_format_cancellation_terms($cancel_terms_raw, $check_in)}
                        {if $_cancel_formatted}
                            <div>
                                <strong style="display: block; margin-bottom: 8px; color: #003580; font-size: 15px;">{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}</strong>
                                <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 3px solid #28a745;">
                                    {$_cancel_formatted|escape:'html'|nl2br nofilter}
                                </div>
                            </div>
                        {/if}
                    {/if}
                </div>
            </div>
        </div>
    {/if}


</div>
{/if}
