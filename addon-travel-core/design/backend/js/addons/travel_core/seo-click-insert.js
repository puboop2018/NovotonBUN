/**
 * Travel Core — SEO Template Editor
 *
 * Shared client-side behaviour for the dedicated SEO template admin
 * pages in both sphinx_holidays and novoton_holidays.
 *
 * Responsibility:
 *
 *   Disabled field visual
 *      A checkbox with data-seo-toggle="<input_id>" toggles a
 *      .seo-field-off class on the enclosing .control-group so the
 *      CSS can fade the input + label when the admin has opted out
 *      of that field during bulk-apply.
 *
 * The placeholder / modifier panel on the right is a static reference
 * only — the badges show the literal token to type (e.g. {{name}},
 * |lower). There is no click-to-insert behaviour.
 *
 * Filename kept as seo-click-insert.js for backwards compatibility
 * with the existing {script src=} includes in both SEO admin pages.
 */
(function () {
    function init() {
        var marker = document.querySelector('[data-seo-wrapper]');
        if (!marker) {
            return;
        }
        // Guard against double-binding: CS-Cart fires both DOMContentLoaded and
        // ce.commoninit (the latter on every AJAX content load), so init() can
        // run more than once against the same DOM. Mark the container once it's
        // wired.
        if (marker.__seoInit) {
            return;
        }
        marker.__seoInit = true;

        // Disabled field visual — fade fields the admin has opted out of.
        function syncFieldEnabled(checkbox) {
            var group = checkbox.closest('.control-group');
            if (!group) {
                return;
            }
            group.classList.toggle('seo-field-off', !checkbox.checked);
        }

        document.querySelectorAll('input[type="checkbox"][data-seo-toggle]').forEach(function (cb) {
            cb.addEventListener('change', function () { syncFieldEnabled(cb); });
            syncFieldEnabled(cb);
        });
    } // end init

    // CS-Cart admin navigates via AJAX: clicking "SEO Templates" in the top nav
    // injects the page into an already-loaded document, so DOMContentLoaded never
    // fires again and the external script's IIFE does not re-run. Hook CS-Cart's
    // ce.commoninit event, which fires after every AJAX content load, so the
    // checkboxes wire up regardless of how the page was reached. The __seoInit
    // guard in init() keeps repeated calls idempotent.
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
