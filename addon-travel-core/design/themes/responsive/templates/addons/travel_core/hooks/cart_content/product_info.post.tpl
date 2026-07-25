{* Booking-details card on CART-page lines — the same shared component the
   checkout hooks render (gated inside on $product.extra.travel_booking,
   which both providers set). Replaced the legacy inline-styled card that
   used to live here AND novoton's per-provider twin: with both files
   present, every novoton cart line rendered two stacked cards. *}
{include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key}
