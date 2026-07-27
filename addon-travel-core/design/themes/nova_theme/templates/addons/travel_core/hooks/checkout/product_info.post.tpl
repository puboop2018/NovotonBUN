{* Booking-details card on cart lines — shared for ALL travel providers
   (gated inside the component on $product.extra.travel_booking). Replaced
   the per-provider hook copies novoton used to carry. *}
{include file="addons/travel_core/components/cart_booking_details.tpl" product=$product key=$key}
