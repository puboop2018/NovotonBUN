{*
 * Hook: products:product_tabs
 * Injects booking form before product tabs on hotel product pages
 *}

{$_nvt = $product.product_id|nvt_hotel_tab_data}
{if $_nvt.is_hotel_product && $_nvt.show_booking_form && $_nvt.booking_form_position == 'before_tabs'}
    {include file="addons/novoton_holidays/blocks/booking_engine.tpl"
        hotel_id=$_nvt.hotel_id
        product_id=$_nvt.product_id
        calendar_prices_json=$_nvt.calendar_prices_json
        calendar_prices_currency=$_nvt.calendar_prices_currency
        show_calendar_prices=$_nvt.show_calendar_prices
    }
{/if}
