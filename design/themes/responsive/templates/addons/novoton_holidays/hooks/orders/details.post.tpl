{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}
{* Supports multiple hotels with different terms *}

{$_nv_hotels_terms = []}

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
        - terms_of_payment: {if $dbg_product.extra.terms_of_payment}"{$dbg_product.extra.terms_of_payment|truncate:50}"{else}(empty){/if}<br>
        - terms_of_payment_raw: {if $dbg_product.extra.terms_of_payment_raw}"{$dbg_product.extra.terms_of_payment_raw|truncate:50}"{else}(empty){/if}<br>
        - terms_of_payment_formatted: {if $dbg_product.extra.terms_of_payment_formatted}"{$dbg_product.extra.terms_of_payment_formatted|truncate:50}"{else}(empty){/if}<br>
        - terms_of_cancellation: {if $dbg_product.extra.terms_of_cancellation}"{$dbg_product.extra.terms_of_cancellation|truncate:50}"{else}(empty){/if}<br>
        - terms_of_cancellation_raw: {if $dbg_product.extra.terms_of_cancellation_raw}"{$dbg_product.extra.terms_of_cancellation_raw|truncate:50}"{else}(empty){/if}<br>
        - terms_of_cancellation_formatted: {if $dbg_product.extra.terms_of_cancellation_formatted}"{$dbg_product.extra.terms_of_cancellation_formatted|truncate:50}"{else}(empty){/if}<br>
        - extra keys: {$dbg_product.extra|@array_keys|@implode:", "}<br>
    {/foreach}
</div>
{/if}

{* Collect terms from all hotel bookings *}
{if $order_info.products}
    {foreach from=$order_info.products item=product key=item_id}
        {if !empty($product.extra.novoton_booking)}
            {* Use item_id as unique key to ensure each booking shows its terms *}
            {$_booking_key = $item_id}
            {$_hotel_name = $product.extra.hotel_name|default:$product.product|default:'Hotel'}

            {* Get raw XML terms and format on-the-fly for consistent date format *}
            {$_payment = ""}
            {$_cancel = ""}
            {$_payment_raw = $product.extra.terms_of_payment_raw|default:$product.extra.terms_of_payment|default:''}
            {$_cancel_raw = $product.extra.terms_of_cancellation_raw|default:$product.extra.terms_of_cancellation|default:''}
            {$_check_in = $product.extra.check_in|default:''}
            {$_price = $product.extra.price|default:$product.price|default:0}
            {$_currency = $product.extra.currency|default:'EUR'}

            {* Format payment terms with amounts using current date format *}
            {if $_payment_raw}
                {capture name="payment_fmt"}{fn_novoton_format_payment_terms_with_amounts($_payment_raw, $_price, $_currency)}{/capture}
                {$_payment = $smarty.capture.payment_fmt}
            {/if}

            {* Format cancellation terms using current date format *}
            {if $_cancel_raw}
                {capture name="cancel_fmt"}{fn_novoton_format_cancellation_terms($_cancel_raw, $_check_in)}{/capture}
                {$_cancel = $smarty.capture.cancel_fmt}
            {/if}

            {* Add if we have terms *}
            {if $_payment || $_cancel}
                {$_nv_hotels_terms[$_booking_key] = [
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
