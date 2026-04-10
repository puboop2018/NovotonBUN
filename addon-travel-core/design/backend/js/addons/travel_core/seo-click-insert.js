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
    if (!document.querySelector('[data-seo-wrapper], [data-seo-preview-for]')) {
        return; // Nothing SEO-related on this page.
    }

    // ════════════════════════════════════════════════════════════════
    // 1. Click-to-insert
    // ════════════════════════════════════════════════════════════════

    var lastField = null;

    function isEditableTarget(el) {
        if (!el || !el.tagName) return false;
        if (el.tagName === 'TEXTAREA') return true;
        if (el.tagName === 'INPUT') {
            var t = (el.type || '').toLowerCase();
            return t === 'text' || t === '' || t === 'search' || t === 'url' || t === 'email';
        }
        return false;
    }

    function rememberField(el) {
        if (isEditableTarget(el)) { lastField = el; }
    }

    document.addEventListener('focus', function (e) { rememberField(e.target); }, true);
    document.addEventListener('click', function (e) { rememberField(e.target); }, true);
    document.addEventListener('keyup', function (e) { rememberField(e.target); }, true);

    function flashField(field) {
        if (!field) return;
        var prevBg = field.style.backgroundColor;
        field.style.transition = 'background-color 0.15s';
        field.style.backgroundColor = '#d4edda';
        setTimeout(function () { field.style.backgroundColor = prevBg; }, 300);
    }

    function insertAtCursor(text) {
        if (!lastField) {
            lastField = document.querySelector('input[type="text"], input:not([type]), textarea');
            if (!lastField) return;
            lastField.selectionStart = lastField.selectionEnd = lastField.value.length;
        }

        lastField.focus();
        var val = lastField.value;
        var selStart = lastField.selectionStart || 0;
        var selEnd = lastField.selectionEnd || 0;

        lastField.value = val.substring(0, selStart) + text + val.substring(selEnd);

        var newPos = selStart + text.length;
        lastField.selectionStart = newPos;
        lastField.selectionEnd = newPos;

        // Trigger downstream listeners (live preview, counter, etc.)
        lastField.dispatchEvent(new Event('input', { bubbles: true }));
        flashField(lastField);
    }

    function appendModifier(modName) {
        if (!lastField) return;

        var val = lastField.value;
        var pos = lastField.selectionStart || val.length;
        var before = val.substring(0, pos);

        var openIdx = before.lastIndexOf('{{');
        if (openIdx === -1) { insertAtCursor('|' + modName); return; }

        var closeIdx = val.indexOf('}}', openIdx);
        if (closeIdx === -1) { insertAtCursor('|' + modName); return; }

        var tokenContent = val.substring(openIdx + 2, closeIdx);
        if (tokenContent.indexOf('|' + modName) !== -1) return; // already applied

        lastField.selectionStart = closeIdx;
        lastField.selectionEnd = closeIdx;
        insertAtCursor('|' + modName);
    }

    document.addEventListener('click', function (e) {
        var badge = e.target.closest && e.target.closest('.seo-ph-badge');
        if (badge && badge.closest('[data-seo-wrapper]')) {
            e.preventDefault();
            insertAtCursor(badge.getAttribute('data-insert') || '');
            return;
        }
        var mod = e.target.closest && e.target.closest('.seo-mod-badge');
        if (mod && mod.closest('[data-seo-wrapper]')) {
            e.preventDefault();
            appendModifier(mod.getAttribute('data-modifier') || '');
        }
    });

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
})();
