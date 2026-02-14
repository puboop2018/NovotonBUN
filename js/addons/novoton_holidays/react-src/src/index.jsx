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
 */
function readConfig(el) {
    return {
        hotelId:             el.dataset.hotelId       || '',
        productId:           el.dataset.productId     || '',
        mode:                el.dataset.mode           || 'product',
        initialCheckIn:      el.dataset.checkIn       || '',
        initialCheckOut:     el.dataset.checkOut      || '',
        initialAdults:       parseInt(el.dataset.adults)       || 2,
        initialChildren:     parseInt(el.dataset.children)     || 0,
        initialChildrenAges: el.dataset.childrenAges  || '',
        initialRooms:        parseInt(el.dataset.rooms)        || 1,
        maxRooms:            parseInt(el.dataset.maxRooms)     || 12,
        maxAdults:           parseInt(el.dataset.maxAdults)    || 9,
        maxChildren:         parseInt(el.dataset.maxChildren)  || 4,
        buttonText:          el.dataset.buttonText    || '',
        roomsData:           el.dataset.roomsData     || '',
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
