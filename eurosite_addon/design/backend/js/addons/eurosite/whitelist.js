// Eurosite — destination whitelist page (sphinx whitelist UX).
//
// Lives as a real file (loaded via {script src=...}) instead of an inline
// <script> block: CS-Cart's backend rewrites inline scripts into
// {inline_script} Smarty blocks for CSP nonces, and that rewrite chokes on
// a script body wrapped in {literal}. All server-side values ride as data
// attributes on #eurosite-whitelist-data, so this file is plain JS.
//
// Model: state[cc] = { all: bool, cities: Set<code> } — mirrors the saved
// whitelist on load and is the single source of truth for badges, the
// summary panel, the whitelisted-only filter and the Save payload.
(function () {
    function init() {
        var dataEl = document.getElementById('eurosite-whitelist-data');
        if (!dataEl) {
            return; // not on the whitelist page
        }

        var citiesUrl = dataEl.getAttribute('data-cities-url') || '';
        var searchUrl = dataEl.getAttribute('data-search-url') || '';
        var txt = {
            loading: dataEl.getAttribute('data-txt-loading') || 'Loading...',
            noCities: dataEl.getAttribute('data-txt-no-cities') || 'No cities found.',
            failed: dataEl.getAttribute('data-txt-failed') || 'Request failed.',
            allBadge: dataEl.getAttribute('data-txt-all-badge') || 'ALL CITIES',
            selected: dataEl.getAttribute('data-txt-selected') || 'selected',
            shown: dataEl.getAttribute('data-txt-shown') || 'shown',
            confirmRemove: dataEl.getAttribute('data-txt-confirm-remove') || 'Remove all whitelisted destinations?',
            search: dataEl.getAttribute('data-txt-search') || 'Search country or city...'
        };

        // ── State ──
        var state = {};          // cc -> { all: bool, cities: Set }
        var cityLists = {};      // cc -> [ { code, name, is_own } ] once loaded
        var filterOn = false;

        var saved = {};
        try { saved = JSON.parse(dataEl.getAttribute('data-whitelist') || '{}'); } catch (e) { saved = {}; }
        Object.keys(saved).forEach(function (cc) {
            state[cc] = {
                all: !!saved[cc].all,
                cities: new Set(saved[cc].all ? [] : (saved[cc].cities || []))
            };
        });

        function esc(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text == null ? '' : String(text)));
            return div.innerHTML;
        }

        function row(cc) { return document.getElementById('eurosite-wl-row-' + cc); }
        function q(sel) { return document.querySelector(sel); }
        function qa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
        function countryCb(cc) { return q('.eurosite-country-all[data-country="' + cc + '"]'); }
        function selectAllCb(cc) { return q('.eurosite-select-all[data-country="' + cc + '"]'); }
        function cityBox(cc) { return q('.eurosite-city-box[data-country="' + cc + '"]'); }
        function cityGrid(cc) { return q('.eurosite-city-grid[data-country="' + cc + '"]'); }

        function countryName(cc) {
            var r = row(cc);
            if (!r) { return cc; }
            var label = r.querySelector('label');
            return label ? label.textContent.trim() : cc;
        }

        // ── Badges / summary / filter ──
        function updateBadge(cc) {
            var badge = q('.eurosite-wl-badge[data-country="' + cc + '"]');
            if (!badge) { return; }
            if (!state[cc]) {
                badge.innerHTML = '';
            } else if (state[cc].all) {
                badge.innerHTML = '<span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px;">' + esc(txt.allBadge) + '</span>';
            } else {
                badge.innerHTML = '<span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px;">' + state[cc].cities.size + ' ' + esc(txt.selected) + '</span>';
            }
        }

        function updateSummary() {
            var ccs = Object.keys(state);
            var cityTotal = 0;
            var detail = '';
            ccs.sort().forEach(function (cc) {
                var line;
                if (state[cc].all) {
                    line = cityLists[cc] ? cityLists[cc].length + ' (' + txt.allBadge.toLowerCase() + ')' : txt.allBadge.toLowerCase();
                    if (cityLists[cc]) { cityTotal += cityLists[cc].length; }
                } else {
                    line = String(state[cc].cities.size);
                    cityTotal += state[cc].cities.size;
                }
                detail += '<div style="margin:6px 0; padding:6px 0; border-bottom:1px solid #eee; font-size:12px;">'
                    + '<strong>' + esc(countryName(cc)) + '</strong><br/>'
                    + '<span style="color:#888;">' + esc(line) + '</span></div>';
            });
            var elC = document.getElementById('eurosite-wl-summary-countries');
            var elCi = document.getElementById('eurosite-wl-summary-cities');
            var elD = document.getElementById('eurosite-wl-summary-detail');
            if (elC) { elC.textContent = String(ccs.length); }
            if (elCi) { elCi.textContent = String(cityTotal); }
            if (elD) { elD.innerHTML = detail; }
        }

        function applyFilter() {
            var rows = qa('.eurosite-country-row');
            var shown = 0;
            rows.forEach(function (r) {
                var cc = r.getAttribute('data-country');
                var visible = !filterOn || !!state[cc];
                r.style.display = visible ? '' : 'none';
                if (visible) { shown++; }
                // Inside open grids, hide unchecked cities while the filter is on
                if (visible && filterOn) {
                    qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (cb) {
                        cb.closest('label').style.display = cb.checked ? '' : 'none';
                    });
                } else {
                    qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (cb) {
                        cb.closest('label').style.display = '';
                    });
                }
            });
            var countEl = document.getElementById('eurosite-wl-filter-count');
            if (countEl) {
                countEl.textContent = filterOn ? shown + ' / ' + rows.length + ' ' + txt.shown : '';
            }
        }

        // ── City grid (lazy) ──
        function renderCities(cc) {
            var grid = cityGrid(cc);
            if (!grid) { return; }
            var st = state[cc];
            var html = '';
            (cityLists[cc] || []).forEach(function (city) {
                var checked = st && (st.all || st.cities.has(city.code)) ? ' checked' : '';
                var own = city.is_own ? ' <span class="label label-info" title="own offers">own</span>' : '';
                html += '<label style="display:inline-flex; align-items:center; gap:3px; min-width:200px; font-size:12px; color:#444; cursor:pointer;">'
                    + '<input type="checkbox" class="eurosite-city" data-country="' + esc(cc) + '" value="' + esc(city.code) + '"' + checked + '> '
                    + '<span>' + esc(city.name) + ' <code>' + esc(city.code) + '</code>' + own + '</span></label>';
            });
            grid.innerHTML = html || '<span class="muted" style="font-size:12px;">' + esc(txt.noCities) + '</span>';
            qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (cb) {
                cb.addEventListener('change', function () { onCityToggle(cc, cb); });
            });
            if (filterOn) { applyFilter(); }
        }

        function loadCities(cc, done) {
            if (cityLists[cc]) { if (done) { done(); } return; }
            var grid = cityGrid(cc);
            if (grid) { grid.innerHTML = '<span class="muted" style="font-size:12px;">' + esc(txt.loading) + '</span>'; }
            fetch(citiesUrl + '&country=' + encodeURIComponent(cc), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        if (grid) { grid.innerHTML = '<span class="text-error">' + esc(data.error || 'error') + '</span>'; }
                        return;
                    }
                    cityLists[cc] = data.cities || [];
                    renderCities(cc);
                    if (done) { done(); }
                })
                .catch(function () {
                    if (grid) { grid.innerHTML = '<span class="text-error">' + esc(txt.failed) + '</span>'; }
                });
        }

        function expand(cc, forceOpen) {
            var box = cityBox(cc);
            if (!box) { return; }
            var isOpen = box.style.display !== 'none';
            if (isOpen && !forceOpen) { box.style.display = 'none'; return; }
            box.style.display = 'block';
            loadCities(cc);
        }

        // ── Selection handlers (sphinx semantics) ──
        function onCountryToggle(cc, cb) {
            if (cb.checked) {
                state[cc] = { all: true, cities: new Set() };
                if (selectAllCb(cc)) { selectAllCb(cc).checked = true; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = true; });
            } else {
                delete state[cc];
                if (selectAllCb(cc)) { selectAllCb(cc).checked = false; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = false; });
            }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        function onSelectAllToggle(cc, cb) {
            if (cb.checked) {
                state[cc] = { all: true, cities: new Set() };
                if (countryCb(cc)) { countryCb(cc).checked = true; }
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = true; });
            } else {
                state[cc] = { all: false, cities: new Set() };
                qa('.eurosite-city[data-country="' + cc + '"]').forEach(function (c) { c.checked = false; });
                delete state[cc];
                if (countryCb(cc)) { countryCb(cc).checked = false; }
            }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        function onCityToggle(cc, cb) {
            if (!state[cc]) {
                state[cc] = { all: false, cities: new Set() };
            }
            if (state[cc].all) {
                // Leaving "all": keep exactly what is checked in the grid now.
                state[cc] = { all: false, cities: new Set() };
                qa('.eurosite-city[data-country="' + cc + '"]:checked').forEach(function (c) {
                    state[cc].cities.add(c.value);
                });
                if (selectAllCb(cc)) { selectAllCb(cc).checked = false; }
            } else if (cb.checked) {
                state[cc].cities.add(cb.value);
            } else {
                state[cc].cities.delete(cb.value);
            }
            // Everything checked -> promote to "all" (sphinx behaviour)
            if (cityLists[cc] && cityLists[cc].length > 0 && state[cc].cities.size >= cityLists[cc].length) {
                state[cc] = { all: true, cities: new Set() };
                if (selectAllCb(cc)) { selectAllCb(cc).checked = true; }
            }
            // Nothing left -> country drops off the whitelist
            if (!state[cc].all && state[cc].cities.size === 0) {
                delete state[cc];
            }
            if (countryCb(cc)) { countryCb(cc).checked = !!state[cc]; }
            updateBadge(cc);
            updateSummary();
            applyFilter();
        }

        // ── Wire static elements ──
        qa('.eurosite-expand').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                expand(el.getAttribute('data-country'), false);
            });
        });
        qa('.eurosite-country-all').forEach(function (cb) {
            cb.addEventListener('change', function () { onCountryToggle(cb.getAttribute('data-country'), cb); });
        });
        qa('.eurosite-select-all').forEach(function (cb) {
            cb.addEventListener('change', function () { onSelectAllToggle(cb.getAttribute('data-country'), cb); });
        });

        var filterCb = document.getElementById('eurosite-wl-filter');
        if (filterCb) {
            filterCb.addEventListener('change', function () {
                filterOn = filterCb.checked;
                applyFilter();
            });
        }

        // ── Save / Remove all ──
        var saveBtn = document.getElementById('eurosite-wl-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var entries = [];
                Object.keys(state).forEach(function (cc) {
                    if (state[cc].all) {
                        entries.push({ country_code: cc, city_code: '', selection_type: 'all' });
                    } else {
                        state[cc].cities.forEach(function (code) {
                            entries.push({ country_code: cc, city_code: code, selection_type: 'specific' });
                        });
                    }
                });
                document.getElementById('eurosite-whitelist-json').value = JSON.stringify(entries);
                document.getElementById('eurosite-whitelist-form').submit();
            });
        }

        var removeBtn = document.getElementById('eurosite-wl-remove-all');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (!window.confirm(txt.confirmRemove)) { return; }
                state = {};
                qa('.eurosite-country-all, .eurosite-select-all, .eurosite-city').forEach(function (cb) { cb.checked = false; });
                qa('.eurosite-wl-badge').forEach(function (b) { b.innerHTML = ''; });
                updateSummary();
                applyFilter();
            });
        }

        // ── Select2 search (country + city) ──
        function jumpToCountry(cc, flash) {
            var r = row(cc);
            if (!r) { return; }
            if (filterOn && !state[cc]) {
                // make it visible even under the filter
                r.style.display = '';
            }
            r.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (flash) {
                var prev = r.style.backgroundColor;
                r.style.backgroundColor = '#fff3cd';
                setTimeout(function () { r.style.backgroundColor = prev; }, 1600);
            }
        }

        function handleSearchPick(item) {
            if (!item || !item.country_code) { return; }
            var cc = item.country_code;
            if (item.type === 'city' && item.city_code) {
                expand(cc, true);
                loadCities(cc, function () {
                    var cb = q('.eurosite-city[data-country="' + cc + '"][value="' + item.city_code + '"]');
                    if (cb && !cb.checked && (!state[cc] || !state[cc].all)) {
                        cb.checked = true;
                        onCityToggle(cc, cb);
                    }
                    jumpToCountry(cc, true);
                });
            } else {
                expand(cc, true);
                jumpToCountry(cc, true);
            }
        }

        if (typeof window.$ !== 'undefined' && window.$.fn && typeof window.$.fn.select2 !== 'undefined') {
            var $sel = window.$('#eurosite-wl-search');
            $sel.select2({
                ajax: {
                    url: searchUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { q: params.term }; },
                    processResults: function (data) { return { results: data.results || [] }; },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: txt.search,
                allowClear: true,
                width: '100%'
            });
            $sel.on('select2:select', function (e) {
                handleSearchPick(e.params.data);
                $sel.val(null).trigger('change');
            });
        }

        // ── Initial paint ──
        updateSummary();
        Object.keys(state).forEach(updateBadge);
    }

    // The admin panel may inject this page after DOMContentLoaded (AJAX
    // navigation) — run immediately when the DOM is already there.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
