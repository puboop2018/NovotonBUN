{*
 * Hook: products:product_detail_bottom
 * Injects booking form after product description on hotel product pages
 *}

{if $product.nvt.is_hotel_product && $product.nvt.show_booking_form && $product.nvt.booking_form_position == 'after_description'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl"
        hotel_id=$product.nvt.hotel_id
        product_id=$product.nvt.product_id
        calendar_prices_json=$product.nvt.calendar_prices_json
        calendar_prices_currency=$product.nvt.calendar_prices_currency
        show_calendar_prices=$product.nvt.show_calendar_prices
    }
{/if}
