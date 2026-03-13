/**
 * Novoton Booking Engine - Main component
 *
 * Renders a Booking.com-style search/booking form with:
 *   - Date range selector (Calendar popup)
 *   - Guest / room picker (GuestPicker popup)
 *   - Search / Book button
 *
 * Three display modes:
 *   "product"  – on a hotel product page (shows Availability header)
 *   "search"   – on search results (shows "Change search" option)
 *   "homepage" – on the homepage (includes a location search input)
 */

import { useState, useCallback, useRef, useEffect, useMemo } from 'react';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { CalendarIcon, GuestIcon, ChevronDown, RefreshIcon } from './icons';
import { parseDate, toDateString, formatDateShort, nightsBetween, t, tPlural } from './utils';
import { injectStyles } from './styles';

export default function BookingEngine({ config }) {
    // Inject CSS once on first render
    useEffect(() => { injectStyles(); }, []);

    const {
        hotelId = '',
        productId = '',
        mode = 'product',
        initialCheckIn = '',
        initialCheckOut = '',
        initialAdults = 2,
        initialChildren = 0,
        initialChildrenAges = '',
        initialRooms = 1,
        maxRooms = 12,
        maxAdults = 9,
        maxChildren = 4,
        buttonText = '',
        roomsData = '',
        calendarPrices: configCalendarPrices = null,
        calendarPricesCurrency: configCalendarPricesCurrency = '',
    } = config;

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    const [checkIn, setCheckIn] = useState(() => parseDate(initialCheckIn));
    const [checkOut, setCheckOut] = useState(() => parseDate(initialCheckOut));

    // Build initial rooms array
    const [rooms, setRooms] = useState(() => {
        // Try to parse roomsData first
        if (roomsData) {
            try {
                const parsed = JSON.parse(roomsData);
                if (Array.isArray(parsed) && parsed.length > 0) return parsed;
            } catch (_) { /* fall through */ }
        }

        // Build from initial values
        const ages = initialChildrenAges
            ? initialChildrenAges.split(',').map(a => parseInt(a.trim()))
            : [];
        const roomArr = [];
        const roomCount = Math.max(1, initialRooms);

        for (let i = 0; i < roomCount; i++) {
            if (i === 0) {
                roomArr.push({
                    adults: initialAdults,
                    children: initialChildren,
                    childrenAges: ages.length > 0 ? ages : Array(initialChildren).fill(null),
                });
            } else {
                roomArr.push({ adults: 2, children: 0, childrenAges: [] });
            }
        }
        return roomArr;
    });

    // Calendar prices: per-date approximate totals for cheapest room
    // Source 1: config prop (from data-calendar-prices on product page)
    // Source 2: window.bookingData (from booking form page template)
    const calendarPrices = useMemo(() => {
        // Prefer config (data-* attribute on product page)
        if (configCalendarPrices && typeof configCalendarPrices === 'object' && Object.keys(configCalendarPrices).length > 0) {
            return {
                prices: configCalendarPrices,
                currency: configCalendarPricesCurrency || ''
            };
        }
        // Fallback: window.bookingData (booking form page)
        if (typeof window !== 'undefined' && window.bookingData && window.bookingData.showCalendarPrices) {
            return {
                prices: window.bookingData.calendarPrices || {},
                currency: window.bookingData.calendarPricesCurrency || ''
            };
        }
        return { prices: {}, currency: '' };
    }, [configCalendarPrices, configCalendarPricesCurrency]);

    const [showCalendar, setShowCalendar] = useState(false);
    const [showGuests, setShowGuests] = useState(false);
    const [validationError, setValidationError] = useState('');
    const [ageErrors, setAgeErrors] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearching, setIsSearching] = useState(false);
    const [fetchError, setFetchError] = useState('');

    // Button state: "Search" → "Change search" → "Apply changes"
    // In search mode, user already searched so start with hasSearched=true.
    // On product page with pre-filled dates, also start as hasSearched.
    const [hasSearched, setHasSearched] = useState(
        mode === 'search' || (!!initialCheckIn && !!initialCheckOut)
    );
    const [paramsChanged, setParamsChanged] = useState(false);

    const engineRef = useRef(null);
    const retryTimerRef = useRef(null);
    const dateTriggerRef = useRef(null);
    const guestTriggerRef = useRef(null);

    // Clean up any pending retry timer on unmount
    useEffect(() => () => { clearTimeout(retryTimerRef.current); }, []);

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    const totalAdults = rooms.reduce((sum, r) => sum + r.adults, 0);
    const totalChildren = rooms.reduce((sum, r) => sum + r.children, 0);
    const nights = nightsBetween(checkIn, checkOut);

    // Build guest summary text (always lowercase)
    const guestSummary = (() => {
        const parts = [];

        const adultLabel = (totalAdults === 1 ? t('adult', 'adult') : t('adults', 'adults')).toLowerCase();
        parts.push(`${totalAdults} ${adultLabel}`);

        const childLabel = (totalChildren === 1 ? t('child', 'child') : t('children', 'children')).toLowerCase();
        parts.push(`${totalChildren} ${childLabel}`);

        const roomLabel = (rooms.length === 1 ? t('room', 'room') : t('rooms', 'rooms')).toLowerCase();
        parts.push(`${rooms.length} ${roomLabel}`);

        return parts.join(' \u00b7 ');
    })();

    // Date display text – e.g. "Mon. 14 Feb. → Mon. 21 Feb. (7 nights)"
    const dateDisplayText = (() => {
        if (checkIn && checkOut) {
            const nightLabel = tPlural(nights, 'night', 'nights', 'nightsMany', 'night', 'nights', 'nights');
            return `${formatDateShort(checkIn)} \u2192 ${formatDateShort(checkOut)} (${nights} ${nightLabel})`;
        }
        if (checkIn) {
            return `${formatDateShort(checkIn)} \u2192 ...`;
        }
        return '';
    })();

    // -----------------------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------------------

    const handleDateSelect = useCallback((newCheckIn, newCheckOut) => {
        setCheckIn(newCheckIn);
        setCheckOut(newCheckOut);
        setValidationError('');
        if (hasSearched) setParamsChanged(true);
    }, [hasSearched]);

    const handleRoomsUpdate = useCallback((newRooms) => {
        setRooms(newRooms);
        // Recalculate age errors: keep red border on children still missing an age
        setAgeErrors(prev => {
            if (prev.length === 0) return prev;
            const remaining = prev.filter(err => {
                const room = newRooms[err.room];
                if (!room || err.child >= room.children) return false;
                const age = (room.childrenAges || [])[err.child];
                return age === null || age === undefined || age === '';
            });
            return remaining.length === prev.length ? prev : remaining;
        });
        if (hasSearched) setParamsChanged(true);
    }, [hasSearched]);

    const buildSearchUrl = useCallback(() => {
        const base = window.location.origin + '/index.php';
        const params = new URLSearchParams();

        if (mode === 'homepage') {
            params.set('dispatch', 'products.search');
            params.set('q', searchQuery);
        } else {
            params.set('dispatch', 'novoton_booking.search');
            if (hotelId) params.set('hotel_id', hotelId);
            if (productId) params.set('product_id', productId);
        }

        params.set('check_in', toDateString(checkIn));
        params.set('check_out', toDateString(checkOut));
        params.set('adults', totalAdults);
        params.set('children', totalChildren);
        params.set('rooms', rooms.length);
        params.set('rooms_data', JSON.stringify(rooms));

        // Collect all child ages
        const allAges = [];
        rooms.forEach(room => {
            (room.childrenAges || []).forEach(age => {
                if (age !== null && age !== undefined) allAges.push(age);
            });
        });
        if (allAges.length > 0) {
            params.set('children_ages', allAges.join(','));
        }

        return base + '?' + params.toString();
    }, [checkIn, checkOut, rooms, mode, hotelId, productId, searchQuery, totalAdults, totalChildren]);

    const performAjaxSearch = useCallback((url) => {
        setIsSearching(true);
        setFetchError('');
        setShowCalendar(false);
        setShowGuests(false);

        const fetchUrl = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
        const maxRetries = 2;

        const attemptFetch = (attempt) => {
            fetch(fetchUrl)
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP ${r.status}`);
                    return r.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    const newPage = doc.querySelector('.novoton-search-results-page');
                    const curPage = document.querySelector('.novoton-search-results-page');

                    if (!newPage || !curPage) {
                        window.location.href = fetchUrl;
                        return;
                    }

                    const curForm = curPage.querySelector('.novoton-search-form-wrapper');
                    const newForm = newPage.querySelector('.novoton-search-form-wrapper');

                    if (!curForm || !newForm) {
                        window.location.href = fetchUrl;
                        return;
                    }

                    // Remove current results (everything after form wrapper)
                    while (curForm.nextSibling) {
                        curForm.nextSibling.remove();
                    }

                    // Append new results from fetched page
                    const fragment = document.createDocumentFragment();
                    let node = newForm.nextSibling;
                    while (node) {
                        fragment.appendChild(document.importNode(node, true));
                        node = node.nextSibling;
                    }
                    curPage.appendChild(fragment);

                    // Re-execute scripts from the fetched same-origin page.
                    // Block cross-origin external scripts to limit XSS surface.
                    curPage.querySelectorAll('script').forEach(oldScript => {
                        if (oldScript.closest('.novoton-search-form-wrapper')) return;

                        // Block cross-origin external scripts
                        if (oldScript.src) {
                            try {
                                const scriptUrl = new URL(oldScript.src, window.location.origin);
                                if (scriptUrl.origin !== window.location.origin) {
                                    oldScript.remove();
                                    return;
                                }
                            } catch {
                                oldScript.remove();
                                return;
                            }
                            // Skip scripts already loaded (React, validation)
                            if (oldScript.src.includes('react19') || oldScript.src.includes('dob-validation')) return;
                        }

                        // Re-execute both inline and same-origin external scripts
                        const newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(attr => {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.textContent = oldScript.textContent;
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    // Scroll to results area
                    const resultsTop = curForm.getBoundingClientRect().bottom + window.pageYOffset - 20;
                    window.scrollTo({ top: resultsTop, behavior: 'smooth' });

                    // Update browser URL without page reload
                    window.history.pushState({}, '', url);

                    // Reset button state
                    setHasSearched(true);
                    setParamsChanged(false);
                    setIsSearching(false);
                })
                .catch(() => {
                    if (attempt < maxRetries) {
                        retryTimerRef.current = setTimeout(() => attemptFetch(attempt + 1), 1000 * Math.pow(2, attempt));
                    } else {
                        setIsSearching(false);
                        setFetchError(t('searchFailed', 'Search failed. Please try again.'));
                    }
                });
        };

        attemptFetch(0);
    }, []);

    const handleSearch = useCallback(() => {
        setFetchError('');
        // Validate dates — show error tooltip, don't auto-open calendar
        if (!checkIn || !checkOut) {
            setValidationError(t('pleaseEnterDates', 'Please select check-in and check-out dates'));
            setShowCalendar(false);
            setShowGuests(false);
            return;
        }

        // Validate child ages — open guest picker so user can fix
        const errors = [];
        rooms.forEach((room, roomIdx) => {
            if (room.children > 0) {
                (room.childrenAges || []).forEach((age, childIdx) => {
                    if (age === null || age === undefined || age === '') {
                        errors.push({ room: roomIdx, child: childIdx });
                    }
                });
            }
        });

        if (errors.length > 0) {
            setAgeErrors(errors);
            setShowGuests(true);
            setShowCalendar(false);
            return;
        }

        setValidationError('');

        const url = buildSearchUrl();

        // In search mode, use AJAX to update results without page reload
        if (mode === 'search') {
            performAjaxSearch(url);
            return;
        }

        window.location.href = url + '&_t=' + Date.now();
    }, [checkIn, checkOut, rooms, mode, buildSearchUrl, performAjaxSearch]);

    // Button click handler: always perform search regardless of state
    const handleButtonClick = useCallback(() => {
        if (isSearching) return;
        handleSearch();
    }, [handleSearch, isSearching]);

    // -----------------------------------------------------------------------
    // Render helpers
    // -----------------------------------------------------------------------

    const renderAvailabilityHeader = () => {
        if (mode === 'search') return null;

        return (
            <div className="nvt-availability-header">
                <h2 className="nvt-availability-title">
                    {t('availability', 'Availability')}
                </h2>
                {!checkIn && (
                    <p className="nvt-availability-subtitle">
                        {t('selectDatesMessage', 'Select dates to see this property\'s availability and prices')}
                    </p>
                )}
            </div>
        );
    };

    const renderSearchHeader = () => {
        if (mode !== 'search') return null;

        return (
            <div className="nvt-availability-header">
                <h2 className="nvt-availability-title" style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <RefreshIcon />
                    {t('changeSearch', 'Change search')}
                </h2>
            </div>
        );
    };

    // -----------------------------------------------------------------------
    // Main render
    // -----------------------------------------------------------------------

    // Button text state machine:
    // 1. Default: "Search"
    // 2. After search: "Change search"
    // 3. If dates/guests changed after search: "Apply changes"
    // 4. After clicking "Apply changes": AJAX updates results, button returns to "Change search"
    const searchBtnText = (() => {
        if (isSearching) return t('searching', 'Searching...');
        if (buttonText) return buttonText;
        if (hasSearched && paramsChanged) return t('applyChanges', 'Apply changes');
        if (hasSearched) return t('changeSearch', 'Change search');
        return t('search', 'Search');
    })();

    return (
        <div className="nvt-booking-engine" ref={engineRef}>
            {renderAvailabilityHeader()}
            {renderSearchHeader()}

            <div className="nvt-form-row">
                {/* Homepage location input */}
                {mode === 'homepage' && (
                    <div className="nvt-field nvt-field--location">
                        <div className="nvt-field-input nvt-field-input--location">
                            <input
                                type="text"
                                className="nvt-homepage-input"
                                value={searchQuery}
                                onChange={e => setSearchQuery(e.target.value)}
                                placeholder={t('whereAreYouGoing', 'Where are you going?')}
                            />
                        </div>
                    </div>
                )}

                {/* Date field */}
                <div className={`nvt-field nvt-field--date${validationError ? ' nvt-field--error' : ''}`}>
                    <button
                        ref={dateTriggerRef}
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowCalendar(!showCalendar); setShowGuests(false); setValidationError(''); }}
                        aria-expanded={showCalendar}
                        aria-haspopup="dialog"
                    >
                        <span className="nvt-field-input-icon"><CalendarIcon /></span>
                        <span className="nvt-field-input-text">
                            {dateDisplayText ? (
                                <span className="nvt-value">{dateDisplayText}</span>
                            ) : (
                                <span className="nvt-value nvt-value--placeholder">
                                    {`${t('checkIn', 'Check-in')} \u2192 ${t('checkOut', 'Check-out')}`}
                                </span>
                            )}
                        </span>
                        <span className="nvt-field-input-arrow"><ChevronDown /></span>
                    </button>

                    {showCalendar && (
                        <Calendar
                            checkIn={checkIn}
                            checkOut={checkOut}
                            onSelect={handleDateSelect}
                            onClose={() => setShowCalendar(false)}
                            prices={calendarPrices.prices}
                            pricesCurrency={calendarPrices.currency}
                            triggerRef={dateTriggerRef}
                        />
                    )}

                    {/* Date validation message — only visible when calendar is closed */}
                    {validationError && !showCalendar && (
                        <div className="nvt-validation-message">
                            {validationError}
                        </div>
                    )}
                </div>

                {/* Guests field */}
                <div className="nvt-field nvt-field--guests">
                    <button
                        ref={guestTriggerRef}
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowGuests(!showGuests); setShowCalendar(false); }}
                        aria-expanded={showGuests}
                        aria-haspopup="dialog"
                    >
                        <span className="nvt-field-input-icon"><GuestIcon /></span>
                        <span className="nvt-field-input-text">
                            <span className="nvt-value">{guestSummary}</span>
                        </span>
                        <span className="nvt-field-input-arrow"><ChevronDown /></span>
                    </button>

                    {showGuests && (
                        <GuestPicker
                            rooms={rooms}
                            maxRooms={maxRooms}
                            maxAdults={maxAdults}
                            maxChildren={maxChildren}
                            onUpdate={handleRoomsUpdate}
                            onClose={() => setShowGuests(false)}
                            ageErrors={ageErrors}
                            triggerRef={guestTriggerRef}
                        />
                    )}
                </div>

                {/* Search button */}
                <div className="nvt-field nvt-field--btn">
                    <button
                        type="button"
                        className="nvt-btn-search"
                        onClick={handleButtonClick}
                        disabled={isSearching}
                        style={isSearching ? { opacity: 0.7, cursor: 'wait' } : undefined}
                    >
                        {searchBtnText}
                    </button>
                </div>
            </div>

            {/* Fetch error message */}
            {fetchError && (
                <div className="nvt-fetch-error">
                    <span className="nvt-warning-icon">!</span>
                    {fetchError}
                </div>
            )}

            {/* Loading spinner during AJAX search */}
            {isSearching && (
                <div className="nvt-search-loading">
                    <span className="nvt-spinner" />
                    <span>{t('searching', 'Searching...')}</span>
                </div>
            )}
        </div>
    );
}
