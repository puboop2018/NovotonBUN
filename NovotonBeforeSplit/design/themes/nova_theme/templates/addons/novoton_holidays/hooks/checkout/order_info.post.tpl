{* 
 * Hook for checkout:order_info
 * Shows booking details in order summary (right sidebar)
 *}

{if $cart.products}
    {foreach from=$cart.products item=product key=key}
        {if !empty($product.extra.novoton_booking)}
        <div style="background: #f0f7ff; border: 1px solid #003580; border-radius: 6px; padding: 10px; margin: 10px 0; font-size: 12px;">
            <div style="font-weight: 600; color: #003580; margin-bottom: 8px; border-bottom: 1px solid #cce0ff; padding-bottom: 5px;">
                 {$product.product|default:'Hotel Booking'}
            </div>
            
            <div style="margin-bottom: 4px;">
                <strong></strong> {$product.extra.check_in|date_format:"%d.%m.%Y"} - {$product.extra.check_out|date_format:"%d.%m.%Y"}
                <span style="color: #666;">({$product.extra.nights} nights)</span>
            </div>
            
            {if $product.extra.num_rooms > 1 && $product.extra.rooms_data}
                <div style="margin: 8px 0;">
                    <strong> {$product.extra.num_rooms} rooms:</strong>
                </div>
                {foreach from=$product.extra.rooms_data item=room_info key=room_idx}
                    <div style="margin-left: 10px; padding: 5px; background: #fff; border-radius: 4px; margin-bottom: 4px; font-size: 11px;">
                        <strong>R{$room_idx+1}:</strong> {$room_info.room_name|default:$room_info.room_id}
                        {if $room_info.board_name} - {$room_info.board_name}{/if}<br>
                        <span style="color: #666;">
                             {$room_info.adults|default:2} ad.{if $room_info.children}, {$room_info.children} ch.{/if}
                            {if $room_info.price} - {$room_info.price|number_format:0}{$smarty.const.CART_PRIMARY_CURRENCY}{/if}
                        </span>
                    </div>
                {/foreach}
            {else}
                <div style="margin-bottom: 4px;">
                    <strong></strong> {$product.extra.room_name|default:$product.extra.room_id}
                </div>
                <div style="margin-bottom: 4px;">
                    <strong></strong> {$product.extra.board_name|default:$product.extra.board_id}
                </div>
                <div>
                    <strong></strong> {$product.extra.adults} adults{if $product.extra.children}, {$product.extra.children} children{/if}
                </div>
            {/if}
            
            {if $product.extra.holder_name}
            <div style="margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cce0ff;">
                <strong></strong> {$product.extra.holder_name}
            </div>
            {/if}
        </div>
        {/if}
    {/foreach}
{/if}
