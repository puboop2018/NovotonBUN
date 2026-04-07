{* 
 * Hook for common:product_info
 * Displays hotel booking details
 *}
{if !empty($product.extra.novoton_booking)}
<div class="ty-novoton-booking-details" style="margin-top: 8px; padding: 10px; background: #f5f9fc; border-radius: 4px; font-size: 12px; border-left: 3px solid #003580;">
    <div style="margin-bottom: 4px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.check_in|default:''|date_format:"%d.%m.%Y"} -> {$product.extra.check_out|default:''|date_format:"%d.%m.%Y"}
        <span style="color: #666;">({$product.extra.nights} nights)</span>
    </div>
    <div style="margin-bottom: 4px;">
        <strong style="color: #003580;"></strong> 
        {$product.extra.room_name|default:$product.extra.room_id|replace:'%2b':'+'|replace:'%2B':'+'}
        &nbsp;|&nbsp;
        <strong style="color: #003580;"></strong> 
        {$product.extra.board_name|default:$product.extra.board_id}
    </div>
    <div>
        <strong style="color: #003580;"></strong> 
        {$product.extra.adults} adults{if $product.extra.children > 0}, {$product.extra.children} children{/if}
        {if $product.extra.holder_name}
            &nbsp;|&nbsp;<strong style="color: #003580;"></strong> {$product.extra.holder_name}
        {/if}
    </div>
</div>
{/if}
