{* Sphinx Booking Engine - Thin wrapper around shared travel_core template
 *
 * IMPORTANT: Pass ALL variables explicitly to the include to avoid Smarty
 * scope chain traversal, which can cause Data::getVariable() stack overflow
 * on product detail pages (see zend.max_allowed_stack_size crash).
 *}

{include file="addons/travel_core/blocks/booking_engine.tpl"
    travel_provider='sphinx'
    travel_search_dispatch='sphinx_booking.search'
    current_hotel_id=$hotel_id
    current_product_id=$product_id
    calendar_prices_json=$calendar_prices_json
    calendar_prices_currency=$calendar_prices_currency
    show_calendar_prices=$show_calendar_prices
}
