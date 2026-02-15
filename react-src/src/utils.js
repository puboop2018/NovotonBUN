/**
 * Novoton Booking Engine - Utility functions
 *
 * Date parsing, formatting, locale detection and
 * a helper to read window.NovotonTranslations.
 */

import {
    MONTHS_SHORT_EN,
    MONTHS_SHORT_RO,
    DAYS_SHORT_EN,
    DAYS_SHORT_RO,
} from './translations';

// ---------------------------------------------------------------------------
// Locale detection
// ---------------------------------------------------------------------------

/**
 * Detect the active locale by inspecting DOM attributes and
 * the CS-Cart Tygh global.
 *
 * @returns {'en'|'ro'}
 */
export function getLocale() {
    // 1. Explicit data-lang on a booking widget element
    const els = document.querySelectorAll('[data-lang], [data-novoton-booking]');
    for (const el of els) {
        const lang = el.getAttribute('data-lang');
        if (lang && lang.toLowerCase().startsWith('ro')) return 'ro';
    }

    // 2. <html lang="...">
    const html = document.documentElement;
    const htmlLang = (html.getAttribute('lang') || html.getAttribute('xml:lang') || '').toLowerCase();
    if (htmlLang.startsWith('ro')) return 'ro';

    // 3. CS-Cart Tygh.language
    if (typeof window !== 'undefined' && window.Tygh && window.Tygh.language) {
        if (window.Tygh.language.toLowerCase().startsWith('ro')) return 'ro';
    }

    return 'en';
}

// ---------------------------------------------------------------------------
// Translation helper
// ---------------------------------------------------------------------------

/**
 * Read a key from `window.NovotonTranslations`, falling back to the
 * provided default.
 */
export function t(key, fallback) {
    const dict = (typeof window !== 'undefined' && window.NovotonTranslations) || {};
    return dict[key] || fallback || key;
}

// ---------------------------------------------------------------------------
// Date utilities
// ---------------------------------------------------------------------------

/**
 * Parse a "YYYY-MM-DD" string into a Date (noon, to avoid TZ issues).
 */
export function parseDate(str) {
    if (!str) return null;
    const parts = str.split('-');
    if (parts.length !== 3) return null;
    return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]), 12, 0, 0);
}

/**
 * Format a Date as "YYYY-MM-DD".
 */
export function toDateString(date) {
    if (!date) return '';
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/**
 * Human-readable short date – e.g. "Mon. 14 Feb." / "Lun. 14 Febr."
 */
export function formatDateShort(date) {
    if (!date) return '';
    const locale = getLocale();
    const dayNames = locale === 'ro' ? DAYS_SHORT_RO : DAYS_SHORT_EN;
    const monthNames = locale === 'ro' ? MONTHS_SHORT_RO : MONTHS_SHORT_EN;
    const day = dayNames[date.getDay()].toLowerCase();
    const month = monthNames[date.getMonth()].toLowerCase();
    return day + ', ' + date.getDate() + ' ' + month;
}

/**
 * Count nights between two dates.
 */
export function nightsBetween(checkIn, checkOut) {
    if (!checkIn || !checkOut) return 0;
    return Math.ceil((checkOut.getTime() - checkIn.getTime()) / 86400000);
}
