{* Novoton Booking Engine (block version)
 *
 * WARNING: Do NOT place this block on product detail pages — the {include}
 * creates Smarty scope depth that exhausts 256MB memory (Data.php:265).
 * Product pages use the inlined version in product_tabs.pre.tpl instead.
 * This block is safe for homepage, category, and landing pages.
 *}
{style src="css/addons/novoton_holidays/styles.css"}

{include file="addons/travel_core/blocks/booking_engine.tpl"
    travel_provider='novoton'
    travel_search_dispatch='novoton_booking.search'
    current_hotel_id=$hotel_id
    current_product_id=$product_id
    calendar_prices_json=$calendar_prices_json
    calendar_prices_currency=$calendar_prices_currency
    show_calendar_prices=$show_calendar_prices
}
