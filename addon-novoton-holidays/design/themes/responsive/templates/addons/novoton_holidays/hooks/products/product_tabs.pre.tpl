{*
 * Hook: products:product_tabs
 * Injects booking form before product tabs on hotel product pages
 *}

{if $is_hotel_product && $show_novoton_booking_form && $novoton_booking_form_position == 'before_tabs'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl" 
        hotel_id=$hotel_id 
        product_id=$product_id
    }
{/if}
