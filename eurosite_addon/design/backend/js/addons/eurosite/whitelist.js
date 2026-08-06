// Eurosite — destination whitelist page.
//
// Lives as a real file (loaded via {script src=...}) instead of an inline
// <script> block: CS-Cart's backend rewrites inline scripts into
// {inline_script} Smarty blocks for CSP nonces, and that rewrite chokes on
// a script body wrapped in {literal}. All server-side values ride as data
// attributes on #eurosite-whitelist-data, so this file is plain JS.
(function () {
    function init() {
        var dataEl = document.getElementById('eurosite-whitelist-data');
        if (!dataEl) {
            return; // not on the whitelist page
        }
        var whitelist = {};
        try { whitelist = JSON.parse(dataEl.getAttribute('data-whitelist') || '{}'); } catch (e) { whitelist = {}; }
        var citiesUrl = dataEl.getAttribute('data-cities-url') || '';
        var txt = {
            loading: dataEl.getAttribute('data-txt-loading') || 'Loading...',
            noCities: dataEl.getAttribute('data-txt-no-cities') || 'No cities found.',
            failed: dataEl.getAttribute('data-txt-failed') || 'Request failed.'
        };
        var cityCache = {};

        function esc(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text == null ? '' : String(text)));
            return div.innerHTML;
        }

        function cityBox(cc) {
            return document.querySelector('.eurosite-city-box[data-country="' + cc + '"]');
        }

        function renderCities(cc, cities) {
            var selected = (whitelist[cc] && whitelist[cc].cities) || [];
            var box = cityBox(cc);
            var html = '';
            cities.forEach(function (city) {
                var checked = selected.indexOf(city.code) !== -1 ? ' checked' : '';
                var own = city.is_own ? ' <span class="label label-info" title="own offers">own</span>' : '';
                html += '<label class="checkbox inline" style="min-width: 240px;">'
                    + '<input type="checkbox" class="eurosite-city" data-country="' + esc(cc) + '" value="' + esc(city.code) + '"' + checked + '> '
                    + esc(city.name) + ' <code>' + esc(city.code) + '</code>' + own + '</label> ';
            });
            box.innerHTML = html || '<span class="muted">' + esc(txt.noCities) + '</span>';
        }

        document.querySelectorAll('.eurosite-expand').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var cc = link.getAttribute('data-country');
                var box = cityBox(cc);
                if (box.style.display !== 'none') { box.style.display = 'none'; return; }
                box.style.display = 'block';
                if (cityCache[cc]) { return; }
                box.innerHTML = '<span class="muted">' + esc(txt.loading) + '</span>';
                fetch(citiesUrl + '&country=' + encodeURIComponent(cc), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { box.innerHTML = '<span class="text-error">' + esc(data.error || 'error') + '</span>'; return; }
                        cityCache[cc] = true;
                        renderCities(cc, data.cities);
                    })
                    .catch(function () { box.innerHTML = '<span class="text-error">' + esc(txt.failed) + '</span>'; });
            });
        });

        document.getElementById('eurosite-whitelist-form').addEventListener('submit', function () {
            var entries = [];
            document.querySelectorAll('.eurosite-country-all').forEach(function (cb) {
                var cc = cb.getAttribute('data-country');
                if (cb.checked) {
                    entries.push({ country_code: cc, city_code: '', selection_type: 'all' });
                    return;                                  // whole country wins
                }
                var boxChecked = document.querySelectorAll('.eurosite-city[data-country="' + cc + '"]:checked');
                if (boxChecked.length) {
                    boxChecked.forEach(function (cityCb) {
                        entries.push({ country_code: cc, city_code: cityCb.value, selection_type: 'specific' });
                    });
                } else if (!cityCache[cc] && whitelist[cc] && !whitelist[cc].all && whitelist[cc].cities) {
                    // country never expanded this session — keep its saved cities
                    whitelist[cc].cities.forEach(function (code) {
                        entries.push({ country_code: cc, city_code: code, selection_type: 'specific' });
                    });
                }
            });
            document.getElementById('eurosite-whitelist-json').value = JSON.stringify(entries);
        });
    }

    // The admin panel may inject this page after DOMContentLoaded (AJAX
    // navigation) — run immediately when the DOM is already there.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
