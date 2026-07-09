import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the shared DOB validation module
 * (addon-travel-core/js/addons/travel_core/dob-validation.js).
 *
 * The module is a script-mode IIFE exposing its API on
 * window.TravelBooking.* — importing it registers DOMContentLoaded/load
 * listeners (which never fire here) and defines the functions under test.
 */
beforeAll(async () => {
    window.TravelBooking = window.TravelBooking || {};
    await import('../../addon-travel-core/js/addons/travel_core/dob-validation.js');
});

describe('validateDOBForCheckIn', () => {
    it('accepts an empty value (optional adult DOB)', () => {
        const result = window.TravelBooking.validateDOBForCheckIn('', '2026-07-24');
        expect(result.valid).toBe(true);
        expect(result.age).toBe(0);
        expect(result.error).toBeNull();
    });

    it('rejects a future date of birth', () => {
        const result = window.TravelBooking.validateDOBForCheckIn('01/01/2090', '2026-07-24');
        expect(result.valid).toBe(false);
        expect(result.error).toBeTruthy();
    });

    it('computes the age AT CHECK-IN, not today (DD/MM/YYYY input)', () => {
        const result = window.TravelBooking.validateDOBForCheckIn('10/05/1990', '2026-07-24');
        expect(result.valid).toBe(true);
        expect(result.age).toBe(36);
    });

    it('handles the birthday-boundary around check-in day', () => {
        // Birthday the day AFTER check-in → still 6 at check-in
        expect(window.TravelBooking.validateDOBForCheckIn('25/07/2019', '2026-07-24').age).toBe(6);
        // Birthday ON check-in day → 7
        expect(window.TravelBooking.validateDOBForCheckIn('24/07/2019', '2026-07-24').age).toBe(7);
    });

    it('accepts ISO (YYYY-MM-DD) check-in dates and dotted DOB input', () => {
        const result = window.TravelBooking.validateDOBForCheckIn('10.05.2019', '2026-07-24');
        expect(result.valid).toBe(true);
        expect(result.age).toBe(7);
    });
});
