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

describe('expected-age guard (sphinx fixed-price offers)', () => {
    function renderGuardedForm({ expectedAge = 7, checkIn = '2026-07-24' } = {}) {
        document.body.innerHTML = `
            <form id="guarded-form" action="#">
                <input type="hidden" name="check_in" value="${checkIn}">
                <div class="travel-guest-field">
                    <input type="text" id="child-dob" data-expected-age="${expectedAge}" maxlength="10">
                </div>
                <button type="submit">Book</button>
            </form>
        `;
        return document.getElementById('child-dob');
    }

    it('flags a DOB implying a different age at check-in and clears on correction', () => {
        const input = renderGuardedForm({ expectedAge: 7 });

        // Born 25/07/2019 → still 6 on 2026-07-24 (birthday the day after)
        input.value = '25/07/2019';
        input.dispatchEvent(new Event('focusout', { bubbles: true }));

        expect(input.getAttribute('aria-invalid')).toBe('true');
        const msg = input.parentElement.querySelector('.js-age-mismatch-msg');
        expect(msg).not.toBeNull();
        expect(msg.textContent).toContain('6');
        expect(msg.textContent).toContain('7');

        // Corrected to 24/07/2019 → exactly 7 at check-in
        input.value = '24/07/2019';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(input.getAttribute('aria-invalid')).toBeNull();
        expect(input.parentElement.querySelector('.js-age-mismatch-msg')).toBeNull();
    });

    it('blocks form submission while a mismatch exists and allows it when resolved', () => {
        const input = renderGuardedForm({ expectedAge: 7 });
        const form = document.getElementById('guarded-form');

        input.value = '25/07/2019';
        let submitted = null;
        const listener = (e) => { submitted = e.defaultPrevented; e.preventDefault(); };
        // Registered AFTER the module's guard listener → sees its preventDefault.
        document.addEventListener('submit', listener);

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(submitted).toBe(true);

        input.value = '24/07/2019';
        submitted = null;
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(submitted).toBe(false);

        document.removeEventListener('submit', listener);
    });

    it('stays silent on inputs without the attribute and on incomplete DOBs', () => {
        const input = renderGuardedForm({ expectedAge: 7 });

        // Incomplete DOB — still typing
        input.value = '25/07/20';
        input.dispatchEvent(new Event('focusout', { bubbles: true }));
        expect(input.getAttribute('aria-invalid')).toBeNull();

        // Unarmed input (novoton path) never flags
        document.body.innerHTML = `
            <form><input type="hidden" name="check_in" value="2026-07-24">
            <div><input type="text" id="plain-dob"></div></form>
        `;
        const plain = document.getElementById('plain-dob');
        plain.value = '25/07/2019';
        plain.dispatchEvent(new Event('focusout', { bubbles: true }));
        expect(plain.getAttribute('aria-invalid')).toBeNull();
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

describe('basic DOB validation (shared guest cards / js-dob-basics)', () => {
    function makeInput(dobValue, { child = true, checkIn = '2026-08-17' } = {}) {
        const name = child ? 'guests[room1_child_1][dob]' : 'guests[room1_adult_1][dob]';
        document.body.innerHTML = `
            <form>
                <input name="check_in" value="${checkIn}">
                <div class="travel-guest-field">
                    <input class="dob-masked-input js-dob-basics" name="${name}" value="${dobValue}">
                </div>
            </form>`;
        return document.querySelector('.js-dob-basics');
    }
    const msgOf = (input) => input.parentElement.querySelector('.js-dob-basics-msg');

    it('flags an incomplete mask with the ZZ/LL/AAAA format message', () => {
        const input = makeInput('24/07');
        expect(window.TravelBooking.validateDobBasics(input)).toBe(false);
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(msgOf(input).textContent).toContain('Format invalid');
    });

    it('flags an impossible calendar date (31/02) that Date would roll over', () => {
        const input = makeInput('31/02/2020');
        expect(window.TravelBooking.validateDobBasics(input)).toBe(false);
        expect(msgOf(input).textContent).toContain('Format invalid');
    });

    it('flags a birth date in the future', () => {
        // Tomorrow, computed dynamically so the outcome is date-independent;
        // on Dec 31 the year-range rule fires instead — both are failures.
        const t = new Date(Date.now() + 86400000);
        const dd = String(t.getDate()).padStart(2, '0');
        const mm = String(t.getMonth() + 1).padStart(2, '0');
        const input = makeInput(`${dd}/${mm}/${t.getFullYear()}`);
        expect(window.TravelBooking.validateDobBasics(input)).toBe(false);
        expect(input.getAttribute('aria-invalid')).toBe('true');
    });

    it('rejects a child who is 18 or older at check-in (novoton rule)', () => {
        const input = makeInput('24/07/2000', { checkIn: '2026-08-17' });
        expect(window.TravelBooking.validateDobBasics(input)).toBe(false);
        expect(msgOf(input).textContent).toContain('sub 18');
    });

    it('accepts a valid child DOB and clears a previous flag', () => {
        const input = makeInput('24/07');
        window.TravelBooking.validateDobBasics(input);
        expect(input.getAttribute('aria-invalid')).toBe('true');

        input.value = '24/07/2020';
        expect(window.TravelBooking.validateDobBasics(input)).toBe(true);
        expect(input.getAttribute('aria-invalid')).toBeNull();
        expect(msgOf(input)).toBeNull();
    });

    it('does not apply the under-18 rule to adult DOB inputs', () => {
        const input = makeInput('24/07/1990', { child: false });
        expect(window.TravelBooking.validateDobBasics(input)).toBe(true);
        expect(msgOf(input)).toBeNull();
    });

    it('leaves empty values to the required attribute', () => {
        const input = makeInput('');
        expect(window.TravelBooking.validateDobBasics(input)).toBe(true);
        expect(msgOf(input)).toBeNull();
    });

    it('stays inert on focusout for inputs without the marker class (novoton path)', () => {
        document.body.innerHTML = `
            <form>
                <div class="travel-guest-field">
                    <input class="dob-masked-input" name="guests[room1_child_1][dob]" value="99/99/9999">
                </div>
            </form>`;
        const input = document.querySelector('.dob-masked-input');
        input.dispatchEvent(new Event('focusout', { bubbles: true }));
        expect(input.getAttribute('aria-invalid')).toBeNull();
        expect(document.querySelector('.js-dob-basics-msg')).toBeNull();
    });
});
