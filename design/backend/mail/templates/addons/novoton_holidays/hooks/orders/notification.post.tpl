{* Novoton Holidays - Order Email Notification Hook *}
{* Hook: orders:notification — adds terms to order notification emails *}
{* Supports multiple hotels with different terms *}

{$_nv_hotels_terms = []}

{* Collect terms from all hotel bookings *}
{if $order_info.products}
    {foreach from=$order_info.products item=product}
        {if !empty($product.extra.novoton_booking)}
            {$_hotel_id = $product.extra.hotel_id|default:'unknown'}
            {$_hotel_name = $product.extra.hotel_name|default:$product.product|default:'Hotel'}

            {* Get terms - formatted version first, then stored text *}
            {$_payment = ""}
            {$_cancel = ""}

            {if $product.extra.terms_of_payment_formatted}
                {$_payment = $product.extra.terms_of_payment_formatted}
            {elseif $product.extra.terms_of_payment}
                {$_payment = $product.extra.terms_of_payment}
            {/if}

            {if $product.extra.terms_of_cancellation_formatted}
                {$_cancel = $product.extra.terms_of_cancellation_formatted}
            {elseif $product.extra.terms_of_cancellation}
                {$_cancel = $product.extra.terms_of_cancellation}
            {/if}

            {* Only add if we have terms and haven't already added this hotel *}
            {if ($_payment || $_cancel) && !isset($_nv_hotels_terms[$_hotel_id])}
                {$_nv_hotels_terms[$_hotel_id] = [
                    'hotel_name' => $_hotel_name,
                    'payment' => $_payment,
                    'cancel' => $_cancel
                ]}
            {/if}
        {/if}
    {/foreach}
{/if}

{* Display terms section in email *}
{if $_nv_hotels_terms|@count > 0}
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 20px;">
    <tr>
        <td style="padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                {foreach from=$_nv_hotels_terms item=_hotel_terms key=_hotel_id name=hotel_loop}
                    {if !$smarty.foreach.hotel_loop.first}
                    <tr>
                        <td style="padding: 10px 0;">
                            <hr style="border: 0; border-top: 1px solid #dee2e6; margin: 0;">
                        </td>
                    </tr>
                    {/if}

                    {* Show hotel name if multiple hotels *}
                    {if $_nv_hotels_terms|@count > 1}
                    <tr>
                        <td style="padding-bottom: 10px; font-weight: bold; color: #495057; font-size: 14px;">
                            {$_hotel_terms.hotel_name}
                        </td>
                    </tr>
                    {/if}

                    {if $_hotel_terms.payment}
                    <tr>
                        <td style="padding-bottom: 10px;">
                            <strong style="color: #333;">{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}</strong><br>
                            <span style="color: #555; font-size: 13px; line-height: 1.6;">{$_hotel_terms.payment|replace:"<br />":"<br>"|replace:"\n":"<br>"}</span>
                        </td>
                    </tr>
                    {/if}

                    {if $_hotel_terms.cancel}
                    <tr>
                        <td>
                            <strong style="color: #333;">{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}</strong><br>
                            <span style="color: #555; font-size: 13px; line-height: 1.6;">{$_hotel_terms.cancel|replace:"<br />":"<br>"|replace:"\n":"<br>"}</span>
                        </td>
                    </tr>
                    {/if}
                {/foreach}
            </table>
        </td>
    </tr>
</table>
{/if}
