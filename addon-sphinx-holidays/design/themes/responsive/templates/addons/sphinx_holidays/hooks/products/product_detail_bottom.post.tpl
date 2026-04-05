{*
 * Hook: products:product_detail_bottom
 * Injects Sphinx booking form after product description on Sphinx hotel product pages
 *}

{if $product.spx.is_sphinx_hotel && $product.spx.show_booking_form && $product.spx.booking_form_position == 'after_description'}
    {include file="addons/sphinx_holidays/blocks/booking_engine.tpl"
        hotel_id=$product.spx.hotel_id
        product_id=$product.spx.product_id
    }
{/if}
