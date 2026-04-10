/**
 * Travel Core — SEO Template Click-to-Insert
 *
 * Shared JS for the enhanced SEO template editor in addon settings.
 * Initializes on elements with [data-seo-wrapper] attribute.
 *
 * Features:
 * - Click a .seo-ph-badge to insert {{placeholder}} at cursor
 * - Click a .seo-mod-badge to append |modifier to nearest placeholder
 * - Green flash on insertion for visual feedback
 */
(function () {
    document.querySelectorAll('[data-seo-wrapper]').forEach(function (wrapper) {
        var section = wrapper.closest('.addon-settings-seo_templates') || wrapper.parentNode;
        var lastField = null;
        var lastPos = 0;

        section.addEventListener('focus', function (e) {
            if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
                lastField = e.target;
                lastPos = e.target.selectionStart || 0;
            }
        }, true);

        section.addEventListener('click', function (e) {
            if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
                lastField = e.target;
                setTimeout(function () { lastPos = e.target.selectionStart || 0; }, 0);
            }
        }, true);

        section.addEventListener('keyup', function (e) {
            if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text')) {
                lastField = e.target;
                lastPos = e.target.selectionStart || 0;
            }
        }, true);

        function insertAtCursor(text) {
            if (!lastField) {
                lastField = section.querySelector('input[type="text"], textarea');
                if (!lastField) return;
                lastPos = lastField.value.length;
            }

            lastField.focus();
            var val = lastField.value;
            var selStart = lastField.selectionStart;
            var selEnd = lastField.selectionEnd;

            lastField.value = val.substring(0, selStart) + text + val.substring(selEnd);

            var newPos = selStart + text.length;
            lastField.selectionStart = newPos;
            lastField.selectionEnd = newPos;
            lastPos = newPos;

            lastField.style.transition = 'background-color 0.15s';
            lastField.style.backgroundColor = '#d4edda';
            setTimeout(function () { lastField.style.backgroundColor = ''; }, 300);
        }

        wrapper.addEventListener('click', function (e) {
            var badge = e.target.closest('.seo-ph-badge');
            if (badge) {
                e.preventDefault();
                insertAtCursor(badge.getAttribute('data-insert'));
                return;
            }

            var mod = e.target.closest('.seo-mod-badge');
            if (mod) {
                e.preventDefault();
                var modName = mod.getAttribute('data-modifier');
                if (!lastField) return;

                var val = lastField.value;
                var pos = lastField.selectionStart || lastPos;
                var before = val.substring(0, pos);

                var openIdx = before.lastIndexOf('{{');
                if (openIdx === -1) { insertAtCursor('|' + modName); return; }

                var closeIdx = val.indexOf('}}', openIdx);
                if (closeIdx === -1) { insertAtCursor('|' + modName); return; }

                var tokenContent = val.substring(openIdx + 2, closeIdx);
                if (tokenContent.indexOf('|' + modName) !== -1) return;

                lastField.selectionStart = closeIdx;
                lastField.selectionEnd = closeIdx;
                insertAtCursor('|' + modName);
            }
        });
    });
})();
