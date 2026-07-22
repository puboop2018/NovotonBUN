/**
 * Novoton search results page — "More info" room-details modal.
 *
 * Extracted from search.tpl's inline <script> so vitest can import the REAL
 * file (tests/js/novoton-search-results.test.mjs). The open/close functions
 * are window globals because the result cards invoke them from inline
 * onclick attributes.
 */

// Copies the per-row hidden details into the shared modal and shows it.
window.openInfoModal = function (rowId) {
    var content = document.getElementById('modal-content-' + rowId);
    if (content) {
        document.getElementById('info-modal-content').innerHTML = content.innerHTML;
        document.getElementById('info-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
};

window.closeInfoModal = function () {
    var modal = document.getElementById('info-modal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
};

(function () {
    function init() {
        var modal = document.getElementById('info-modal');
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            if (e.target === this) window.closeInfoModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.closeInfoModal();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
