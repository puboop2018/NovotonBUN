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

import { useState, useCallback, useRef, useEffect } from 'react';
import Calendar from './Calendar';
import GuestPicker from './GuestPicker';
import { CalendarIcon, GuestIcon, ChevronDown, RefreshIcon } from './icons';
import { parseDate, toDateString, formatDateShort, nightsBetween, t } from './utils';
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

    const [showCalendar, setShowCalendar] = useState(false);
    const [showGuests, setShowGuests] = useState(false);
    const [validationError, setValidationError] = useState('');
    const [ageErrors, setAgeErrors] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearching, setIsSearching] = useState(false);

    // Button state: "Search" → "Change search" → "Apply changes"
    // In search mode, user already searched so start with hasSearched=true.
    // On product page with pre-filled dates, also start as hasSearched.
    const [hasSearched, setHasSearched] = useState(
        mode === 'search' || (!!initialCheckIn && !!initialCheckOut)
    );
    const [paramsChanged, setParamsChanged] = useState(false);

    const engineRef = useRef(null);

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    const totalAdults = rooms.reduce((sum, r) => sum + r.adults, 0);
    const totalChildren = rooms.reduce((sum, r) => sum + r.children, 0);
    const nights = nightsBetween(checkIn, checkOut);

    // Check if any child is missing an age selection
    const hasChildrenMissingAge = rooms.some(room =>
        room.children > 0 &&
        (room.childrenAges || []).some(age => age === null || age === undefined || age === '')
    );

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

    // Date display text – e.g. "Mon. 14 Feb. - Mon. 21 Feb. — 7 nights"
    const dateDisplayText = (() => {
        if (checkIn && checkOut) {
            const nightLabel = nights === 1 ? t('night', 'night') : t('nights', 'nights');
            return `${formatDateShort(checkIn)} - ${formatDateShort(checkOut)} — ${nights} ${nightLabel}`;
        }
        if (checkIn) {
            return `${formatDateShort(checkIn)} - ...`;
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
        setAgeErrors([]);
        if (hasSearched) setParamsChanged(true);
    }, [hasSearched]);

    const buildSearchUrl = useCallback(() => {
        let url;
        if (mode === 'homepage') {
            url = window.location.origin + '/index.php?dispatch=products.search&q=' +
                  encodeURIComponent(searchQuery);
        } else {
            url = window.location.origin + '/index.php?dispatch=novoton_booking.search';
            if (hotelId) url += '&hotel_id=' + hotelId;
            if (productId) url += '&product_id=' + productId;
        }

        url += '&check_in=' + toDateString(checkIn);
        url += '&check_out=' + toDateString(checkOut);
        url += '&adults=' + totalAdults;
        url += '&children=' + totalChildren;
        url += '&rooms=' + rooms.length;
        url += '&rooms_data=' + encodeURIComponent(JSON.stringify(rooms));

        // Collect all child ages
        const allAges = [];
        rooms.forEach(room => {
            (room.childrenAges || []).forEach(age => {
                if (age !== null && age !== undefined) allAges.push(age);
            });
        });
        if (allAges.length > 0) {
            url += '&children_ages=' + allAges.join(',');
        }

        return url;
    }, [checkIn, checkOut, rooms, mode, hotelId, productId, searchQuery, totalAdults, totalChildren]);

    const performAjaxSearch = useCallback((url) => {
        setIsSearching(true);
        setShowCalendar(false);
        setShowGuests(false);

        const fetchUrl = url + '&_t=' + Date.now();

        fetch(fetchUrl)
            .then(r => r.text())
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

                // Execute inline scripts in the new content
                curPage.querySelectorAll('script').forEach(oldScript => {
                    if (oldScript.closest('.novoton-search-form-wrapper')) return;
                    // Skip already-loaded external scripts (React, DOB validation)
                    if (oldScript.src && (oldScript.src.includes('react19') || oldScript.src.includes('dob-validation'))) return;
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
                // Fallback to full page navigation on error
                window.location.href = fetchUrl;
            });
    }, []);

    const handleSearch = useCallback(() => {
        // Validate dates
        if (!checkIn || !checkOut) {
            setValidationError(t('pleaseEnterDates', 'Please select check-in and check-out dates'));
            setShowCalendar(true);
            return;
        }

        // Validate child ages
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
            setValidationError(t('selectAge', 'Please select the age of each child'));
            setShowGuests(true);
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

    // Button click handler: "Change search" opens calendar, others navigate
    const handleButtonClick = useCallback(() => {
        if (isSearching) return;
        if (hasSearched && !paramsChanged) {
            // "Change search" state – open calendar for editing
            setShowCalendar(true);
            setShowGuests(false);
            return;
        }
        // "Search" or "Apply changes" – perform search
        handleSearch();
    }, [hasSearched, paramsChanged, handleSearch, isSearching]);

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
                    <div className="nvt-field" style={{ flex: 1.5 }}>
                        <div className="nvt-field-input" style={{ padding: 0 }}>
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={e => setSearchQuery(e.target.value)}
                                placeholder={t('whereAreYouGoing', 'Where are you going?')}
                                style={{
                                    border: 'none',
                                    outline: 'none',
                                    width: '100%',
                                    padding: '12px 14px',
                                    fontSize: '14px',
                                    fontFamily: 'inherit',
                                    background: 'transparent',
                                }}
                            />
                        </div>
                    </div>
                )}

                {/* Date field */}
                <div className="nvt-field nvt-field--date">
                    <button
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowCalendar(!showCalendar); setShowGuests(false); }}
                    >
                        <span className="nvt-field-input-icon"><CalendarIcon /></span>
                        <span className="nvt-field-input-text">
                            {dateDisplayText ? (
                                <span className="nvt-value">{dateDisplayText}</span>
                            ) : (
                                <span className="nvt-value nvt-value--placeholder">
                                    {`${t('checkIn', 'Check-in')} — ${t('checkOut', 'Check-out')}`}
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
                        />
                    )}
                </div>

                {/* Guests field */}
                <div className="nvt-field nvt-field--guests">
                    <button
                        type="button"
                        className="nvt-field-input"
                        onClick={() => { setShowGuests(!showGuests); setShowCalendar(false); }}
                    >
                        <span className="nvt-field-input-icon"><GuestIcon /></span>
                        <span className="nvt-field-input-text">
                            <span className="nvt-value">{guestSummary}</span>
                        </span>
                        <span className="nvt-field-input-arrow"><ChevronDown /></span>
                    </button>

                    {hasChildrenMissingAge && (
                        <div className="nvt-child-age-hint">
                            {t('pleaseSelectChildAge', 'Please select the age of each child')}
                        </div>
                    )}

                    {showGuests && (
                        <GuestPicker
                            rooms={rooms}
                            maxRooms={maxRooms}
                            maxAdults={maxAdults}
                            maxChildren={maxChildren}
                            onUpdate={handleRoomsUpdate}
                            onClose={() => setShowGuests(false)}
                            ageErrors={ageErrors}
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

            {/* Validation message */}
            {validationError && (
                <div className="nvt-validation-message">
                    <span className="nvt-warning-icon">!</span>
                    {validationError}
                </div>
            )}
        </div>
    );
}
