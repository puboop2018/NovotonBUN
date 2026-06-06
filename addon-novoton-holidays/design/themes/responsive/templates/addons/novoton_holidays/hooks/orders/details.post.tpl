{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}
{* Groups by hotel_id so multiple products from same hotel show terms only once *}
{''|novoton_trace:"ENTER orders/details.post.tpl"}

{$_nv_hotels_terms = []}
{$_nv_hotels_prices = []}

{* First pass: collect prices per hotel *}
{if $order_info.products}
    {foreach from=$order_info.products item=product key=item_id}
        {if !empty($product.extra.novoton_booking) && $product.extra.hotel_id}
            {$_hotel_id = $product.extra.hotel_id}
            {$_price = $product.extra.price|default:$product.price|default:0}
            {if isset($_nv_hotels_prices[$_hotel_id])}
                {$_nv_hotels_prices[$_hotel_id] = $_nv_hotels_prices[$_hotel_id] + $_price}
            {else}
                {$_nv_hotels_prices[$_hotel_id] = $_price}
            {/if}
        {/if}
    {/foreach}
{/if}

{* Second pass: collect terms per hotel (only first occurrence) *}
{if $order_info.products}
    {foreach from=$order_info.products item=product key=item_id}
        {if !empty($product.extra.novoton_booking)}
            {* Use hotel_id as key to group by hotel *}
            {$_hotel_id = $product.extra.hotel_id|default:$item_id}

            {* Only process if we don't already have terms for this hotel *}
            {if !isset($_nv_hotels_terms[$_hotel_id])}
                {$_hotel_name = $product.extra.hotel_name|default:$product.product|default:'Hotel'}
                {$_check_in = $product.extra.check_in|default:''}
                {$_currency = $product.extra.currency|default:$smarty.const.CART_PRIMARY_CURRENCY}

                {* Use aggregated price for this hotel *}
                {$_total_price = $_nv_hotels_prices[$_hotel_id]|default:$product.extra.price|default:$product.price|default:0}

                {* Terms are pre-formatted in fn_novoton_holidays_get_order_info — never call
                   fn_*() inside the {capture}, which throws under Smarty 5 and breaks the page. *}
                {$_payment = $product.extra.terms_of_payment_with_amounts|default:$product.extra.terms_of_payment_formatted|default:""}
                {$_cancel = $product.extra.terms_of_cancellation_formatted|default:""}

                {* Add if we have terms *}
                {if $_payment || $_cancel}
                    {$_nv_hotels_terms[$_hotel_id] = [
                        'hotel_name' => $_hotel_name,
                        'payment' => $_payment,
                        'cancel' => $_cancel
                    ]}
                {/if}
            {/if}
        {/if}
    {/foreach}
{/if}

{* Display terms for each hotel *}
{if $_nv_hotels_terms|count > 0}
<div style="margin-top: 20px; padding: 15px;">
    {foreach from=$_nv_hotels_terms item=_hotel_terms key=_hotel_id name=hotel_loop}
        {if !$smarty.foreach.hotel_loop.first}
            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #dee2e6;">
        {/if}

        {* Always show hotel name for clarity *}
        <p style="margin: 0 0 10px 0; font-weight: bold; color: #495057;">
            {$_hotel_terms.hotel_name}
        </p>

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
{''|novoton_trace:"EXIT orders/details.post.tpl"}
