/**
 * Travel Core — SEO Template Editor
 *
 * Shared client-side behaviour for the dedicated SEO template admin
 * pages in both sphinx_holidays and novoton_holidays.
 *
 * Responsibilities (all off-by-default unless the page opts in via
 * data-* attributes):
 *
 *   1. Click-to-insert
 *      Click a .seo-ph-badge inside any [data-seo-wrapper] to insert
 *      {{placeholder}} at the last focused input/textarea cursor.
 *      Click a .seo-mod-badge to append |modifier inside the nearest
 *      {{placeholder}} before the cursor.
 *
 *   2. Live template preview
 *      Any <input>/<textarea> paired with a matching
 *      <div class="seo-preview" data-seo-preview-for="<input_id>">
 *      gets its rendered value (template → mock data) written into
 *      the preview element on every input event. The mock dictionary
 *      is supplied by the host page via window.__seoMockData.
 *
 *   3. Character counter + SEO length hints
 *      An <input>/<textarea> with data-seo-ideal / data-seo-max
 *      attributes and a matching <div class="seo-counter"
 *      data-seo-counter-for="<input_id>"> shows live "X / N chars"
 *      with green (under ideal) / amber (over ideal) / red (over max).
 *      Counts the RENDERED output (what Google sees), not the
 *      template source.
 *
 *   4. Disabled field visual
 *      A checkbox with data-seo-toggle="<input_id>" toggles a
 *      .seo-field-off class on the enclosing .control-group so the
 *      CSS can fade the input + label + preview when the admin has
 *      opted out of that field during bulk-apply.
 *
 * Filename kept as seo-click-insert.js for backwards compatibility
 * with the existing {script src=} includes in both SEO admin pages.
 */
(function () {
    function init() {
    var marker = document.querySelector('[data-seo-wrapper]')
        || document.querySelector('[data-seo-preview-for]');
    if (!marker) {
        return; // Nothing SEO-related on this page.
    }
    // Guard against double-binding: CS-Cart fires both DOMContentLoaded and
    // ce.commoninit (the latter on every AJAX content load), so init() can run
    // more than once against the same DOM. Mark the container once it's wired.
    if (marker.__seoInit) {
        return;
    }
    marker.__seoInit = true;

    // ════════════════════════════════════════════════════════════════
    // 1. Click-to-insert
    // ════════════════════════════════════════════════════════════════

    var lastField = null;
    var lastSelStart = 0;
    var lastSelEnd = 0;

    function isEditableTarget(el) {
        if (!el || !el.tagName) return false;
        if (el.tagName === 'TEXTAREA') return true;
        if (el.tagName === 'INPUT') {
            var t = (el.type || '').toLowerCase();
            return t === 'text' || t === '' || t === 'search' || t === 'url' || t === 'email';
        }
        return false;
    }

    function savePosition(el) {
        if (isEditableTarget(el)) {
            lastField    = el;
            lastSelStart = el.selectionStart || 0;
            lastSelEnd   = el.selectionEnd   || 0;
        }
    }

    // Capture selection on every meaningful interaction with editable fields.
    // 'select' and 'input' keep the saved caret accurate as the user types or
    // drags a selection; 'mouseup' covers click-to-reposition inside the field.
    document.addEventListener('focus',   function (e) { savePosition(e.target); }, true);
    document.addEventListener('click',   function (e) { savePosition(e.target); }, true);
    document.addEventListener('keyup',   function (e) { savePosition(e.target); }, true);
    document.addEventListener('mouseup', function (e) { savePosition(e.target); }, true);
    document.addEventListener('select',  function (e) { savePosition(e.target); }, true);
    document.addEventListener('input',   function (e) { savePosition(e.target); }, true);

    /**
     * Resolve the field a badge click should target.
     * Prefer the field that still holds focus (when the badge handler ran on
     * mousedown+preventDefault the field never blurred, so its live caret is
     * authoritative); otherwise fall back to the last field we tracked.
     */
    function resolveTargetField() {
        var active = document.activeElement;
        if (isEditableTarget(active)) {
            lastField    = active;
            lastSelStart = active.selectionStart || 0;
            lastSelEnd   = active.selectionEnd   || 0;
        }
        return lastField;
    }

    function flashField(field) {
        if (!field) return;
        var prevBg = field.style.backgroundColor;
        field.style.transition = 'background-color 0.15s';
        field.style.backgroundColor = '#d4edda';
        setTimeout(function () { field.style.backgroundColor = prevBg; }, 300);
    }

    // Insert `text` at the currently-tracked caret (lastField + lastSel*).
    // Callers are responsible for resolving the target field/caret first:
    // placeholder badges call resolveTargetField() (live caret); appendModifier
    // sets the caret deliberately to the end of the nearest token.
    function insertAtCursor(text) {
        if (!lastField) {
            // Scope fallback to the containing form so we don't accidentally
            // target the admin search box or other unrelated inputs.
            var wrapper = document.querySelector('[data-seo-wrapper]');
            var scope   = (wrapper && wrapper.closest('form')) || document;
            lastField   = scope.querySelector('input[type="text"], input:not([type]), textarea');
            if (!lastField) return;
            lastSelStart = lastField.value.length;
            lastSelEnd   = lastField.value.length;
        }

        // Use the cursor position saved at last blur/focus — NOT selectionStart
        // after re-focusing, which browsers reset to 0 when focus was lost.
        var selStart = lastSelStart;
        var selEnd   = lastSelEnd;
        var val      = lastField.value;

        lastField.value = val.substring(0, selStart) + text + val.substring(selEnd);

        var newPos = selStart + text.length;
        lastField.focus();
        lastField.selectionStart = newPos;
        lastField.selectionEnd   = newPos;

        // Keep saved position in sync.
        lastSelStart = newPos;
        lastSelEnd   = newPos;

        // Trigger downstream listeners (live preview, counter, etc.)
        lastField.dispatchEvent(new Event('input', { bubbles: true }));
        flashField(lastField);
    }

    function appendModifier(modName) {
        resolveTargetField();
        if (!lastField) return;

        var val = lastField.value;
        var pos = lastSelStart || val.length;
        var before = val.substring(0, pos);

        var openIdx = before.lastIndexOf('{{');
        if (openIdx === -1) { insertAtCursor('|' + modName); return; }

        var closeIdx = val.indexOf('}}', openIdx);
        if (closeIdx === -1) { insertAtCursor('|' + modName); return; }

        var tokenContent = val.substring(openIdx + 2, closeIdx);
        if (tokenContent.indexOf('|' + modName) !== -1) return; // already applied

        lastSelStart = closeIdx;
        lastSelEnd   = closeIdx;
        insertAtCursor('|' + modName);
    }

    // Suppress the default mousedown action on a badge so clicking it does NOT
    // blur (and reset the caret of) the SEO field the admin is editing. This is
    // what lets the insert land in the field that currently has focus — Meta
    // description, Meta keywords, etc. — instead of falling back to the first
    // field after focus is lost.
    function preserveFocusOnMousedown(el) {
        el.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
    }

    function bindBadgeClick(badge) {
        preserveFocusOnMousedown(badge);
        badge.addEventListener('click', function (e) {
            // Stop CS-Cart admin panel jQuery from swallowing the event.
            e.preventDefault();
            e.stopPropagation();
            // Resolve the focused field + its live caret before inserting.
            resolveTargetField();
            insertAtCursor(badge.getAttribute('data-insert') || '');
        });
    }

    function bindModClick(mod) {
        preserveFocusOnMousedown(mod);
        mod.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            appendModifier(mod.getAttribute('data-modifier') || '');
        });
    }

    // ════════════════════════════════════════════════════════════════
    // 2. Live template preview — render template against mock data
    // ════════════════════════════════════════════════════════════════

    var mockData = window.__seoMockData || {};

    function applyModifier(value, modifier) {
        var v = String(value);
        switch ((modifier || '').toLowerCase()) {
            case 'lower': return v.toLowerCase();
            case 'upper': return v.toUpperCase();
            case 'title':
                return v.replace(/\w\S*/g, function (t) {
                    return t.charAt(0).toUpperCase() + t.substr(1).toLowerCase();
                });
            case 'capitalize':
                return v ? v.charAt(0).toUpperCase() + v.substr(1) : '';
            case 'trim': return v.trim();
            case 'slug':
                return v.toLowerCase()
                    .replace(/[^a-z0-9\-]+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
            case 'first': return v.charAt(0);
            case 'last':  return v.charAt(v.length - 1);
            case 'abs':   return String(Math.abs(parseFloat(v) || 0));
            case 'round': return String(Math.round(parseFloat(v) || 0));
            case 'strip_tags': return v.replace(/<[^>]*>/g, '');
            default: return v;
        }
    }

    var TOKEN_RE = /\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?\}\}/g;

    function renderTemplate(template) {
        if (!template) return '';
        var out = template.replace(TOKEN_RE, function (_full, key, mod) {
            var value = mockData[key];
            if (Array.isArray(value)) {
                // Mirror PHP fn_travel_core_render_seo_template behaviour:
                // arrays are joined as comma-separated, first 3 items.
                value = value.slice(0, 3).join(', ');
            }
            if (value === undefined || value === null) value = '';
            if (mod) value = applyModifier(String(value), mod);
            return value;
        });
        // Collapse whitespace runs and trim.
        return out.replace(/\s+/g, ' ').trim();
    }

    function updatePreview(input) {
        var preview = document.querySelector(
            '.seo-preview[data-seo-preview-for="' + input.id + '"]'
        );
        var rendered = renderTemplate(input.value);

        if (preview) {
            if (rendered) {
                preview.textContent = rendered;
                preview.classList.remove('seo-preview-empty');
            } else {
                preview.textContent = '(empty)';
                preview.classList.add('seo-preview-empty');
            }
        }

        updateCounter(input, rendered);
    }

    // ════════════════════════════════════════════════════════════════
    // 3. Character counter — counts RENDERED output, not template src
    // ════════════════════════════════════════════════════════════════

    function updateCounter(input, renderedText) {
        var counter = document.querySelector(
            '.seo-counter[data-seo-counter-for="' + input.id + '"]'
        );
        if (!counter) return;

        var ideal = parseInt(input.getAttribute('data-seo-ideal') || '0', 10);
        var max   = parseInt(input.getAttribute('data-seo-max')   || '0', 10);
        var len   = (renderedText || '').length;

        var status = 'ok';
        if (max && len > max)        status = 'over';
        else if (ideal && len > ideal) status = 'warn';

        counter.className = 'seo-counter seo-counter-' + status;

        var label = len + ' chars';
        if (max)        label += ' (max ' + max + ')';
        else if (ideal) label += ' (ideal ~' + ideal + ')';

        counter.textContent = label;
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Disabled field visual
    // ════════════════════════════════════════════════════════════════

    function syncFieldEnabled(checkbox) {
        var group = checkbox.closest('.control-group');
        if (!group) return;
        group.classList.toggle('seo-field-off', !checkbox.checked);
    }

    // ════════════════════════════════════════════════════════════════
    // Init — wire DOM → behaviours
    // ════════════════════════════════════════════════════════════════

    // Bind badge clicks directly — avoid document delegation which CS-Cart
    // jQuery can swallow via stopPropagation on admin pages.
    document.querySelectorAll('[data-seo-wrapper] .seo-ph-badge').forEach(bindBadgeClick);
    document.querySelectorAll('[data-seo-wrapper] .seo-mod-badge').forEach(bindModClick);

    // Live previews + counters
    document.querySelectorAll('.seo-preview[data-seo-preview-for]').forEach(function (preview) {
        var input = document.getElementById(preview.getAttribute('data-seo-preview-for'));
        if (!input) return;
        input.addEventListener('input', function () { updatePreview(input); });
        updatePreview(input); // initial render
    });

    // Counters for inputs that have no preview (counter-only fields)
    document.querySelectorAll('.seo-counter[data-seo-counter-for]').forEach(function (counter) {
        var input = document.getElementById(counter.getAttribute('data-seo-counter-for'));
        if (!input) return;
        // If the input also has a preview we already bound an 'input' listener there.
        if (document.querySelector('.seo-preview[data-seo-preview-for="' + input.id + '"]')) return;
        input.addEventListener('input', function () {
            updateCounter(input, renderTemplate(input.value));
        });
        updateCounter(input, renderTemplate(input.value));
    });

    // Per-field enable/disable checkboxes
    document.querySelectorAll('input[type="checkbox"][data-seo-toggle]').forEach(function (cb) {
        cb.addEventListener('change', function () { syncFieldEnabled(cb); });
        syncFieldEnabled(cb);
    });
    } // end init

    // CS-Cart admin navigates via AJAX: clicking "SEO Templates" in the top nav
    // injects the page into an already-loaded document, so DOMContentLoaded never
    // fires again and the external script's IIFE does not re-run. Hook CS-Cart's
    // ce.commoninit event, which fires after every AJAX content load, so the badges
    // and live preview wire up regardless of how the page was reached. The
    // __seoInit guard in init() keeps repeated calls idempotent.
    var Tygh = window.Tygh;
    if (Tygh && Tygh.$ && typeof Tygh.$.ceEvent === 'function') {
        Tygh.$.ceEvent('on', 'ce.commoninit', function () { init(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
