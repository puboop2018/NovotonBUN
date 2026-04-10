/**
 * Travel Core — SEO Template Click-to-Insert
 *
 * Shared JS for the enhanced SEO template editor.
 * Initializes once per page and attaches global listeners.
 *
 * Features:
 * - Tracks the last focused text input / textarea anywhere on the page
 * - Click a .seo-ph-badge (inside any [data-seo-wrapper]) to insert
 *   {{placeholder}} at the cursor of the last focused field
 * - Click a .seo-mod-badge to append |modifier inside the nearest
 *   {{placeholder}} before the cursor (or standalone if none found)
 * - Brief green flash on the target field to confirm the insertion
 *
 * Why global listeners instead of scoping to the wrapper's parent:
 *   The dedicated SEO templates admin pages embed the sidebar beside
 *   the form in a two-column layout. Scoping focus tracking to the
 *   sidebar's parent node worked on the legacy tab layout but is
 *   fragile when the markup changes. Using document-level capture
 *   handlers is robust across both layouts and any future refactors.
 */
(function () {
    if (!document.querySelector('[data-seo-wrapper]')) {
        return; // No SEO sidebar on this page — nothing to do.
    }

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
        if (isEditableTarget(el)) {
            lastField = el;
        }
    }

    // Track the last focused text-ish field across the whole document.
    // Focus events don't bubble, so we use capture phase.
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
            // Fallback: first editable field on the page. Better UX than
            // doing nothing when the admin hasn't focused anything yet.
            lastField = document.querySelector(
                'input[type="text"], input:not([type]), textarea'
            );
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

        // Fire an input event so any listeners (live preview, validation)
        // see the change. Native assignment doesn't dispatch one.
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

        // Move cursor just before the }} then insert the modifier.
        lastField.selectionStart = closeIdx;
        lastField.selectionEnd = closeIdx;
        insertAtCursor('|' + modName);
    }

    // Global click handler: look for badges anywhere inside a
    // [data-seo-wrapper] ancestor.
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
})();
