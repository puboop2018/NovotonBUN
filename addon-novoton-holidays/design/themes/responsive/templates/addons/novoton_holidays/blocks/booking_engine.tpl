{* Novoton Booking Engine - Thin wrapper around shared travel_core template
 * Provider-specific: sets $travel_provider and $travel_search_dispatch
 *}
{style src="css/addons/novoton_holidays/styles.css"}

{$travel_provider = 'novoton'}
{$travel_search_dispatch = 'novoton_booking.search'}

{include file="addons/travel_core/blocks/booking_engine.tpl"}
