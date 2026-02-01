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
<div class="ty-orders-detail__novoton-terms" style="margin: 20px 0; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; border-left: 4px solid #003580;">

    {if $all_payment_terms|@count > 0}
    <div style="margin-bottom: {if $all_cancel_terms|@count > 0}16px{else}0{/if};">
        <h3 style="font-size: 15px; font-weight: 600; color: #333; margin: 0 0 8px 0;">{__("novoton_holidays.terms_of_payment")|default:"Terms of Payment"}</h3>
        {foreach from=$all_payment_terms item=terms}
        <div style="color: #555; font-size: 13px; line-height: 1.7; white-space: pre-line;">{$terms}</div>
        {/foreach}
    </div>
    {/if}

    {if $all_cancel_terms|@count > 0}
    <div>
        <h3 style="font-size: 15px; font-weight: 600; color: #333; margin: 0 0 8px 0;">{__("novoton_holidays.cancellation_policy")|default:"Cancellation Policy"}</h3>
        {foreach from=$all_cancel_terms item=terms}
        <div style="color: #555; font-size: 13px; line-height: 1.7; white-space: pre-line;">{$terms}</div>
        {/foreach}
    </div>
    {/if}

</div>
{/if}
