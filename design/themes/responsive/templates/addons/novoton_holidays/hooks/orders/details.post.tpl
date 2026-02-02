{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* Hook: orders:details — fires after the products table *}

{$_nv_has_terms = false}
{$_nv_payment = ""}
{$_nv_cancel = ""}

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
    <p><strong>{__("novoton_holidays.cancellation_policy")|default:"Politica de anulare"}</strong><br>
    {$_nv_cancel|strip_tags|trim|nl2br}</p>
    {/if}
{/if}
