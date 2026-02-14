# Novoton Booking Engine – React Source

Full source code for the `react19-bundle.js` booking engine widget.

## Quick Start

```bash
cd react-src
npm install
npm run build   # production build → js/addons/novoton_holidays/react19-bundle.js
npm run dev     # development watch mode
```

## Project Structure

```
src/
├── index.jsx           Entry point, auto-init, window.NovotonBooking API
├── BookingEngine.jsx   Main form component (date + guest + search)
├── Calendar.jsx        Date range picker (two-month view)
├── GuestPicker.jsx     Room / guest configuration popup
├── icons.jsx           SVG icon components
├── utils.js            Date parsing, formatting, locale detection
├── styles.js           CSS (injected at runtime via <style>)
└── translations.js     Month / weekday name arrays (en + ro)
```

## Build Output

`npm run build` produces `js/addons/novoton_holidays/react19-bundle.js` (the file loaded by CS-Cart).

The bundle includes React 19, ReactDOM 19 and all custom components in a
single self-executing IIFE — no external dependencies at runtime.

## DOM Mount Points

The widget auto-mounts on these element IDs:

| ID | Mode | Used on |
|----|------|---------|
| `novoton-booking-root` | product | Hotel product page |
| `novoton-search-form-root` | search | Search results |
| `novoton-homepage-form-root` | homepage | Homepage |

Configuration is read from `data-*` attributes on those elements.

## Public API

After initialisation, `window.NovotonBooking` exposes:

- `init()` – re-run auto-mounting
- `BookingEngine` – React component
- `Calendar` – React component
- `GuestPicker` – React component
- `getLocale()` – returns `"en"` or `"ro"`
- `parseDate(str)` – parse `"YYYY-MM-DD"` → `Date`
- `toDateString(date)` – format `Date` → `"YYYY-MM-DD"`

## Translations

UI strings are read from `window.NovotonTranslations` (injected by Smarty
templates via the CS-Cart language system). Calendar month/weekday names
have built-in English and Romanian support.
