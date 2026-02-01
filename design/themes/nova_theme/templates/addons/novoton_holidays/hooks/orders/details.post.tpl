{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}
{* This hook fires after the products table, before Customer notes *}

{* Collect unique terms from all Novoton booking products in this order *}
{$all_payment_terms = []}
{$all_cancel_terms = []}

{if $order_info.products}
    {foreach from=$order_info.products item=product}
        {if !empty($product.extra.novoton_booking)}
            {* Try formatted version first, then raw, then fallbacks *}
            {$pt = $product.extra.terms_of_payment_formatted|default:$product.extra.terms_of_payment|default:$product.extra.payment_terms|default:$product.extra.remark|default:''}
            {if $pt}
                {$pt = $pt|strip_tags|trim}
                {if $pt && !in_array($pt, $all_payment_terms)}
                    {$all_payment_terms[] = $pt}
                {/if}
            {/if}

            {$ct = $product.extra.terms_of_cancellation_formatted|default:$product.extra.terms_of_cancellation|default:$product.extra.cancellation_terms|default:$product.extra.important|default:''}
            {if $ct}
                {$ct = $ct|strip_tags|trim}
                {if $ct && !in_array($ct, $all_cancel_terms)}
                    {$all_cancel_terms[] = $ct}
                {/if}
            {/if}
        {/if}
    {/foreach}
{/if}

{if $all_payment_terms|@count > 0 || $all_cancel_terms|@count > 0}
<div style="margin: 15px 0;">

    {if $all_payment_terms|@count > 0}
    <div style="margin-bottom: {if $all_cancel_terms|@count > 0}10px{else}0{/if};">
        <strong>{__("novoton_holidays.terms_of_payment")|default:"Terms of Payment"}</strong><br>
        {foreach from=$all_payment_terms item=terms}
        <span style="white-space: pre-line;">{$terms}</span>
        {/foreach}
    </div>
    {/if}

    {if $all_cancel_terms|@count > 0}
    <div>
        <strong>{__("novoton_holidays.cancellation_policy")|default:"Cancellation Policy"}</strong><br>
        {foreach from=$all_cancel_terms item=terms}
        <span style="white-space: pre-line;">{$terms}</span>
        {/foreach}
    </div>
    {/if}

</div>
{/if}
