{*
 * Hook: products:product_detail_bottom
 * Injects booking form after product description on hotel product pages
 *}

{nvt_data product_id=$product.product_id assign="_nvt"}
{if $_nvt.is_hotel_product && $_nvt.show_booking_form && $_nvt.booking_form_position == 'after_description'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl"
        hotel_id=$_nvt.hotel_id
        product_id=$_nvt.product_id
        calendar_prices_json=$_nvt.calendar_prices_json
        calendar_prices_currency=$_nvt.calendar_prices_currency
        show_calendar_prices=$_nvt.show_calendar_prices
    }
{/if}
