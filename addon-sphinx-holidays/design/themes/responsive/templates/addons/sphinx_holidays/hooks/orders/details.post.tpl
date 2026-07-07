{* Sphinx Holidays — Order Details Hook: Payment & Cancellation terms.
   Hook: orders:details — fires after the products table.
   Terms travel in each cart item's extras (captured from the verified offer
   at add_to_cart); grouped per hotel so multi-item orders show them once. *}

{$_sx_terms_by_hotel = []}
{if $order_info.products}
    {foreach from=$order_info.products item=_sx_product}
        {if !empty($_sx_product.extra.sphinx_booking)}
            {$_sx_hotel = $_sx_product.extra.hotel_id|default:$_sx_product.product_id}
            {if !isset($_sx_terms_by_hotel[$_sx_hotel]) && ($_sx_product.extra.payment_terms || $_sx_product.extra.cancellation_fees)}
                {$_sx_terms_by_hotel[$_sx_hotel] = [
                    'name' => $_sx_product.extra.hotel_name|default:$_sx_product.product,
                    'payment' => $_sx_product.extra.payment_terms|default:[],
                    'cancellation' => $_sx_product.extra.cancellation_fees|default:[]
                ]}
            {/if}
        {/if}
    {/foreach}
{/if}

{if $_sx_terms_by_hotel}
<div class="sphinx-order-terms" style="margin-top: 20px; padding: 15px; background: #fffdf5; border: 1px solid #f0e6c8; border-radius: 8px; font-size: 13px;">
    <h4 style="margin: 0 0 10px;">{__("sphinx_holidays.terms_heading")|default:"Payment & cancellation terms"}</h4>
    {foreach $_sx_terms_by_hotel as $_sx_entry}
    <div style="margin-bottom: 12px;">
        <strong>{$_sx_entry.name|escape:html}</strong>
        {if $_sx_entry.payment}
        <div style="margin-top: 6px;">
            {__("sphinx_holidays.payment_terms")|default:"Payment terms"}:
            <ul style="margin: 4px 0 0 18px; padding: 0;">
                {foreach $_sx_entry.payment as $_sx_line}<li>{$_sx_line|escape:html}</li>{/foreach}
            </ul>
        </div>
        {/if}
        {if $_sx_entry.cancellation}
        <div style="margin-top: 6px;">
            {__("sphinx_holidays.cancellation_policy")|default:"Cancellation policy"}:
            <ul style="margin: 4px 0 0 18px; padding: 0;">
                {foreach $_sx_entry.cancellation as $_sx_line}<li>{$_sx_line|escape:html}</li>{/foreach}
            </ul>
        </div>
        {/if}
    </div>
    {/foreach}
</div>
{/if}
