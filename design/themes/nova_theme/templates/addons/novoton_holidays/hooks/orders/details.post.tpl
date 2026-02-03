{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}
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

{* Display terms for each hotel *}
{if $_nv_hotels_terms|@count > 0}
<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
    {foreach from=$_nv_hotels_terms item=_hotel_terms key=_hotel_id name=hotel_loop}
        {if !$smarty.foreach.hotel_loop.first}
            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #dee2e6;">
        {/if}

        {* Show hotel name if multiple hotels *}
        {if $_nv_hotels_terms|@count > 1}
            <p style="margin: 0 0 10px 0; font-weight: bold; color: #495057;">
                {$_hotel_terms.hotel_name}
            </p>
        {/if}

        {if $_hotel_terms.payment}
        <p style="margin: 0 0 10px 0;"><strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}</strong><br>
        <span style="white-space: pre-line;">{$_hotel_terms.payment|replace:"<br />":"\n"|replace:"<br>":"\n"|replace:"<br/>":"\n"|trim}</span></p>
        {/if}

        {if $_hotel_terms.cancel}
        <p style="margin: 0;"><strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}</strong><br>
        <span style="white-space: pre-line;">{$_hotel_terms.cancel|replace:"<br />":"\n"|replace:"<br>":"\n"|replace:"<br/>":"\n"|trim}</span></p>
        {/if}
    {/foreach}
</div>
{/if}
