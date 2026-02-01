{*
 * Hook: products:main_info_description
 * Injects booking form after product description on hotel product pages
 *}

{if $is_hotel_product && $show_novoton_booking_form && $novoton_booking_form_position == 'after_description'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl" 
        hotel_id=$hotel_id 
        product_id=$product.product_id
    }
{/if}
