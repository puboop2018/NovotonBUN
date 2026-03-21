{*
 * Hook: products:product_tabs
 * Injects Sphinx booking form before product tabs on Sphinx hotel product pages
 *}

{if $is_sphinx_hotel && $show_sphinx_booking_form && $sphinx_booking_form_position == 'before_tabs'}
    {include file="addons/sphinx_holidays/blocks/booking_engine.tpl"
        hotel_id=$sphinx_hotel_id
        product_id=$product.product_id
    }
{/if}
