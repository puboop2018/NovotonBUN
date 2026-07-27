/**
 * Shared cart booking-details card (components/cart_booking_details.tpl):
 * multi-room collapse toggle. Extracted from novoton's inline hook script
 * when the card moved to travel_core — window global because the card's
 * room headers invoke it from onclick attributes.
 */
window.travelToggleRoomCard = function (id) {
    var content = document.getElementById(id);
    var icon = document.getElementById(id + '_icon');
    if (!content) return;
    var svg = icon ? icon.querySelector('svg') : null;
    if (content.style.display === 'none') {
        content.style.display = 'block';
        if (svg) svg.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        if (svg) svg.style.transform = 'rotate(0deg)';
    }
};
