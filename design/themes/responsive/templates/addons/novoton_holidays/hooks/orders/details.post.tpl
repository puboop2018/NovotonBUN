{* Novoton Holidays - Order Details Hook - Terms of Payment & Cancellation *}

{* DEBUG - Always show when debug_novoton is set *}
{if $smarty.request.debug_novoton}
<div style="margin:15px 0;padding:15px;background:#e3f2fd;border:1px solid #2196f3;border-radius:4px;font-size:12px;">
    <strong> DEBUG: details.post.tpl hook triggered</strong><br>
    Order ID: {$order_info.order_id|default:'N/A'}<br>
    Products count: {$order_info.products|@count|default:0}<br>
    {if $order_info.products}
        {foreach from=$order_info.products item=product key=pid}
        <div style="margin-top:10px;padding:10px;background:#fff;border-radius:4px;">
            <strong>Product #{$pid}:</strong> {$product.product}<br>
            novoton_booking: {if !empty($product.extra.novoton_booking)}YES{else}NO{/if}<br>
            {if !empty($product.extra.novoton_booking)}
            <strong>Extra keys:</strong> {foreach from=$product.extra key=k item=v}{$k}, {/foreach}<br>
            <strong>terms_of_payment:</strong> {$product.extra.terms_of_payment|default:'[empty]'|truncate:100}<br>
            <strong>payment_terms:</strong> {$product.extra.payment_terms|default:'[empty]'|truncate:100}<br>
            <strong>remark:</strong> {$product.extra.remark|default:'[empty]'|truncate:100}<br>
            <strong>terms_of_cancellation:</strong> {$product.extra.terms_of_cancellation|default:'[empty]'|truncate:100}<br>
            <strong>cancellation_terms:</strong> {$product.extra.cancellation_terms|default:'[empty]'|truncate:100}<br>
            <strong>important:</strong> {$product.extra.important|default:'[empty]'|truncate:100}<br>
            {/if}
        </div>
        {/foreach}
    {/if}
</div>
{/if}

{if $order_info.products}
    {foreach from=$order_info.products item=product}
        {if !empty($product.extra.novoton_booking)}
            {$payment_terms = $product.extra.terms_of_payment|default:$product.extra.payment_terms|default:$product.extra.remark|default:''}
            {$cancel_terms = $product.extra.terms_of_cancellation|default:$product.extra.cancellation_terms|default:$product.extra.important|default:''}
            
            {if $payment_terms || $cancel_terms}
            <div class="ty-orders-detail__novoton-terms" style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 3px solid #003580;">
                {if $payment_terms}
                <p style="margin-bottom: 10px;">
                    <strong>{__("novoton_holidays.terms_of_payment")|default:"Terms of Payment"}:</strong><br>
                    {$payment_terms|escape:'html'|nl2br nofilter}
                </p>
                {/if}
                
                {if $cancel_terms}
                <p style="margin-bottom: 0;">
                    <strong>{__("novoton_holidays.cancellation_policy")|default:"Cancellation Policy"}:</strong><br>
                    {$cancel_terms|escape:'html'|nl2br nofilter}
                </p>
                {/if}
            </div>
            {/if}
        {/if}
    {/foreach}
{/if}
