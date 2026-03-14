/**
 * Travel Booking Engine - Entry point
 *
 * Auto-initializes React roots on mount-points found via
 * [data-travel-booking] attribute and exposes the public API
 * on window.TravelBooking.
 *
 * Provider addons (novoton, sphinx) set data-search-dispatch on
 * the mount element to control which controller handles the search.
 */

import { createRoot } from 'react-dom/client';
import { Component } from 'react';
import BookingEngine from './BookingEngine';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { getLocale, parseDate, toDateString, t } from './utils';

class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    render() {
        if (this.state.hasError) {
            return (
                <div className="nvt-error-boundary">
                    {t('componentError', 'Something went wrong. Please reload the page.')}
                </div>
            );
        }
        return this.props.children;
    }
}

/**
 * Read data-* attributes from an element and build a config object.
 * URL query parameters take priority for booking fields (check_in, check_out,
 * adults, children, rooms, etc.) so the search-results form always reflects
 * the URL.
 */
function readConfig(el) {
    const url = new URLSearchParams(window.location.search);

    const str  = (urlKey, dataKey) => url.get(urlKey) || el.dataset[dataKey] || '';
    const num  = (urlKey, dataKey, fallback) => {
        const fromUrl  = url.get(urlKey);
        if (fromUrl !== null && fromUrl !== '') return parseInt(fromUrl, 10);
        const fromData = el.dataset[dataKey];
        if (fromData !== undefined && fromData !== '') return parseInt(fromData, 10);
        return fallback;
    };

    // Calendar prices: JSON map from data-calendar-prices attribute
    let calendarPrices = null;
    let calendarPricesCurrency = '';
    if (el.dataset.calendarPrices) {
        try {
            calendarPrices = JSON.parse(el.dataset.calendarPrices);
            calendarPricesCurrency = el.dataset.calendarPricesCurrency || '';
        } catch (_) { /* ignore malformed JSON */ }
    }

    return {
        hotelId:             str('hotel_id',   'hotelId'),
        productId:           str('product_id', 'productId'),
        mode:                el.dataset.mode || 'product',
        searchDispatch:      el.dataset.searchDispatch || '',
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
        calendarPrices,
        calendarPricesCurrency,
    };
}

/**
 * Main initialisation function.
 * Looks for mount points by [data-travel-booking] attribute or legacy IDs.
 */
function init() {
    // Modern: find all [data-travel-booking] elements
    const mountPoints = document.querySelectorAll('[data-travel-booking]');
    if (mountPoints.length > 0) {
        mountPoints.forEach(el => {
            loadTranslations(el);
            const config = readConfig(el);
            if (!config.mode) config.mode = el.dataset.travelBooking || 'product';
            createRoot(el).render(<ErrorBoundary><BookingEngine config={config} /></ErrorBoundary>);
        });
        return;
    }

    // Legacy: look for specific element IDs (backwards compatibility with novoton)
    const legacyMounts = [
        { id: 'novoton-booking-root',       mode: 'product' },
        { id: 'novoton-search-form-root',   mode: 'search' },
        { id: 'novoton-homepage-form-root', mode: 'homepage' },
        { id: 'travel-booking-root',        mode: 'product' },
        { id: 'travel-search-form-root',    mode: 'search' },
        { id: 'travel-homepage-form-root',  mode: 'homepage' },
    ];

    for (const { id, mode } of legacyMounts) {
        const el = document.getElementById(id);
        if (el) {
            loadTranslations(el);
            const config = readConfig(el);
            config.mode = mode;
            createRoot(el).render(<ErrorBoundary><BookingEngine config={config} /></ErrorBoundary>);
        }
    }
}

/**
 * Parse data-translations JSON attribute and merge into
 * window.TravelTranslations so the t() helper can find them.
 */
function loadTranslations(el) {
    const raw = el.dataset.translations;
    if (!raw) return;
    try {
        const parsed = JSON.parse(raw);
        window.TravelTranslations = Object.assign(window.TravelTranslations || {}, parsed);
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

window.TravelBooking = {
    init,
    BookingEngine,
    Calendar,
    GuestPicker,
    getLocale,
    parseDate,
    toDateString,
};

// Backwards compatibility alias
window.NovotonBooking = window.TravelBooking;
