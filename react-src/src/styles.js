/**
 * Novoton Booking Engine - CSS styles (injected once via <style>)
 *
 * Design tokens use CSS custom properties so theme overrides are possible
 * from the CS-Cart storefront stylesheet.
 */

let injected = false;

export function injectStyles() {
    if (injected) return;
    injected = true;

    const css = `
/* ======================================================================
   Novoton Booking Engine – CSS
   ====================================================================== */

.nvt-booking-engine {
    /* React-only variables (no LESS equivalent) */
    --nvt-z-popup: 1000;
    --nvt-btn-height: 52px;
    --nvt-btn-font: 15px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--nvt-text, #1a1a1a);
    position: relative;
}

/* ---------- Availability header ---------- */

.nvt-availability-header {
    margin-bottom: 12px;
}
.nvt-availability-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--nvt-primary, #003580);
    margin: 0 0 4px;
}
.nvt-availability-subtitle {
    font-size: 13px;
    color: var(--nvt-danger, #d32f2f);
    margin: 0;
}

/* ---------- Form row ---------- */

.nvt-form-row {
    display: flex;
    align-items: stretch;
    gap: 4px;
    border: 3px solid var(--nvt-accent, #FFB703);
    border-radius: var(--nvt-radius, 8px);
    background: var(--nvt-accent, #FFB703);
    overflow: visible;
    position: relative;
}

/* ---- Kill every hover / focus / active visual on form-row children ---- */
.nvt-form-row *,
.nvt-form-row *:hover,
.nvt-form-row *:focus,
.nvt-form-row *:active,
.nvt-form-row *:focus-visible {
    outline: none !important;
    box-shadow: none !important;
}
/* Kill hover backgrounds on all buttons inside booking engine popups */
.nvt-booking-engine button:hover,
.nvt-booking-engine button:focus,
.nvt-booking-engine button:active {
    outline: none !important;
    box-shadow: none !important;
}
.nvt-field-input,
.nvt-field-input:hover,
.nvt-field-input:focus,
.nvt-field-input:active,
button.nvt-field-input,
button.nvt-field-input:hover,
button.nvt-field-input:focus,
button.nvt-field-input:active {
    background: transparent !important;
    background-color: transparent !important;
    border-color: inherit !important;
}

.nvt-field {
    position: relative;
    flex: 1;
    background: var(--nvt-bg, #ffffff);
    border-radius: 4px;
}
.nvt-field--date {
    flex: 2;
}
/* Red border highlight when date validation fails */
.nvt-field--error {
    box-shadow: inset 0 0 0 2px var(--nvt-danger, #d32f2f);
    border-radius: 4px;
    animation: nvt-field-shake 0.3s ease-out;
}
@keyframes nvt-field-shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-3px); }
    75% { transform: translateX(3px); }
}
.nvt-field--guests {
    flex: 1.5;
}
.nvt-field--btn {
    flex: 0.5;
    padding: 0;
    display: flex;
    align-items: stretch;
}

.nvt-field-input {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    padding: 6px 12px;
    cursor: pointer;
    min-height: 50px;
    background: transparent;
    border: none;
    width: 100%;
    text-align: left;
    font: inherit;
    color: inherit;
    -webkit-user-select: text;
    user-select: text;
}

.nvt-field-input-icon {
    flex: 0 0 20px;
    color: var(--nvt-text-light, #6b6b6b);
    display: flex;
    align-items: center;
}
.nvt-field-input-text {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: flex-start;
}
.nvt-field-input-text .nvt-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--nvt-text-light, #6b6b6b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.nvt-field-input-text .nvt-value {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--nvt-text, #1a1a1a);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.4;
    -webkit-user-select: text;
    user-select: text;
    cursor: text;
}
.nvt-field-input-text .nvt-value--placeholder {
    color: var(--nvt-text, #1a1a1a);
}
.nvt-field-input-arrow {
    flex: 0 0 16px;
    color: var(--nvt-text-light, #6b6b6b);
    display: flex;
    align-items: center;
}

/* ---------- Search button ---------- */

.nvt-btn-search {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 28px;
    background: #0071E3;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: var(--nvt-btn-font, 15px);
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    width: 100%;
    height: 100%;
    transition: background 0.15s;
}
.nvt-btn-search:hover {
    background: #0071E3;
}

/* ---------- Validation message (tooltip under date field) ---------- */

.nvt-validation-message {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    z-index: calc(var(--nvt-z-popup) - 1);
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #fff;
    background: var(--nvt-danger, #d32f2f);
    border-radius: 6px;
    white-space: nowrap;
    box-shadow: 0 2px 12px rgba(0,0,0,0.15);
    animation: nvt-fade-in 0.2s ease-out;
}
.nvt-validation-message::before {
    content: '';
    position: absolute;
    top: -6px;
    left: 20px;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-bottom: 6px solid var(--nvt-danger, #d32f2f);
}
@keyframes nvt-fade-in {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ======================================================================
   Calendar popup
   ====================================================================== */

.nvt-calendar-popup {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: var(--nvt-z-popup);
    background: var(--nvt-bg, #ffffff);
    border-radius: var(--nvt-radius, 8px);
    box-shadow: 0 4px 24px rgba(0,0,0,0.15);
    padding: 20px;
    min-width: 600px;
    max-width: calc(100vw - 24px);
    box-sizing: border-box;
}

.nvt-calendar-months {
    display: flex;
    gap: 30px;
}
.nvt-calendar-month {
    flex: 1;
    min-width: 0;
}
.nvt-calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.nvt-calendar-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--nvt-text, #1a1a1a);
}
.nvt-calendar-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--nvt-border, #e0e0e0);
    background: var(--nvt-bg, #ffffff);
    cursor: pointer;
    color: var(--nvt-text, #1a1a1a);
    padding: 0;
}
.nvt-calendar-nav:hover {
    background: transparent !important;
}
.nvt-calendar-nav:disabled {
    opacity: 0.3;
    cursor: default;
}

.nvt-calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--nvt-text-light, #6b6b6b);
    margin-bottom: 4px;
}

.nvt-calendar-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}
/* When prices are displayed, switch from circles to rectangles */
.nvt-calendar-days--with-prices {
    gap: 1px;
}

.nvt-calendar-day {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    aspect-ratio: 1;
    border: none;
    border-radius: 50%;
    background: transparent;
    font-size: 13px;
    cursor: pointer;
    color: var(--nvt-text, #1a1a1a);
    padding: 0;
    transition: background 0.1s;
}
/* With prices: flex-column for number + price, rectangular cells */
.nvt-calendar-day--has-prices {
    flex-direction: column;
    aspect-ratio: auto;
    border-radius: 4px;
    padding: 4px 2px 3px;
    min-height: 44px;
    gap: 1px;
}
.nvt-calendar-day:hover {
    background: transparent !important;
}
.nvt-calendar-day--has-prices:hover:not(.nvt-calendar-day--disabled):not(.nvt-calendar-day--selected) {
    background: transparent !important;
}
.nvt-calendar-day--disabled {
    color: #ccc;
    cursor: default;
    pointer-events: none;
}
.nvt-calendar-day--today {
    font-weight: 700;
}
.nvt-calendar-day--selected {
    background: var(--nvt-primary, #003580) !important;
    color: #fff !important;
    font-weight: 600;
}
.nvt-calendar-day--selected .nvt-calendar-day-price {
    color: rgba(255, 255, 255, 0.85) !important;
}
.nvt-calendar-day--in-range {
    background: #e8f0fe;
    border-radius: 0;
}
.nvt-calendar-day--in-range.nvt-calendar-day--has-prices {
    border-radius: 4px;
}
.nvt-calendar-day--empty {
    visibility: hidden;
}
/* No-price dates: greyed out with reduced opacity */
.nvt-calendar-day--no-price {
    opacity: 0.45;
}
.nvt-calendar-day--no-price .nvt-calendar-day-price {
    color: #999;
}

/* Day number inside price-enabled cells */
.nvt-calendar-day-num {
    line-height: 1.2;
}
/* Price label below the day number */
.nvt-calendar-day-price {
    font-size: 9px;
    line-height: 1;
    color: var(--nvt-primary, #003580);
    font-weight: 600;
    white-space: nowrap;
}

.nvt-calendar-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--nvt-border, #e0e0e0);
    font-size: 13px;
    color: var(--nvt-text-light, #6b6b6b);
}
/* Approximate prices disclaimer footer */
.nvt-calendar-price-footer {
    text-align: center;
    font-size: 11px;
    color: #999;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed var(--nvt-border, #e0e0e0);
    font-style: italic;
}

.nvt-done-btn {
    padding: 8px 20px;
    background: var(--nvt-primary, #003580);
    color: #fff;
    border: none;
    border-radius: var(--nvt-radius, 8px);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.nvt-done-btn:hover {
    background: var(--nvt-primary, #003580);
}

/* ======================================================================
   Guest / Room picker popup
   ====================================================================== */

.nvt-guest-popup {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: var(--nvt-z-popup);
    background: var(--nvt-bg, #ffffff);
    border-radius: var(--nvt-radius, 8px);
    box-shadow: 0 4px 24px rgba(0,0,0,0.15);
    padding: 20px;
    min-width: 340px;
    max-width: calc(100vw - 24px);
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
}

.nvt-guest-rooms-container {
    flex: 1;
    overflow-y: auto;
    padding-right: 14px;
    margin-right: -4px;
}

.nvt-room-section {
    padding-bottom: 16px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--nvt-border, #e0e0e0);
}
.nvt-room-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.nvt-room-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.nvt-room-header h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--nvt-text, #1a1a1a);
}
.nvt-remove-room {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 400;
    color: var(--nvt-text-light, #6b6b6b);
    cursor: pointer;
    background: transparent;
    border: none;
    border-radius: 4px;
    padding: 4px 6px;
    transition: color 0.15s;
}
.nvt-remove-room:hover {
    background: transparent !important;
    border: none !important;
    color: var(--nvt-danger, #d32f2f) !important;
}

.nvt-guest-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.nvt-guest-label {
    font-size: 13px;
    color: var(--nvt-text, #1a1a1a);
}
.nvt-guest-label small {
    display: block;
    font-size: 11px;
    color: var(--nvt-text-light, #6b6b6b);
}

.nvt-guest-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.nvt-guest-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--nvt-border, #e0e0e0);
    background: var(--nvt-bg, #ffffff);
    font-size: 18px;
    cursor: pointer;
    color: var(--nvt-primary, #003580);
    padding: 0;
    transition: background 0.1s;
}
.nvt-guest-btn:hover {
    background: transparent !important;
}
.nvt-guest-btn:disabled {
    opacity: 0.3;
    cursor: default;
}
.nvt-guest-count {
    font-size: 15px;
    font-weight: 600;
    min-width: 24px;
    text-align: center;
}

/* ----- Child ages ----- */

.nvt-child-ages {
    margin-top: 8px;
    padding: 10px 12px;
    background: var(--nvt-bg-light, #f5f5f5);
    border-radius: 6px;
}
.nvt-child-ages-header {
    font-size: 12px;
    font-weight: 600;
    color: var(--nvt-text, #1a1a1a);
    margin-bottom: 4px;
}
.nvt-child-ages-message {
    font-size: 11px;
    color: var(--nvt-text-light, #6b6b6b);
    margin-bottom: 8px;
}
.nvt-child-age-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.nvt-child-age-row label {
    font-size: 12px;
    color: var(--nvt-text, #1a1a1a);
    min-width: 60px;
}
.nvt-child-age-select {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid var(--nvt-border, #e0e0e0);
    border-radius: 4px;
    font-size: 13px;
    color: var(--nvt-text, #1a1a1a);
    background: var(--nvt-bg, #ffffff);
}
.nvt-age-error .nvt-child-age-select {
    border-color: var(--nvt-danger, #d32f2f);
    background: #fff5f5;
}

/* ----- Smart age anchor (top of guest picker) ----- */

.nvt-smart-age-anchor {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 14px;
    margin-bottom: 12px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: var(--nvt-radius, 8px);
    color: #991b1b;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s;
}
.nvt-smart-age-anchor::before {
    content: '!';
    flex: 0 0 20px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #ef4444;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}
.nvt-smart-age-anchor:hover {
    background: #fee2e2 !important;
    text-decoration: underline;
}

/* ----- Highlight animation on target age select ----- */

@keyframes nvt-age-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
    40%  { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.3); }
    70%  { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}
.nvt-age-highlight {
    animation: nvt-age-pulse 0.8s ease-out 2;
    border-color: #ef4444 !important;
    background: #fef2f2 !important;
}

/* ----- Add room button ----- */

.nvt-add-room-btn {
    display: block;
    width: 100%;
    padding: 10px;
    margin-top: 12px;
    border: 2px dashed var(--nvt-border, #e0e0e0);
    border-radius: var(--nvt-radius, 8px);
    background: transparent;
    color: var(--nvt-primary, #003580);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
}
.nvt-add-room-btn:hover {
    border-color: var(--nvt-border, #e0e0e0) !important;
    background: transparent !important;
}

/* ----- Done button inside guest popup ----- */

.nvt-guest-popup .nvt-done-btn {
    margin-top: 16px;
    width: 100%;
}

/* ---------- Calendar nav bar (prev/next buttons) ---------- */

.nvt-calendar-nav-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

/* ---------- Homepage location input ---------- */

.nvt-field--location {
    flex: 1.5;
}
.nvt-field-input--location {
    padding: 0;
}
.nvt-homepage-input {
    border: none;
    outline: none;
    width: 100%;
    padding: 12px 14px;
    font-size: 14px;
    font-family: inherit;
    background: transparent;
}

/* ---------- Search loading spinner ---------- */

.nvt-search-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 12px;
    padding: 16px;
    font-size: 14px;
    color: var(--nvt-text-light, #6b6b6b);
}
.nvt-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid var(--nvt-border, #e0e0e0);
    border-top-color: var(--nvt-primary, #003580);
    border-radius: 50%;
    animation: nvt-spin 0.7s linear infinite;
}
@keyframes nvt-spin {
    to { transform: rotate(360deg); }
}

/* ---------- Fetch error message ---------- */

.nvt-fetch-error {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 8px 12px;
    font-size: 13px;
    color: var(--nvt-danger, #d32f2f);
    background: #fef2f2;
    border-radius: var(--nvt-radius, 8px);
}
.nvt-warning-icon {
    flex: 0 0 20px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--nvt-danger, #d32f2f);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

/* ---------- Error boundary fallback ---------- */

.nvt-error-boundary {
    padding: 20px;
    text-align: center;
    color: var(--nvt-danger, #d32f2f);
    font-size: 14px;
}

/* ======================================================================
   Responsive
   ====================================================================== */

/* Tablet: stack calendar months, scale button text */
@media (max-width: 1024px) {
    .nvt-calendar-months {
        flex-direction: column;
        gap: 20px;
    }
    .nvt-field--date {
        position: static;
    }
    .nvt-calendar-popup {
        min-width: 280px;
        max-width: calc(100vw - 24px);
    }
    .nvt-validation-message {
        white-space: normal;
    }
    .nvt-btn-search {
        font-size: 15px;
        padding: 10px 24px;
    }
}

@media (max-width: 768px) {
    .nvt-form-row {
        flex-direction: column;
        border-radius: var(--nvt-radius, 8px);
    }
    .nvt-field--btn {
        flex: 0 0 auto;
        min-width: 0;
        padding: 0;
    }
    .nvt-btn-search {
        width: 100%;
        border-radius: 4px;
        font-size: 15px;
        padding: 10px 20px;
    }
    .nvt-calendar-popup {
        min-width: 100%;
        left: 0;
        right: 0;
    }
    .nvt-guest-popup {
        left: 0;
        right: 0;
        min-width: 0;
        width: 100%;
    }
}
`;

    const style = document.createElement('style');
    style.setAttribute('data-novoton', 'booking-engine');
    style.textContent = css;
    document.head.appendChild(style);
}
