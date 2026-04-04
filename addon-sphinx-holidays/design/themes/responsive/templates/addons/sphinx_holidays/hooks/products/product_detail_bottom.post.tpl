{*
 * Hook: products:product_detail_bottom
 * Injects Sphinx booking form after product description on Sphinx hotel product pages
 *}

{if $is_sphinx_hotel && $show_sphinx_booking_form}
    {if $sphinx_booking_form_position == 'after_description'}
        {include file="addons/sphinx_holidays/blocks/booking_engine.tpl"
            hotel_id=$sphinx_hotel_id
            product_id=$product_id
        }
    {/if}
{/if}
