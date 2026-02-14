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
 * URL query parameters take priority for booking fields (check_in, check_out,
 * adults, children, rooms, etc.) so the search-results form always reflects
 * the URL — even when the server-side template populates the data attributes
 * with defaults like "0".
 */
function readConfig(el) {
    const url = new URLSearchParams(window.location.search);

    // Helpers: URL param wins when present, then data attribute, then fallback.
    const str  = (urlKey, dataKey) => url.get(urlKey) || el.dataset[dataKey] || '';
    const num  = (urlKey, dataKey, fallback) => {
        const fromUrl  = url.get(urlKey);
        if (fromUrl !== null && fromUrl !== '') return parseInt(fromUrl, 10);
        const fromData = el.dataset[dataKey];
        if (fromData !== undefined && fromData !== '') return parseInt(fromData, 10);
        return fallback;
    };

    return {
        hotelId:             str('hotel_id',   'hotelId'),
        productId:           str('product_id', 'productId'),
        mode:                el.dataset.mode || 'product',
        initialCheckIn:      str('check_in',       'checkIn'),
        initialCheckOut:     str('check_out',      'checkOut'),
        initialAdults:       num('adults',         'adults',       2),
        initialChildren:     num('children',       'children',     0),
        initialChildrenAges: str('children_ages',  'childrenAges'),
        initialRooms:        num('rooms',          'rooms',        1),
        maxRooms:            parseInt(el.dataset.maxRooms)     || 12,
        maxAdults:           parseInt(el.dataset.maxAdults)    || 9,
        maxChildren:         parseInt(el.dataset.maxChildren)  || 4,
        buttonText:          el.dataset.buttonText || '',
        roomsData:           url.get('rooms_data') || el.dataset.roomsData || '',
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
        loadTranslations(productRoot);
        const config = readConfig(productRoot);
        config.mode = 'product';
        createRoot(productRoot).render(<BookingEngine config={config} />);
    }

    // 2. Search results form
    const searchRoot = document.getElementById('novoton-search-form-root');
    if (searchRoot) {
        loadTranslations(searchRoot);
        const config = readConfig(searchRoot);
        config.mode = 'search';
        createRoot(searchRoot).render(<BookingEngine config={config} />);
    }

    // 3. Homepage search bar
    const homepageRoot = document.getElementById('novoton-homepage-form-root');
    if (homepageRoot) {
        loadTranslations(homepageRoot);
        const config = readConfig(homepageRoot);
        config.mode = 'homepage';
        createRoot(homepageRoot).render(<BookingEngine config={config} />);
    }
}

/**
 * Parse data-translations JSON attribute and merge into
 * window.NovotonTranslations so the t() helper can find them.
 */
function loadTranslations(el) {
    const raw = el.dataset.translations;
    if (!raw) return;
    try {
        const parsed = JSON.parse(raw);
        window.NovotonTranslations = Object.assign(window.NovotonTranslations || {}, parsed);
    } catch (_) { /* ignore malformed JSON */ }
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
