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
    --nvt-primary: #003580;
    --nvt-primary-light: #0057b8;
    --nvt-accent: #ffb700;
    --nvt-yellow: #febb02;
    --nvt-text: #1a1a1a;
    --nvt-text-light: #6b6b6b;
    --nvt-border: #e0e0e0;
    --nvt-bg: #ffffff;
    --nvt-bg-light: #f5f5f5;
    --nvt-error: #d32f2f;
    --nvt-radius: 8px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--nvt-text);
    position: relative;
}

/* ---------- Availability header ---------- */

.nvt-availability-header {
    margin-bottom: 12px;
}
.nvt-availability-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--nvt-primary);
    margin: 0 0 4px;
}
.nvt-availability-subtitle {
    font-size: 13px;
    color: var(--nvt-error);
    margin: 0;
}

/* ---------- Form row ---------- */

.nvt-form-row {
    display: flex;
    align-items: stretch;
    gap: 0;
    border: 3px solid var(--nvt-yellow);
    border-radius: var(--nvt-radius);
    background: var(--nvt-bg);
    overflow: visible;
    position: relative;
}

.nvt-field {
    position: relative;
    flex: 1;
    border-right: 1px solid var(--nvt-border);
}
.nvt-field:last-child {
    border-right: none;
}
.nvt-field--date {
    flex: 2;
}
.nvt-field--guests {
    flex: 1.5;
}
.nvt-field--btn {
    flex: 0 0 auto;
    border-right: none;
}

.nvt-field-input {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    cursor: pointer;
    min-height: 48px;
    background: transparent;
    border: none;
    width: 100%;
    text-align: left;
    font: inherit;
    color: inherit;
    -webkit-user-select: text;
    user-select: text;
}
.nvt-field:hover {
    background: transparent !important;
    background-color: transparent !important;
}
.nvt-field-input:hover {
    background: transparent !important;
    background-color: transparent !important;
    border-color: inherit !important;
}

.nvt-field-input-icon {
    flex: 0 0 20px;
    color: var(--nvt-text-light);
    display: flex;
    align-items: center;
}
.nvt-field-input-text {
    flex: 1;
    min-width: 0;
}
.nvt-field-input-text .nvt-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--nvt-text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.nvt-field-input-text .nvt-value {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--nvt-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    -webkit-user-select: text;
    user-select: text;
    cursor: text;
}
.nvt-field-input-text .nvt-value--placeholder {
    color: var(--nvt-text-light);
}
.nvt-field-input-arrow {
    flex: 0 0 16px;
    color: var(--nvt-text-light);
    display: flex;
    align-items: center;
}

/* ---------- Search button ---------- */

.nvt-btn-search {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 21px 42px;
    background: #006ce4;
    color: #fff;
    border: none;
    border-left: 3px solid var(--nvt-yellow);
    font-size: 26px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    min-height: 84px;
    transition: background 0.15s;
}
.nvt-btn-search:hover {
    background: #006ce4;
}

/* ---------- Validation message ---------- */

.nvt-validation-message {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 8px 12px;
    font-size: 13px;
    color: var(--nvt-error);
    background: #fef2f2;
    border-radius: var(--nvt-radius);
}
.nvt-warning-icon {
    flex: 0 0 20px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--nvt-error);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

/* ======================================================================
   Calendar popup
   ====================================================================== */

.nvt-calendar-popup {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 1000;
    background: var(--nvt-bg);
    border-radius: var(--nvt-radius);
    box-shadow: 0 4px 24px rgba(0,0,0,0.15);
    padding: 20px;
    min-width: 600px;
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
    color: var(--nvt-text);
}
.nvt-calendar-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--nvt-border);
    background: var(--nvt-bg);
    cursor: pointer;
    color: var(--nvt-text);
    padding: 0;
}
.nvt-calendar-nav:hover {
    background: var(--nvt-bg);
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
    color: var(--nvt-text-light);
    margin-bottom: 4px;
}

.nvt-calendar-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
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
    color: var(--nvt-text);
    padding: 0;
    transition: background 0.1s;
}
.nvt-calendar-day:hover {
    background: transparent;
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
    background: var(--nvt-primary) !important;
    color: #fff !important;
    font-weight: 600;
}
.nvt-calendar-day--in-range {
    background: #e8f0fe;
    border-radius: 0;
}
.nvt-calendar-day--empty {
    visibility: hidden;
}

.nvt-calendar-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--nvt-border);
    font-size: 13px;
    color: var(--nvt-text-light);
}

.nvt-done-btn {
    padding: 8px 20px;
    background: var(--nvt-primary);
    color: #fff;
    border: none;
    border-radius: var(--nvt-radius);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.nvt-done-btn:hover {
    background: var(--nvt-primary);
}

/* ======================================================================
   Guest / Room picker popup
   ====================================================================== */

.nvt-guest-popup {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 1000;
    background: var(--nvt-bg);
    border-radius: var(--nvt-radius);
    box-shadow: 0 4px 24px rgba(0,0,0,0.15);
    padding: 20px;
    min-width: 340px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

.nvt-guest-rooms-container {
    flex: 1;
    overflow-y: auto;
    padding-right: 8px;
    margin-right: -8px;
}

.nvt-room-section {
    padding-bottom: 16px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--nvt-border);
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
    color: var(--nvt-text);
}
.nvt-remove-room {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    color: #b71c1c;
    cursor: pointer;
    background: #ffebee;
    border: 1px solid #ef9a9a;
    border-radius: 4px;
    padding: 4px 10px;
    transition: background 0.15s, border-color 0.15s;
}
.nvt-remove-room:hover {
    background: #ffebee;
    border-color: #ef9a9a;
    color: #b71c1c;
}

.nvt-guest-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.nvt-guest-label {
    font-size: 13px;
    color: var(--nvt-text);
}
.nvt-guest-label small {
    display: block;
    font-size: 11px;
    color: var(--nvt-text-light);
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
    border: 1px solid var(--nvt-border);
    background: var(--nvt-bg);
    font-size: 18px;
    cursor: pointer;
    color: var(--nvt-primary);
    padding: 0;
    transition: background 0.1s;
}
.nvt-guest-btn:hover {
    background: var(--nvt-bg);
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
    background: var(--nvt-bg-light);
    border-radius: 6px;
}
.nvt-child-ages-header {
    font-size: 12px;
    font-weight: 600;
    color: var(--nvt-text);
    margin-bottom: 4px;
}
.nvt-child-ages-message {
    font-size: 11px;
    color: var(--nvt-text-light);
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
    color: var(--nvt-text);
    min-width: 60px;
}
.nvt-child-age-select {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid var(--nvt-border);
    border-radius: 4px;
    font-size: 13px;
    color: var(--nvt-text);
    background: var(--nvt-bg);
}
.nvt-age-error .nvt-child-age-select {
    border-color: var(--nvt-error);
    background: #fff5f5;
}

/* ----- Missing ages alert ----- */

.nvt-missing-ages-alert {
    display: block;
    width: 100%;
    padding: 10px 14px;
    margin-top: 12px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: var(--nvt-radius);
    color: #856404;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    transition: background 0.15s;
}
.nvt-missing-ages-alert:hover {
    background: #fff3cd;
}

/* ----- Add room button ----- */

.nvt-add-room-btn {
    display: block;
    width: 100%;
    padding: 10px;
    margin-top: 12px;
    border: 2px dashed var(--nvt-border);
    border-radius: var(--nvt-radius);
    background: transparent;
    color: var(--nvt-primary);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
}
.nvt-add-room-btn:hover {
    border-color: var(--nvt-border);
    background: transparent;
}

/* ----- Child age hint below guest picker ----- */

.nvt-child-age-hint {
    font-size: 11px;
    color: var(--nvt-error);
    padding: 2px 14px;
    white-space: nowrap;
}

/* ----- Done button inside guest popup ----- */

.nvt-guest-popup .nvt-done-btn {
    margin-top: 16px;
    width: 100%;
}

/* ======================================================================
   Responsive
   ====================================================================== */

@media (max-width: 768px) {
    .nvt-form-row {
        flex-direction: column;
        border-radius: var(--nvt-radius);
    }
    .nvt-field {
        border-right: none;
        border-bottom: 1px solid var(--nvt-border);
    }
    .nvt-field:last-child {
        border-bottom: none;
    }
    .nvt-field--btn {
        flex: 0 0 auto;
    }
    .nvt-btn-search {
        width: 100%;
        border-left: none;
        border-top: 3px solid var(--nvt-yellow);
        border-radius: 0 0 var(--nvt-radius) var(--nvt-radius);
    }
    .nvt-calendar-popup {
        min-width: 100%;
        left: 0;
        right: 0;
    }
    .nvt-calendar-months {
        flex-direction: column;
        gap: 20px;
    }
}
`;

    const style = document.createElement('style');
    style.setAttribute('data-novoton', 'booking-engine');
    style.textContent = css;
    document.head.appendChild(style);
}
