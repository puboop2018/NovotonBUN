{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}

{$_nv_has_terms = false}
{$_nv_payment = ""}
{$_nv_cancel = ""}

{* Debug output when ?debug=1 *}
{if $smarty.request.debug}
<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;margin:15px 0;font-family:monospace;font-size:12px;">
    <strong>DEBUG: Novoton Order Terms</strong><br>
    order_info.products count: {$order_info.products|@count|default:0}<br>
    {foreach from=$order_info.products item=dbg_product key=dbg_key}
        <hr style="margin:5px 0;">
        Product [{$dbg_key}]: {$dbg_product.product|default:'?'}<br>
        - novoton_booking: {if $dbg_product.extra.novoton_booking}YES{else}NO{/if}<br>
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

{if $order_info.products}
    {foreach from=$order_info.products item=product}
        {if !empty($product.extra.novoton_booking) && !$_nv_has_terms}
            {* Use formatted version (set by PHP hook), fall back to stored text *}
            {if $product.extra.terms_of_payment_formatted}
                {$_nv_payment = $product.extra.terms_of_payment_formatted}
            {elseif $product.extra.terms_of_payment}
                {$_nv_payment = $product.extra.terms_of_payment}
            {/if}

            {if $product.extra.terms_of_cancellation_formatted}
                {$_nv_cancel = $product.extra.terms_of_cancellation_formatted}
            {elseif $product.extra.terms_of_cancellation}
                {$_nv_cancel = $product.extra.terms_of_cancellation}
            {/if}

            {if $_nv_payment || $_nv_cancel}
                {$_nv_has_terms = true}
            {/if}
        {/if}
    {/foreach}
{/if}

{if $_nv_has_terms}
    {if $_nv_payment}
    <p><strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}</strong><br>
    {$_nv_payment|strip_tags|trim|nl2br}</p>
    {/if}

    {if $_nv_cancel}
    <p><strong>{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}</strong><br>
    {$_nv_cancel|strip_tags|trim|nl2br}</p>
    {/if}
{/if}
