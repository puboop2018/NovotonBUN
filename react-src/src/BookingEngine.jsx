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

    const engineRef = useRef(null);

    // -----------------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------------

    const totalAdults = rooms.reduce((sum, r) => sum + r.adults, 0);
    const totalChildren = rooms.reduce((sum, r) => sum + r.children, 0);
    const nights = nightsBetween(checkIn, checkOut);

    // Build guest summary text
    const guestSummary = (() => {
        const parts = [];

        const adultLabel = totalAdults === 1 ? t('adult', 'adult') : t('adults', 'adults');
        parts.push(`${totalAdults} ${adultLabel}`);

        const childLabel = totalChildren === 1 ? t('child', 'child') : t('children', 'children');
        parts.push(`${totalChildren} ${childLabel}`);

        const roomLabel = rooms.length === 1 ? t('room', 'room') : t('rooms', 'rooms');
        parts.push(`${rooms.length} ${roomLabel}`);

        return parts.join(' · ');
    })();

    // Date display text – show partial (check-in only) or full range
    const dateDisplayText = (() => {
        if (checkIn && checkOut) {
            const nightLabel = nights === 1 ? t('night', 'night') : t('nights', 'nights');
            return `${formatDateShort(checkIn)} — ${formatDateShort(checkOut)} (${nights} ${nightLabel})`;
        }
        if (checkIn) {
            return `${formatDateShort(checkIn)} — ...`;
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
    }, []);

    const handleRoomsUpdate = useCallback((newRooms) => {
        setRooms(newRooms);
        setAgeErrors([]);
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

        // Build URL
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
        url += '&_t=' + Date.now();

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

        window.location.href = url;
    }, [checkIn, checkOut, rooms, mode, hotelId, productId, searchQuery, totalAdults, totalChildren]);

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

    const searchBtnText = buttonText || t('search', 'Search');

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
                            {mode !== 'search' && (
                                <span className="nvt-label">
                                    {t('checkIn', 'Check-in')} — {t('checkOut', 'Check-out')}
                                </span>
                            )}
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
                        onClick={handleSearch}
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
