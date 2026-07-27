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
    // Document-delegated on purpose: the inline-results swap REPLACES the
    // modal node on every search (and on product pages the modal only
    // arrives with the first swapped-in results), so an element-bound
    // backdrop handler would die with the old node.
    document.addEventListener('click', function (e) {
        var modal = document.getElementById('info-modal');
        if (modal && e.target === modal) window.closeInfoModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeInfoModal();
    });
})();
