# Novoton Holidays Addon - Changelog

## v2.8.0-A80D

Base release: `8c11d20` novoton_holidays_v2.8.0_A80D

### 0fddefe - Remove unused React code and fix duplicate script loading

- Deleted `js/addons/novoton_holidays/booking-form.jsx` — unused development artifact, never referenced by any template. Actual React components are compiled inside `react19-bundle.js`.
- Deleted `js/addons/novoton_holidays/lazy-loader.js` — dead code. Waited for `window.React`/`window.ReactDOM` globals that the minified bundle never exports; no template used its `data-nvt-lazy` trigger attribute.
- Removed `react19-bundle.js` from global `scripts.post.tpl` (both `nova_theme` and `responsive` themes) — it was loading the 218KB bundle on every page despite already being loaded by individual booking templates (`booking_engine.tpl`, `homepage_booking.tpl`, `search.tpl`).
- Removed unused `formatPrice()` and `calculateNights()` from `booking_engine.js` — defined inside the IIFE but never called; better implementations already exist in `utils.js`.

### 393eb7e - Fix schema mismatch, commented-out styles, and JS bugs

- Fixed `init.php` sync_log table schema: was creating columns `started_at`/`finished_at` that no query in the codebase references. All queries use `sync_date`. On a fresh install this would cause every sync log operation to fail.
- Fixed `{style}` tag trapped inside Smarty comment blocks in three `booking_engine.tpl` files (`nova_theme/blocks`, `responsive/blocks`, `responsive/static_templates`). The CSS was never loaded from these templates.
- Fixed `clearDOBError()` in `dob-validation.js`: could not find error elements when field had no `id` or `name` attribute, because `showDOBError()` generated a random fallback ID that `clearDOBError()` could not reproduce.
- Fixed `updateFormWithNewPricing()` in `booking-form-validation.js`: `data.new_price` was written to the hidden input without an undefined check, which would set the value to the literal string `"undefined"`.
