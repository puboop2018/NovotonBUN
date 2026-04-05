{*
 * Hook: products:product_tabs
 * Injects Sphinx booking form before product tabs on Sphinx hotel product pages
 *}

{if $product.spx.is_sphinx_hotel && $product.spx.show_booking_form && $product.spx.booking_form_position == 'before_tabs'}
    {include file="addons/sphinx_holidays/blocks/booking_engine.tpl"
        hotel_id=$product.spx.hotel_id
        product_id=$product.spx.product_id
    }
{/if}
