{*
 * Hook: orders:extra_list.post
 * A69: Shows booking terms and conditions after all products in order details
 * Path: design/themes/responsive/templates/addons/novoton_holidays/hooks/orders/extra_list.post.tpl
 *}

{* Collect formatted terms from all Novoton bookings in this order *}
{$all_payment_terms = []}
{$all_cancellation_terms = []}
{$has_novoton_booking = false}

{foreach from=$order_info.products item=product}
    {if $product.extra.novoton_booking}
        {$has_novoton_booking = true}
        
        {* Get formatted payment terms (from PHP hook) *}
        {$pt = $product.extra.terms_of_payment_formatted|default:''}
        {if empty($pt)}
            {* Fallback: try raw terms with strip_tags *}
            {$pt = $product.extra.terms_of_payment|default:$product.extra.payment_terms|default:''}
            {if $pt}
                {$pt = $pt|strip_tags|trim}
            {/if}
        {/if}
        {if $pt && !in_array($pt, $all_payment_terms)}
            {$all_payment_terms[] = $pt}
        {/if}
        
        {* Get formatted cancellation terms (from PHP hook) *}
        {$ct = $product.extra.terms_of_cancellation_formatted|default:''}
        {if empty($ct)}
            {* Fallback: try raw terms with strip_tags *}
            {$ct = $product.extra.terms_of_cancellation|default:$product.extra.cancellation_terms|default:''}
            {if $ct}
                {$ct = $ct|strip_tags|trim}
            {/if}
        {/if}
        {if $ct && !in_array($ct, $all_cancellation_terms)}
            {$all_cancellation_terms[] = $ct}
        {/if}
    {/if}
{/foreach}

{if $has_novoton_booking && ($all_payment_terms|@count > 0 || $all_cancellation_terms|@count > 0)}
<tr>
    <td colspan="{$colspan|default:5}" style="padding: 20px 15px; background: #f9f9f9; border-top: 1px solid #e5e5e5;">
        
        {if $all_payment_terms|@count > 0}
        <div style="margin-bottom: 20px;">
            <strong style="display: block; font-size: 14px; color: #333; margin-bottom: 8px;">{__("novoton_holidays.terms_of_payment")|default:"Conditii de plata"}</strong>
            {foreach from=$all_payment_terms item=terms}
            <div style="color: #555; font-size: 13px; line-height: 1.6; white-space: pre-line;">{$terms}</div>
            {/foreach}
        </div>
        {/if}
        
        {if $all_cancellation_terms|@count > 0}
        <div>
            <strong style="display: block; font-size: 14px; color: #333; margin-bottom: 8px;">{__("novoton_holidays.cancellation_terms")|default:"Conditii de anulare"}</strong>
            {foreach from=$all_cancellation_terms item=terms}
            <div style="color: #555; font-size: 13px; line-height: 1.6; white-space: pre-line;">{$terms}</div>
            {/foreach}
        </div>
        {/if}
        
    </td>
</tr>
{/if}
