/**
 * Eurosite destination picker — feeds the shared travel_core booking engine.
 *
 * The engine has no destination fields of its own; it merges a JSON map from
 * the mount element's data-extra-params into the search URL at search time
 * (additive travel_core contract). This script keeps that attribute in sync
 * with the country/city selects and filters the city options per country.
 *
 * Delegated listeners: the engine's inline-results mode replaces the whole
 * .travel-search-results-page node on every search, so nothing may bind to
 * the swapped elements directly.
 */
(function () {
    'use strict';

    function syncExtraParams() {
        var country = document.getElementById('eurosite-country');
        var city = document.getElementById('eurosite-city');
        var mount = document.querySelector('[data-travel-booking][data-provider="eurosite"]');
        if (!mount) { return; }
        mount.dataset.extraParams = JSON.stringify({
            country: country ? country.value : '',
            city: city ? city.value : ''
        });
    }

    function filterCities(countryCode, resetCity) {
        var city = document.getElementById('eurosite-city');
        if (!city) { return; }
        Array.prototype.forEach.call(city.options, function (opt) {
            if (!opt.value) { return; }
            var match = opt.getAttribute('data-country') === countryCode;
            opt.hidden = !match;
            if (!match && opt.selected && resetCity) { opt.selected = false; }
        });
        if (resetCity) { city.value = ''; }
        syncExtraParams();
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'eurosite-country') {
            filterCities(e.target.value, true);
        } else if (e.target && e.target.id === 'eurosite-city') {
            syncExtraParams();
        }
    });

    // Initial sync (direct page load with preselected values) — and again
    // after every inline results swap re-renders the selects.
    function init() { syncExtraParams(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('travel:results-swapped', init);
    // The engine re-executes same-origin scripts on swap; a fresh execution
    // of this file re-runs init() via the branch above.
})();
