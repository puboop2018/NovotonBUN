/**
 * Novoton Booking Engine - Calendar date-range picker
 *
 * Shows two consecutive months side-by-side. The user picks a check-in
 * date first, then a check-out date. Selected range is highlighted.
 *
 * When `prices` prop is provided, each day cell displays a small per-night
 * price label below the day number, similar to Booking.com. Dates without
 * prices show "_" and are greyed out.
 */

import { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { getLocale, nightsBetween, formatDateShort, t } from './utils';
import { MONTHS_EN, MONTHS_RO, WEEKDAYS_EN, WEEKDAYS_RO } from './translations';
import { ChevronLeft, ChevronRight } from './icons';

/**
 * Build a matrix of weeks (rows of 7 cells) for a given month.
 * Empty cells are `null`.
 */
function buildCalendarGrid(year, month) {
    const firstDay = new Date(year, month, 1);
    // JS getDay(): 0=Sun … 6=Sat → convert to Mon-based (0=Mon … 6=Sun)
    let startDay = firstDay.getDay() - 1;
    if (startDay < 0) startDay = 6;

    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells = [];

    for (let i = 0; i < startDay; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(d);

    return cells;
}

function isSameDay(a, b) {
    if (!a || !b) return false;
    return a.getFullYear() === b.getFullYear() &&
           a.getMonth() === b.getMonth() &&
           a.getDate() === b.getDate();
}

function isInRange(date, checkIn, checkOut) {
    if (!date || !checkIn || !checkOut) return false;
    return date > checkIn && date < checkOut;
}

/**
 * Convert a Date to "YYYY-MM-DD" string for price map lookup.
 */
function toDateKey(year, month, day) {
    const m = String(month + 1).padStart(2, '0');
    const d = String(day).padStart(2, '0');
    return `${year}-${m}-${d}`;
}

/**
 * Format a price for compact calendar display.
 * 5000 → "5k", 12500 → "13k", 350 → "350", 99 → "99"
 */
function formatCalendarPrice(price) {
    if (price >= 1000) {
        return Math.round(price / 1000) + 'k';
    }
    return String(Math.round(price));
}

export default function Calendar({ checkIn, checkOut, onSelect, onClose, prices, pricesCurrency }) {
    const locale = getLocale();
    const monthNames = locale === 'ro' ? MONTHS_RO : MONTHS_EN;
    const weekdays = locale === 'ro' ? WEEKDAYS_RO : WEEKDAYS_EN;

    const hasPrices = prices && typeof prices === 'object' && Object.keys(prices).length > 0;

    // Memoize today so it doesn't invalidate useCallback deps on every render
    const today = useMemo(() => {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }, []);

    // Start calendar from check-in month, or current month
    const startMonth = checkIn ? new Date(checkIn.getFullYear(), checkIn.getMonth(), 1)
                               : new Date(today.getFullYear(), today.getMonth(), 1);

    const [viewDate, setViewDate] = useState(startMonth);
    const [selecting, setSelecting] = useState(null); // null | 'checkIn' | 'checkOut'
    const [tempCheckIn, setTempCheckIn] = useState(checkIn);
    const [tempCheckOut, setTempCheckOut] = useState(checkOut);
    const popupRef = useRef(null);

    // Close on outside click or Escape key
    useEffect(() => {
        function handleClick(e) {
            if (popupRef.current && !popupRef.current.contains(e.target)) {
                onClose && onClose();
            }
        }
        function handleKeyDown(e) {
            if (e.key === 'Escape') {
                onClose && onClose();
            }
        }
        document.addEventListener('mousedown', handleClick);
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('mousedown', handleClick);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose]);

    const goToPrev = useCallback(() => {
        setViewDate(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1));
    }, []);

    const goToNext = useCallback(() => {
        setViewDate(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1));
    }, []);

    const handleDayClick = useCallback((date) => {
        if (date < today) return;

        // CASE 1 & 4: No dates or both dates selected → fresh CHECK-IN
        if (!tempCheckIn || (tempCheckIn && tempCheckOut)) {
            setTempCheckIn(date);
            setTempCheckOut(null);
            setSelecting('checkOut');
            onSelect(date, null);
        }
        // CASE 3: Only check-in + click before/on → new CHECK-IN
        else if (date <= tempCheckIn) {
            setTempCheckIn(date);
            setTempCheckOut(null);
            setSelecting('checkOut');
            onSelect(date, null);
        }
        // CASE 2: Only check-in + click after → CHECK-OUT (complete)
        else {
            setTempCheckOut(date);
            setSelecting(null);
            onSelect(tempCheckIn, date);
        }
    }, [tempCheckIn, tempCheckOut, today, onSelect]);

    // Two consecutive months
    const month1Year = viewDate.getFullYear();
    const month1Month = viewDate.getMonth();
    const month2Date = new Date(month1Year, month1Month + 1, 1);
    const month2Year = month2Date.getFullYear();
    const month2Month = month2Date.getMonth();

    const canGoPrev = new Date(month1Year, month1Month, 1) > new Date(today.getFullYear(), today.getMonth(), 1);

    const nights = nightsBetween(tempCheckIn, tempCheckOut);

    function renderMonth(year, month) {
        const cells = buildCalendarGrid(year, month);

        return (
            <div className="nvt-calendar-month">
                <div className="nvt-calendar-header">
                    <h3>{monthNames[month]} {year}</h3>
                </div>
                <div className="nvt-calendar-weekdays">
                    {weekdays.map((d, i) => <span key={i}>{d}</span>)}
                </div>
                <div className={`nvt-calendar-days${hasPrices ? ' nvt-calendar-days--with-prices' : ''}`}>
                    {cells.map((day, i) => {
                        if (day === null) {
                            return <span key={`e${i}`} className="nvt-calendar-day nvt-calendar-day--empty" />;
                        }

                        const date = new Date(year, month, day, 12, 0, 0);
                        const isPast = date < today;
                        const isToday = isSameDay(date, today);
                        const isSelectedCheckIn = isSameDay(date, tempCheckIn);
                        const isSelectedCheckOut = isSameDay(date, tempCheckOut);
                        const inRange = isInRange(date, tempCheckIn, tempCheckOut);

                        // Price lookup
                        const dateKey = toDateKey(year, month, day);
                        const dayPrice = hasPrices ? prices[dateKey] : undefined;
                        const hasNoPrice = hasPrices && !isPast && dayPrice === undefined;

                        let className = 'nvt-calendar-day';
                        if (hasPrices) className += ' nvt-calendar-day--has-prices';
                        if (isPast) className += ' nvt-calendar-day--disabled';
                        if (isToday) className += ' nvt-calendar-day--today';
                        if (isSelectedCheckIn || isSelectedCheckOut) className += ' nvt-calendar-day--selected';
                        if (inRange) className += ' nvt-calendar-day--in-range';
                        if (hasNoPrice) className += ' nvt-calendar-day--no-price';

                        // Build accessible label for screen readers
                        const ariaLabel = (() => {
                            const dateStr = `${day} ${monthNames[month]} ${year}`;
                            if (isPast) return `${dateStr}, ${t('unavailable', 'unavailable')}`;

                            const parts = [dateStr];
                            if (isSelectedCheckIn) parts.push(t('selectedCheckIn', 'selected as check-in'));
                            else if (isSelectedCheckOut) parts.push(t('selectedCheckOut', 'selected as check-out'));
                            else if (inRange) parts.push(t('withinStay', 'within your stay'));

                            if (hasPrices && dayPrice !== undefined) {
                                parts.push(`${dayPrice} ${pricesCurrency || ''} ${t('perNight', 'per night')}`.trim());
                            } else if (hasPrices) {
                                parts.push(t('priceUnavailable', 'price unavailable'));
                            }
                            return parts.join(', ');
                        })();

                        return (
                            <button
                                key={day}
                                type="button"
                                className={className}
                                disabled={isPast}
                                onClick={() => handleDayClick(date)}
                                aria-label={ariaLabel}
                                aria-pressed={isSelectedCheckIn || isSelectedCheckOut || undefined}
                            >
                                <span className="nvt-calendar-day-num">{day}</span>
                                {hasPrices && !isPast && (
                                    <span className="nvt-calendar-day-price">
                                        {dayPrice !== undefined ? formatCalendarPrice(dayPrice) : '_'}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>
        );
    }

    // Footer text – e.g. "Mon. 14 Feb. - Mon. 21 Feb. — 7 nights"
    const footerText = (() => {
        if (tempCheckIn && tempCheckOut) {
            const nightLabel = nights === 1 ? t('night', 'night') : t('nights', 'nights');
            return `${formatDateShort(tempCheckIn)} - ${formatDateShort(tempCheckOut)} — ${nights} ${nightLabel}`;
        }
        if (tempCheckIn) {
            return `${formatDateShort(tempCheckIn)} - ${t('selectCheckOut', 'Select check-out date')}`;
        }
        return t('selectCheckIn', 'Select check-in date');
    })();

    // Price footer text — "Approximate prices in EUR for a 1-night stay"
    const priceFooterText = hasPrices && pricesCurrency
        ? t('calendarPriceFooter', 'Approximate prices in %s for a 1-night stay').replace('%s', pricesCurrency)
        : '';

    return (
        <div className="nvt-calendar-popup" ref={popupRef} role="dialog" aria-modal="true" aria-label={t('datePicker', 'Date picker')}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                <button
                    type="button"
                    className="nvt-calendar-nav"
                    onClick={goToPrev}
                    disabled={!canGoPrev}
                    aria-label={t('previousMonth', 'Previous month')}
                >
                    <ChevronLeft />
                </button>
                <button
                    type="button"
                    className="nvt-calendar-nav"
                    onClick={goToNext}
                    aria-label={t('nextMonth', 'Next month')}
                >
                    <ChevronRight />
                </button>
            </div>

            <div className="nvt-calendar-months">
                {renderMonth(month1Year, month1Month)}
                {renderMonth(month2Year, month2Month)}
            </div>

            <div className="nvt-calendar-footer">
                <span>{footerText}</span>
            </div>

            {priceFooterText && (
                <div className="nvt-calendar-price-footer">
                    {priceFooterText}
                </div>
            )}
        </div>
    );
}
