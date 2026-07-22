import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the novoton booking-form module
 * (addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js) — the
 * REAL file the storefront loads, extracted from booking_form.tpl. Covers
 * the nvtLabel raw-lang-key guard, HTML escaping, the children-ages
 * collector the initial-load recalc depends on, and the submit validation.
 */
beforeAll(async () => {
    document.body.innerHTML = '<form id="novoton-booking-form"></form>';
    window.bookingData = {
        checkIn: '2026-08-10',
        numRooms: 1,
        ajaxRecalculateUrl: 'index.php?dispatch=novoton_booking.ajax_recalculate_price',
    };
    window.NovotonBookingI18n = {
        fillAllFields: 'Completați câmpurile',
        available: '_novoton_holidays.available', // raw key — guard must swap the fallback in
    };
    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js');
});

describe('nvtLabel (raw-lang-key guard)', () => {
    it('returns the config label when it is a real translation', () => {
        expect(window.nvtLabel ?? nvtLabelFrom()).toBeTypeOf('function');
        expect(nvtLabelFrom()('fillAllFields', 'fb')).toBe('Completați câmpurile');
    });

    it('falls back for raw "_"-prefixed and missing values', () => {
        const t = nvtLabelFrom();
        expect(t('available', 'Disponibil')).toBe('Disponibil');
        expect(t('nonexistent', 'fb')).toBe('fb');
    });

    function nvtLabelFrom() {
        // Classic-script top-level functions become window globals.
        return window.nvtLabel;
    }
});

describe('escapeHtml', () => {
    it('escapes markup-significant characters', () => {
        expect(window.escapeHtml('<b>&"</b>')).toContain('&lt;b&gt;');
        expect(window.escapeHtml('')).toBe('');
    });
});

describe('collectChildrenAges', () => {
    beforeEach(() => {
        document.querySelectorAll('input').forEach((el) => el.remove());
    });

    it('collects valid child ages from the single-room inputs', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <input id="child_age_r1_c1" value="4">
            <input id="child_age_r1_c2" value="17">
            <input id="child_age_r1_c3" value="">
        `);
        expect(window.collectChildrenAges(1)).toEqual([4, 17]);
    });

    it('drops adult-range and non-numeric values', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <input id="child_age_r1_c1" value="18">
            <input id="child_age_r1_c2" value="abc">
            <input id="child_age_r1_c3" value="7">
        `);
        expect(window.collectChildrenAges(1)).toEqual([7]);
    });
});

describe('submit validation', () => {
    it('blocks submit and alerts when a required field is empty', () => {
        const form = document.getElementById('novoton-booking-form');
        form.innerHTML = '<input required value="">';
        const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
        const ev = new Event('submit', { cancelable: true });
        form.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(alertSpy).toHaveBeenCalledWith('Completați câmpurile');
        alertSpy.mockRestore();
    });
});
