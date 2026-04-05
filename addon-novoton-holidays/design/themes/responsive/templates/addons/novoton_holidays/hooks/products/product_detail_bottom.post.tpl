{*
 * Hook: products:product_detail_bottom
 * Injects booking form after product description on hotel product pages
 *}

{if $is_hotel_product && $show_novoton_booking_form && $novoton_booking_form_position == 'after_description'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl"
        hotel_id=$hotel_id
        product_id=$product_id
        calendar_prices_json=$calendar_prices_json
        calendar_prices_currency=$calendar_prices_currency
        show_calendar_prices=$show_calendar_prices
    }
{/if}
