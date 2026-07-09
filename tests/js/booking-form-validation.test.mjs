import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the shared booking-form module
 * (addon-travel-core/js/addons/travel_core/booking-form-validation.js):
 * DOB input masking, masked-value parsing, age-at-date math, and the price
 * display update path the AJAX recalculation drives.
 */
beforeAll(async () => {
    window.TravelBooking = window.TravelBooking || {};
    window.TravelTranslations = { currency: 'RON', priceUpdated: 'Pretul a fost actualizat' };
    await import('../../addon-travel-core/js/addons/travel_core/booking-form-validation.js');
});

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('applyDobMask', () => {
    function maskedValueFor(typedDigits) {
        document.body.innerHTML = '<input type="text" id="dob">';
        const input = document.getElementById('dob');
        input.value = typedDigits;
        input.setSelectionRange(typedDigits.length, typedDigits.length);
        window.TravelBooking.applyDobMask(input);
        return input.value;
    }

    it('masks bare digits into DD/MM/YYYY progressively', () => {
        expect(maskedValueFor('2')).toBe('2');
        expect(maskedValueFor('24')).toBe('24/');
        expect(maskedValueFor('2407')).toBe('24/07/');
        expect(maskedValueFor('24071990')).toBe('24/07/1990');
    });

    it('strips non-digits before masking', () => {
        expect(maskedValueFor('24a07x1990')).toBe('24/07/1990');
    });

    it('does not force trailing slashes right after Backspace', () => {
        document.body.innerHTML = '<input type="text" id="dob">';
        const input = document.getElementById('dob');
        // Simulate: value was 24/, user hits Backspace leaving "24"
        window.TravelBooking.handleDobKeydown({ key: 'Backspace' });
        input.value = '24';
        input.setSelectionRange(2, 2);
        window.TravelBooking.applyDobMask(input);
        expect(input.value).toBe('24');
    });
});

describe('parseDobMasked', () => {
    it('parses a complete masked value', () => {
        expect(window.TravelBooking.parseDobMasked('24/07/1990')).toEqual({ day: 24, month: 7, year: 1990 });
    });

    it('rejects incomplete or malformed values', () => {
        expect(window.TravelBooking.parseDobMasked('24/07/199')).toBeNull();
        expect(window.TravelBooking.parseDobMasked('24-07-1990')).toBeNull();
        expect(window.TravelBooking.parseDobMasked('')).toBeNull();
    });
});

describe('calculateAgeAtDate', () => {
    it('computes age at the target date with birthday boundaries', () => {
        expect(window.TravelBooking.calculateAgeAtDate(new Date(2019, 6, 25), new Date(2026, 6, 24))).toBe(6);
        expect(window.TravelBooking.calculateAgeAtDate(new Date(2019, 6, 24), new Date(2026, 6, 24))).toBe(7);
    });
});

describe('updatePriceDisplay (the AJAX recalc UI path)', () => {
    it('writes the server-formatted price into every price element', () => {
        document.body.innerHTML = `
            <div class="booking-price-box"><div class="price-total">100.00 RON</div></div>
            <div class="guest-names-section"><h3>Guests</h3></div>
        `;

        window.TravelBooking.updatePriceDisplay(123.45, '123<sup>45</sup> RON', 0);

        expect(document.querySelector('.price-total').innerHTML).toBe('123<sup>45</sup> RON');
        // No difference → no change notification injected
        expect(document.getElementById('price-change-notification')).toBeNull();
    });

    it('falls back to translations currency and shows the change notification', () => {
        document.body.innerHTML = `
            <div class="booking-price-box"><div class="price-total">100.00 RON</div></div>
            <div class="guest-names-section"><h3>Guests</h3></div>
        `;

        window.TravelBooking.updatePriceDisplay(123.4, '', 23.4);

        expect(document.querySelector('.price-total').textContent).toBe('123.40 RON');
        const notif = document.getElementById('price-change-notification');
        expect(notif).not.toBeNull();
        expect(notif.textContent).toContain('+23.40');
    });
});
