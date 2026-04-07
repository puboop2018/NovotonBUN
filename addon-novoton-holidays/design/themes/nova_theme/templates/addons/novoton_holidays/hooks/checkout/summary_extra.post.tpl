{* 
 * Hook: checkout:summary_extra
 * Alternative hook for checkout sidebar summary
 *}

{if $cart.products}
    {foreach from=$cart.products item=product key=key}
        {if !empty($product.extra.novoton_booking)}
        <div style="background: #f0f7ff; border: 1px solid #003580; border-radius: 6px; padding: 10px; margin: 10px 0; font-size: 11px;">
            <div style="font-weight: 600; color: #003580; margin-bottom: 6px;">
                 {$product.product|default:'Booking'}
            </div>
            <div style="font-size: 10px; color: #555;">
                 {$product.extra.check_in|default:''|date_format:"%d.%m"} - {$product.extra.check_out|default:''|date_format:"%d.%m.%Y"}<br>
                 {$product.extra.room_name|default:$product.extra.room_id|truncate:25}<br>
                 {$product.extra.adults} ad.{if $product.extra.children}, {$product.extra.children} ch.{/if}
            </div>
        </div>
        {/if}
    {/foreach}
{/if}
