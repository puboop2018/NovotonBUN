/**
 * Novoton Holidays - Resort Exclusion Manager
 *
 * Handles filtering, selection, and management of excluded resorts
 * in the admin dashboard.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */
(function () {
    'use strict';

    var searchInput, countryFilter, resortForm;

    function init() {
        searchInput = document.getElementById('resort-search');
        countryFilter = document.getElementById('country-filter');
        resortForm = document.getElementById('excluded-resorts-form');

        if (!resortForm) {
            return;
        }

        // Bind search and filter events
        if (searchInput) {
            searchInput.addEventListener('keyup', filterResorts);
        }
        if (countryFilter) {
            countryFilter.addEventListener('change', filterResorts);
        }

        // Bind select/deselect all buttons
        var selectAllBtn = document.getElementById('btn-select-all-visible');
        var deselectAllBtn = document.getElementById('btn-deselect-all-visible');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', selectAllVisible);
        }
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', deselectAllVisible);
        }

        // Bind country-level select/deselect links via event delegation
        resortForm.addEventListener('click', function (e) {
            var target = e.target;
            if (target.hasAttribute('data-select-country')) {
                e.preventDefault();
                selectCountry(target.getAttribute('data-select-country'));
            } else if (target.hasAttribute('data-deselect-country')) {
                e.preventDefault();
                deselectCountry(target.getAttribute('data-deselect-country'));
            }
        });

        // Bind checkbox change events for live count
        resortForm.addEventListener('change', function (e) {
            if (e.target.type === 'checkbox' && e.target.name === 'excluded_resorts[]') {
                updateExcludedCount();
            }
        });
    }

    function filterResorts() {
        var search = searchInput ? searchInput.value.toLowerCase() : '';
        var country = countryFilter ? countryFilter.value : '';
        var items = document.querySelectorAll('.resort-item');
        var groups = document.querySelectorAll('.novoton-country-group');
        var visibleCount = 0;
        var visibleGroups = {};

        items.forEach(function (item) {
            var resortName = item.getAttribute('data-resort');
            var resortCountry = item.getAttribute('data-country');
            var matchSearch = !search || resortName.indexOf(search) !== -1;
            var matchCountry = !country || resortCountry === country;

            if (matchSearch && matchCountry) {
                item.style.display = 'inline-flex';
                visibleCount++;
                visibleGroups[resortCountry] = true;
            } else {
                item.style.display = 'none';
            }
        });

        groups.forEach(function (group) {
            var groupCountry = group.getAttribute('data-country');
            group.style.display = visibleGroups[groupCountry] ? 'block' : 'none';
        });

        var visibleCountEl = document.getElementById('visible-count');
        if (visibleCountEl) {
            visibleCountEl.textContent = '(Showing ' + visibleCount + ' resorts)';
        }

        var noResults = document.getElementById('no-results');
        var container = document.getElementById('resorts-container');
        if (noResults) {
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
        if (container) {
            container.style.display = visibleCount === 0 ? 'none' : 'block';
        }
    }

    function selectAllVisible() {
        document.querySelectorAll('.resort-item').forEach(function (item) {
            if (item.style.display !== 'none') {
                item.querySelector('input[type="checkbox"]').checked = true;
            }
        });
        updateExcludedCount();
    }

    function deselectAllVisible() {
        document.querySelectorAll('.resort-item').forEach(function (item) {
            if (item.style.display !== 'none') {
                item.querySelector('input[type="checkbox"]').checked = false;
            }
        });
        updateExcludedCount();
    }

    function selectCountry(country) {
        document.querySelectorAll('.resort-item[data-country="' + country + '"]').forEach(function (item) {
            if (item.style.display !== 'none') {
                item.querySelector('input[type="checkbox"]').checked = true;
            }
        });
        updateExcludedCount();
    }

    function deselectCountry(country) {
        document.querySelectorAll('.resort-item[data-country="' + country + '"]').forEach(function (item) {
            item.querySelector('input[type="checkbox"]').checked = false;
        });
        updateExcludedCount();
    }

    function updateExcludedCount() {
        var count = document.querySelectorAll('#excluded-resorts-form input[name="excluded_resorts[]"]:checked').length;
        var el = document.getElementById('excluded-count');
        if (el) {
            el.textContent = count;
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
