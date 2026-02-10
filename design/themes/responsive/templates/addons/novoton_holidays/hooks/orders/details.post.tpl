{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}
{* Groups by hotel_id so multiple products from same hotel show terms only once *}

{$_nv_hotels_terms = []}
{$_nv_hotels_prices = []}

{* Debug output when ?debug=1 *}
{if $smarty.request.debug}
<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;margin:15px 0;font-family:monospace;font-size:12px;">
    <strong>DEBUG: Novoton Order Terms</strong><br>
    order_info.products count: {$order_info.products|@count|default:0}<br>
    {foreach from=$order_info.products item=dbg_product key=dbg_key}
        <hr style="margin:5px 0;">
        Product [{$dbg_key}]: {$dbg_product.product|default:'?'}<br>
        - novoton_booking: {if $dbg_product.extra.novoton_booking}YES{else}NO{/if}<br>
        - hotel_id: {$dbg_product.extra.hotel_id|default:'(empty)'}<br>
        - hotel_name: {$dbg_product.extra.hotel_name|default:'(empty)'}<br>
        - price: {$dbg_product.extra.price|default:$dbg_product.price|default:'(empty)'}<br>
        - terms_of_payment_raw: {if $dbg_product.extra.terms_of_payment_raw}"{$dbg_product.extra.terms_of_payment_raw|truncate:80}"{else}(empty){/if}<br>
        - terms_of_cancellation_raw: {if $dbg_product.extra.terms_of_cancellation_raw}"{$dbg_product.extra.terms_of_cancellation_raw|truncate:80}"{else}(empty){/if}<br>
        - extra keys: {if is_array($dbg_product.extra)}{","|implode:$dbg_product.extra|@array_keys}{else}(not array){/if}<br>
    {/foreach}
</div>
{/if}

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

            {* Skip if we already have terms for this hotel *}
            {if isset($_nv_hotels_terms[$_hotel_id])}
                {continue}
            {/if}

            {$_hotel_name = $product.extra.hotel_name|default:$product.product|default:'Hotel'}
            {$_payment_raw = $product.extra.terms_of_payment_raw|default:$product.extra.terms_of_payment|default:''}
            {$_cancel_raw = $product.extra.terms_of_cancellation_raw|default:$product.extra.terms_of_cancellation|default:''}
            {$_check_in = $product.extra.check_in|default:''}
            {$_currency = $product.extra.currency|default:'EUR'}

            {* Use aggregated price for this hotel *}
            {$_total_price = $_nv_hotels_prices[$_hotel_id]|default:$product.extra.price|default:$product.price|default:0}

            {* Format payment terms with amounts *}
            {$_payment = ""}
            {if $_payment_raw}
                {capture name="payment_fmt"}{fn_novoton_format_payment_terms_with_amounts($_payment_raw, $_total_price, $_currency)}{/capture}
                {$_payment = $smarty.capture.payment_fmt}
            {/if}

            {* Format cancellation terms *}
            {$_cancel = ""}
            {if $_cancel_raw}
                {capture name="cancel_fmt"}{fn_novoton_format_cancellation_terms($_cancel_raw, $_check_in)}{/capture}
                {$_cancel = $smarty.capture.cancel_fmt}
            {/if}

            {* Add if we have terms *}
            {if $_payment || $_cancel}
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
