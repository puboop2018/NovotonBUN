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
<div class="ty-orders-detail__terms" style="margin: 20px 0; padding: 15px; border: 1px solid #e0e0e0; border-radius: 4px; background: #fafafa;">

    {if $_nv_payment}
    <div style="margin-bottom: {if $_nv_cancel}12px{else}0{/if};">
        <strong>{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}</strong><br>
        <span style="white-space: pre-line;">{$_nv_payment|strip_tags|trim}</span>
    </div>
    {/if}

    {if $_nv_cancel}
    <div>
        <strong>{__("novoton_holidays.cancellation_policy")|default:"Politica de anulare"}</strong><br>
        <span style="white-space: pre-line;">{$_nv_cancel|strip_tags|trim}</span>
    </div>
    {/if}

</div>
{/if}
