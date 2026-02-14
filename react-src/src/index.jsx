/**
 * Novoton Booking Engine - Entry point
 *
 * Auto-initializes React roots on three possible DOM mount-points and
 * exposes the public API on window.NovotonBooking.
 */

import { createRoot } from 'react-dom/client';
import BookingEngine from './BookingEngine';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { getLocale, parseDate, toDateString } from './utils';

/**
 * Read data-* attributes from an element and build a config object.
 * Falls back to URL query parameters for booking fields so that the
 * search-results form picks up check_in, check_out, etc. from the URL
 * even when the server-side template doesn't populate the data attributes.
 */
function readConfig(el) {
    const url = new URLSearchParams(window.location.search);

    return {
        hotelId:             el.dataset.hotelId       || url.get('hotel_id')   || '',
        productId:           el.dataset.productId     || url.get('product_id') || '',
        mode:                el.dataset.mode           || 'product',
        initialCheckIn:      el.dataset.checkIn       || url.get('check_in')   || '',
        initialCheckOut:     el.dataset.checkOut      || url.get('check_out')  || '',
        initialAdults:       parseInt(el.dataset.adults   || url.get('adults'))    || 2,
        initialChildren:     parseInt(el.dataset.children || url.get('children'))  || 0,
        initialChildrenAges: el.dataset.childrenAges  || url.get('children_ages') || '',
        initialRooms:        parseInt(el.dataset.rooms    || url.get('rooms'))     || 1,
        maxRooms:            parseInt(el.dataset.maxRooms)     || 12,
        maxAdults:           parseInt(el.dataset.maxAdults)    || 9,
        maxChildren:         parseInt(el.dataset.maxChildren)  || 4,
        buttonText:          el.dataset.buttonText    || '',
        roomsData:           el.dataset.roomsData     || url.get('rooms_data') || '',
    };
}

/**
 * Main initialisation function.
 * Looks for three possible root elements and mounts the BookingEngine.
 */
function init() {
    // 1. Product page booking widget
    const productRoot = document.getElementById('novoton-booking-root');
    if (productRoot) {
        const config = readConfig(productRoot);
        config.mode = 'product';
        createRoot(productRoot).render(<BookingEngine config={config} />);
    }

    // 2. Search results form
    const searchRoot = document.getElementById('novoton-search-form-root');
    if (searchRoot) {
        const config = readConfig(searchRoot);
        config.mode = 'search';
        createRoot(searchRoot).render(<BookingEngine config={config} />);
    }

    // 3. Homepage search bar
    const homepageRoot = document.getElementById('novoton-homepage-form-root');
    if (homepageRoot) {
        const config = readConfig(homepageRoot);
        config.mode = 'homepage';
        createRoot(homepageRoot).render(<BookingEngine config={config} />);
    }
}

// ---------------------------------------------------------------------------
// Auto-initialize on DOM ready
// ---------------------------------------------------------------------------

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

window.NovotonBooking = {
    init,
    BookingEngine,
    Calendar,
    GuestPicker,
    getLocale,
    parseDate,
    toDateString,
};
