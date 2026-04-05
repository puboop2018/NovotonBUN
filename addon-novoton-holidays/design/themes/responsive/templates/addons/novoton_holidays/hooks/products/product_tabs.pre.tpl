{*
 * Hook: products:product_tabs
 * Injects booking form before product tabs on Novoton hotel product pages.
 * Detects hotel products by checking product_code starts with "NVT".
 * Uses ONLY built-in Smarty syntax — no custom plugins needed.
 *}

{if $product.product_code|substr:0:3 == 'NVT'}
    {$_nvt_hotel_id = $product.product_code|substr:3}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl"
        hotel_id=$_nvt_hotel_id
        product_id=$product.product_id
    }
{/if}
