{capture name="mainbox"}

<div class="travel-admin-panel">

    {if $total_destinations == 0}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_destinations_synced")}
        </div>
    {else}

    {* ── Stats Bar ── *}
    <div class="sync-stats" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.continent|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.continents")}</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value">{$counts_by_type.country|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.countries")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.region|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.regions")}</div>
        </div>
    </div>

    <p class="muted" style="margin-bottom: 15px;">{__("sphinx_holidays.whitelist_description")}</p>

    <div style="display: flex; gap: 30px; align-items: flex-start;">

        {* ── Left: Country Tree ── *}
        <div style="flex: 1; min-width: 0;">

            {* Search *}
            <div style="margin-bottom: 12px; position: relative;">
                <input type="text" id="wl_search" class="input-large"
                       placeholder="{__("sphinx_holidays.search_destinations")}"
                       style="width: 100%; box-sizing: border-box;" autocomplete="off" />
                <div id="wl_search_results" style="display:none; position:absolute; z-index:100; left:0; right:0; top:100%;
                     background:#fff; border:1px solid #ccc; border-top:none; border-radius:0 0 6px 6px;
                     max-height:400px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>
            </div>

            {* Country List *}
            <form id="whitelist_form" method="post" action="{"sphinx_holidays.save_whitelist"|fn_url}">
            <input type="hidden" name="security_hash" value="{$security_hash}" />

            <div id="wl_country_list">
                {foreach from=$countries item=c name=cloop}
                <div class="wl-country" data-country-id="{$c.destination_id}" data-cc="{$c.country_code}" data-name="{$c.name|lower}">
                    <div style="display: flex; align-items: center; padding: 6px 0; border-bottom: 1px solid #eee;">
                        {* Expand arrow *}
                        <span class="wl-expand" style="cursor:pointer; width:20px; text-align:center; color:#999; user-select:none;"
                              onclick="toggleCountryExpand({$c.destination_id})">&#9654;</span>

                        {* Country checkbox *}
                        <label style="margin: 0 8px 0 0; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" class="wl-country-cb" value="{$c.destination_id}"
                                   {if $c.is_whitelisted}checked{/if}
                                   onchange="onCountryToggle(this, {$c.destination_id})" />
                            {$c.name}
                        </label>

                        {* Badge *}
                        <span class="wl-badge" id="wl_badge_{$c.destination_id}" style="font-size:11px; padding:2px 8px; border-radius:3px; font-weight:bold;">
                            {if $c.is_whitelisted && $c.selection_type === 'all'}
                                <span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px;">{__("sphinx_holidays.select_all_children")|upper}</span>
                            {elseif $c.is_whitelisted && $c.whitelisted_child_count > 0}
                                <span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px;">{$c.whitelisted_child_count} {__("sphinx_holidays.specific_regions")}</span>
                            {/if}
                        </span>
                    </div>

                    {* Expandable children area (hidden by default) *}
                    <div class="wl-children" id="wl_children_{$c.destination_id}" style="display:none; padding: 8px 0 8px 28px; background: #fafafa; border-bottom: 1px solid #eee;">
                        <div style="margin-bottom: 8px;">
                            <label style="cursor:pointer; font-size:12px; color:#555;">
                                <input type="checkbox" class="wl-select-all-children" data-country="{$c.destination_id}"
                                       {if $c.is_whitelisted && $c.selection_type === 'all'}checked{/if}
                                       onchange="onSelectAllChildren(this, {$c.destination_id})" />
                                <strong>{__("sphinx_holidays.select_all")}</strong>
                            </label>
                        </div>
                        <div class="wl-children-grid" id="wl_grid_{$c.destination_id}">
                            <span class="muted" style="font-size:12px;">Loading...</span>
                        </div>
                    </div>
                </div>
                {/foreach}
            </div>

            {* Hidden inputs will be built by JS before submit *}
            <div id="wl_hidden_inputs"></div>

            </form>
        </div>

        {* ── Right: Summary Panel ── *}
        <div style="width: 280px; flex-shrink: 0; border: 1px solid #ddd; border-radius: 6px; padding: 20px; background: #fafafa; position: sticky; top: 80px;">
            <h4 style="margin-top: 0;">{__("sphinx_holidays.whitelist_summary")}</h4>

            <div style="margin-bottom: 12px;">
                <strong>{__("sphinx_holidays.whitelisted_countries")}:</strong>
                <span id="wl_summary_countries">{$whitelisted_country_count|default:0}</span>
            </div>

            <div style="margin-bottom: 12px;">
                <strong>{__("sphinx_holidays.whitelisted_regions")}:</strong>
                <span id="wl_summary_regions">{$whitelisted_region_count|default:0}</span>
                {if $sample_cities}
                    <br><small class="muted" id="wl_summary_sample">(e.g., {', '|implode:$sample_cities}...)</small>
                {/if}
            </div>

            <button type="button" class="btn btn-primary" onclick="submitWhitelist()" style="width: 100%;">
                <i class="icon-ok"></i> {__("sphinx_holidays.save_whitelist")}
            </button>
        </div>

    </div>

    {/if}
</div>

<script>
(function() {
    // State: { countryId: { type: 'all'|'specific', children: Set<int> } }
    var state = {ldelim}{rdelim};
    var loadedTree = {ldelim}{rdelim};    // countryId => [ { destination_id, name, type, children: [...] } ]
    var loadedFlat = {ldelim}{rdelim};    // countryId => [ { destination_id, name, type } ] (flat list)
    var treeUrl = '{"sphinx_holidays.get_destinations_tree"|fn_url}';
    var searchUrl = '{"sphinx_holidays.search_destinations"|fn_url}';
    var searchTimeout = null;

    // Initialize state from server data
    {foreach from=$countries item=c}
    {if $c.is_whitelisted}
    state[{$c.destination_id}] = {ldelim}
        type: '{$c.selection_type}',
        children: new Set()
    {rdelim};
    {/if}
    {/foreach}

    {literal}

    // ─── Country expand/collapse ───

    window.toggleCountryExpand = function(countryId) {
        var el = document.getElementById('wl_children_' + countryId);
        var arrow = el.previousElementSibling.querySelector('.wl-expand');
        if (el.style.display === 'none') {
            el.style.display = 'block';
            arrow.innerHTML = '&#9660;';
            loadChildren(countryId);
        } else {
            el.style.display = 'none';
            arrow.innerHTML = '&#9654;';
        }
    };

    function loadChildren(countryId) {
        if (loadedTree[countryId]) {
            renderChildren(countryId);
            return;
        }
        var countryEl = document.querySelector('[data-country-id="' + countryId + '"]');
        var cc = countryEl ? countryEl.dataset.cc : '';
        if (!cc) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', treeUrl + '&country_code=' + encodeURIComponent(cc));
        xhr.onload = function() {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);
                var tree = [];
                var flat = [];
                (data.tree || []).forEach(function(region) {
                    var regionItem = {
                        destination_id: parseInt(region.destination_id),
                        name: region.name,
                        type: region.type || 'region',
                        children: []
                    };
                    flat.push({ destination_id: regionItem.destination_id, name: regionItem.name, type: regionItem.type });
                    (region.children || []).forEach(function(city) {
                        var cityItem = {
                            destination_id: parseInt(city.destination_id),
                            name: city.name,
                            type: city.type || 'city'
                        };
                        regionItem.children.push(cityItem);
                        flat.push(cityItem);
                    });
                    tree.push(regionItem);
                });
                loadedTree[countryId] = tree;
                loadedFlat[countryId] = flat;
                renderChildren(countryId);
            }
        };
        xhr.send();
    }

    // ─── Hierarchical render: regions as headers, cities grouped beneath ───

    function renderChildren(countryId) {
        var grid = document.getElementById('wl_grid_' + countryId);
        var tree = loadedTree[countryId] || [];
        if (tree.length === 0) {
            grid.innerHTML = '<span class="muted" style="font-size:12px;">No regions/cities found</span>';
            return;
        }
        var isAll = state[countryId] && state[countryId].type === 'all';
        var selectedChildren = state[countryId] ? state[countryId].children : new Set();

        var html = '';
        tree.forEach(function(region) {
            var regionId = region.destination_id;
            // Determine if all cities in this region are selected
            var allCitiesSelected = isAll;
            if (!isAll && region.children.length > 0) {
                allCitiesSelected = region.children.every(function(c) {
                    return selectedChildren.has(c.destination_id);
                }) && selectedChildren.has(regionId);
            }
            var regionChecked = isAll || selectedChildren.has(regionId) ? ' checked' : '';

            // Region header
            html += '<div class="wl-region-block" style="margin-bottom:12px; width:100%;">';
            html += '<div style="display:flex; align-items:center; gap:6px; padding:4px 8px; background:#e8f4fd; border-left:3px solid #2196F3; border-radius:0 4px 4px 0; margin-bottom:4px;">';
            html += '<input type="checkbox" class="wl-child-cb wl-region-cb" value="' + regionId + '" ' +
                'data-country="' + countryId + '" data-region-id="' + regionId + '"' + regionChecked +
                ' onchange="onRegionToggle(this,' + countryId + ',' + regionId + ')" />';
            html += '<span style="font-weight:600; font-size:13px; color:#1565C0;">' + region.name + '</span>';
            html += '<span style="font-size:10px; padding:1px 6px; background:#2196F3; color:#fff; border-radius:3px; text-transform:uppercase;">Region</span>';
            if (region.children.length > 0) {
                html += '<span style="font-size:11px; color:#888; margin-left:auto;">' + region.children.length + ' cities</span>';
            }
            html += '</div>';

            // Cities under this region
            if (region.children.length > 0) {
                html += '<div style="display:flex; flex-wrap:wrap; gap:3px 14px; padding-left:24px;">';
                region.children.forEach(function(city) {
                    var cityChecked = isAll || selectedChildren.has(city.destination_id) ? ' checked' : '';
                    html += '<label style="display:inline-flex; align-items:center; gap:3px; min-width:160px; font-size:12px; color:#444; cursor:pointer;">' +
                        '<input type="checkbox" class="wl-child-cb wl-city-cb" value="' + city.destination_id + '" ' +
                        'data-country="' + countryId + '" data-region="' + regionId + '"' + cityChecked +
                        ' onchange="onChildToggle(this,' + countryId + ',' + city.destination_id + ')" />' +
                        '<span style="color:#666;">' + city.name + '</span></label>';
                });
                html += '</div>';
            }

            html += '</div>';
        });
        grid.innerHTML = html;
    }

    // ─── Region toggle: select/deselect all cities in region ───

    window.onRegionToggle = function(cb, countryId, regionId) {
        if (!state[countryId]) {
            state[countryId] = { type: 'specific', children: new Set() };
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
        }

        var region = findRegionInTree(countryId, regionId);
        if (!region) return;

        if (cb.checked) {
            // Add region + all its cities
            state[countryId].children.add(regionId);
            region.children.forEach(function(city) {
                state[countryId].children.add(city.destination_id);
            });
            // Check all city checkboxes
            var cityCbs = document.querySelectorAll('.wl-city-cb[data-region="' + regionId + '"]');
            cityCbs.forEach(function(c) { c.checked = true; });
        } else {
            // Remove region + all its cities
            state[countryId].children.delete(regionId);
            region.children.forEach(function(city) {
                state[countryId].children.delete(city.destination_id);
            });
            state[countryId].type = 'specific';
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
            // Uncheck all city checkboxes
            var cityCbs = document.querySelectorAll('.wl-city-cb[data-region="' + regionId + '"]');
            cityCbs.forEach(function(c) { c.checked = false; });
        }

        checkAllChildrenSelected(countryId);
        updateBadge(countryId);
        updateSummary();
    };

    function findRegionInTree(countryId, regionId) {
        var tree = loadedTree[countryId] || [];
        for (var i = 0; i < tree.length; i++) {
            if (tree[i].destination_id === regionId) return tree[i];
        }
        return null;
    }

    function updateRegionCheckboxState(countryId, regionId) {
        var region = findRegionInTree(countryId, regionId);
        if (!region || region.children.length === 0) return;
        var selectedChildren = state[countryId] ? state[countryId].children : new Set();
        var allSelected = region.children.every(function(c) {
            return selectedChildren.has(c.destination_id);
        });
        var regionCb = document.querySelector('.wl-region-cb[data-region-id="' + regionId + '"]');
        if (regionCb) {
            regionCb.checked = allSelected;
            if (allSelected) {
                state[countryId].children.add(regionId);
            }
        }
    }

    // ─── Country toggle ───

    window.onCountryToggle = function(cb, countryId) {
        if (cb.checked) {
            state[countryId] = { type: 'all', children: new Set() };
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = true;
            if (loadedTree[countryId]) {
                var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
                childCbs.forEach(function(c) { c.checked = true; });
            }
        } else {
            delete state[countryId];
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = false; });
        }
        updateBadge(countryId);
        updateSummary();
    };

    window.onSelectAllChildren = function(cb, countryId) {
        var countryCb = document.querySelector('.wl-country-cb[value="' + countryId + '"]');
        if (cb.checked) {
            countryCb.checked = true;
            state[countryId] = { type: 'all', children: new Set() };
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = true; });
        } else {
            state[countryId] = { type: 'specific', children: new Set() };
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = false; });
        }
        updateBadge(countryId);
        updateSummary();
    };

    // ─── City toggle ───

    window.onChildToggle = function(cb, countryId, childId) {
        if (!state[countryId]) {
            state[countryId] = { type: 'specific', children: new Set() };
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
        }

        if (cb.checked) {
            state[countryId].children.add(childId);
        } else {
            state[countryId].children.delete(childId);
            state[countryId].type = 'specific';
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = false;
        }

        // Update region checkbox state based on whether all its cities are checked
        var regionAttr = cb.getAttribute('data-region');
        if (regionAttr) {
            updateRegionCheckboxState(countryId, parseInt(regionAttr));
        }

        checkAllChildrenSelected(countryId);

        // If no children selected, uncheck country
        if (state[countryId].type === 'specific' && state[countryId].children.size === 0) {
            delete state[countryId];
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = false;
        }

        updateBadge(countryId);
        updateSummary();
    };

    function checkAllChildrenSelected(countryId) {
        var flat = loadedFlat[countryId] || [];
        if (flat.length > 0 && state[countryId] && state[countryId].type === 'specific') {
            if (state[countryId].children.size >= flat.length) {
                state[countryId].type = 'all';
                state[countryId].children = new Set();
                var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
                if (selectAllCb) selectAllCb.checked = true;
            }
        }
    }

    // ─── Badge & Summary ───

    function updateBadge(countryId) {
        var badge = document.getElementById('wl_badge_' + countryId);
        if (!state[countryId]) {
            badge.innerHTML = '';
            return;
        }
        if (state[countryId].type === 'all') {
            badge.innerHTML = '<span style="background:#28a745; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px;">ALL CITIES INCLUDED</span>';
        } else {
            var count = state[countryId].children.size;
            badge.innerHTML = '<span style="background:#e67e22; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px;">' + count + ' SELECTED</span>';
        }
    }

    function updateSummary() {
        var countryCount = Object.keys(state).length;
        var regionCount = 0;
        for (var cid in state) {
            if (state[cid].type === 'all') {
                regionCount += (loadedFlat[cid] || []).length || 0;
            } else {
                regionCount += state[cid].children.size;
            }
        }
        document.getElementById('wl_summary_countries').textContent = countryCount;
        document.getElementById('wl_summary_regions').textContent = regionCount;
    }

    // ─── Submit ───

    window.submitWhitelist = function() {
        var form = document.getElementById('whitelist_form');
        var container = document.getElementById('wl_hidden_inputs');
        container.innerHTML = '';
        var idx = 0;
        for (var countryId in state) {
            container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][destination_id]" value="' + countryId + '" />';
            container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][selection_type]" value="' + state[countryId].type + '" />';
            idx++;
            if (state[countryId].type === 'specific') {
                state[countryId].children.forEach(function(childId) {
                    container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][destination_id]" value="' + childId + '" />';
                    container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][selection_type]" value="specific" />';
                    idx++;
                });
            }
        }
        form.submit();
    };

    // ─── Search: countries + regions/cities via AJAX ───

    var searchInput = document.getElementById('wl_search');
    var searchResultsEl = document.getElementById('wl_search_results');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();

            // Always filter countries instantly
            var countryItems = document.querySelectorAll('.wl-country');
            countryItems.forEach(function(el) {
                var name = el.dataset.name || '';
                el.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
            });

            // AJAX search for regions/cities (debounced)
            clearTimeout(searchTimeout);
            if (q.length < 2) {
                searchResultsEl.style.display = 'none';
                searchResultsEl.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', searchUrl + '&q=' + encodeURIComponent(q));
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var data = JSON.parse(xhr.responseText);
                        var results = (data.results || []).filter(function(r) {
                            return r.type === 'region' || r.type === 'city' || r.type === 'destination';
                        });
                        renderSearchResults(results, q);
                    }
                };
                xhr.send();
            }, 300);
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResultsEl.contains(e.target)) {
                searchResultsEl.style.display = 'none';
            }
        });
    }

    function renderSearchResults(results, query) {
        if (results.length === 0) {
            searchResultsEl.innerHTML = '<div style="padding:12px; color:#999; font-size:13px;">No regions or cities found for "' + escapeHtml(query) + '"</div>';
            searchResultsEl.style.display = 'block';
            return;
        }

        // Group by country
        var grouped = {};
        results.forEach(function(r) {
            var cc = r.country_code || '??';
            if (!grouped[cc]) grouped[cc] = { regions: [], cities: [] };
            if (r.type === 'region') {
                grouped[cc].regions.push(r);
            } else {
                grouped[cc].cities.push(r);
            }
        });

        var html = '';
        for (var cc in grouped) {
            var group = grouped[cc];
            // Find country name from the page
            var countryEl = document.querySelector('[data-cc="' + cc + '"]');
            var countryName = countryEl ? countryEl.dataset.name : cc;
            countryName = countryName.charAt(0).toUpperCase() + countryName.slice(1);
            var countryId = countryEl ? parseInt(countryEl.dataset.countryId) : 0;

            html += '<div style="padding:4px 12px 2px; background:#f0f0f0; font-weight:600; font-size:12px; color:#555; border-top:1px solid #eee;">' +
                countryName + ' (' + cc + ')</div>';

            // Regions
            group.regions.forEach(function(r) {
                html += '<div class="wl-search-item" style="display:flex; align-items:center; gap:8px; padding:6px 12px 6px 20px; cursor:pointer; border-bottom:1px solid #f5f5f5;" ' +
                    'onmouseover="this.style.background=\'#f0f7ff\'" onmouseout="this.style.background=\'\'" ' +
                    'onclick="onSearchResultClick(' + countryId + ',' + r.destination_id + ',\'region\')">' +
                    '<span style="font-size:10px; padding:1px 6px; background:#2196F3; color:#fff; border-radius:3px; text-transform:uppercase; flex-shrink:0;">Region</span>' +
                    '<span style="font-weight:600; font-size:13px;">' + highlightMatch(r.name, query) + '</span>' +
                    '<span style="font-size:11px; color:#999; margin-left:auto;">' + (r.full_path || '') + '</span>' +
                    '</div>';
            });

            // Cities
            group.cities.forEach(function(r) {
                html += '<div class="wl-search-item" style="display:flex; align-items:center; gap:8px; padding:5px 12px 5px 28px; cursor:pointer; border-bottom:1px solid #f5f5f5;" ' +
                    'onmouseover="this.style.background=\'#f0f7ff\'" onmouseout="this.style.background=\'\'" ' +
                    'onclick="onSearchResultClick(' + countryId + ',' + r.destination_id + ',\'city\')">' +
                    '<span style="font-size:10px; padding:1px 6px; background:#8BC34A; color:#fff; border-radius:3px; text-transform:uppercase; flex-shrink:0;">City</span>' +
                    '<span style="font-size:13px; color:#444;">' + highlightMatch(r.name, query) + '</span>' +
                    '<span style="font-size:11px; color:#999; margin-left:auto;">' + (r.full_path || '') + '</span>' +
                    '</div>';
            });
        }

        searchResultsEl.innerHTML = html;
        searchResultsEl.style.display = 'block';
    }

    function highlightMatch(text, query) {
        var idx = text.toLowerCase().indexOf(query.toLowerCase());
        if (idx === -1) return escapeHtml(text);
        return escapeHtml(text.substring(0, idx)) + '<strong>' + escapeHtml(text.substring(idx, idx + query.length)) + '</strong>' + escapeHtml(text.substring(idx + query.length));
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // ─── Search result click: expand country, load tree, select item ───

    window.onSearchResultClick = function(countryId, destId, type) {
        if (!countryId) return;
        searchResultsEl.style.display = 'none';

        // Show the country in the list
        var countryEl = document.querySelector('[data-country-id="' + countryId + '"]');
        if (countryEl) countryEl.style.display = '';

        // Expand country if not already
        var childrenEl = document.getElementById('wl_children_' + countryId);
        if (childrenEl && childrenEl.style.display === 'none') {
            toggleCountryExpand(countryId);
        }

        // After tree loads, select the item
        function doSelect() {
            if (!state[countryId]) {
                state[countryId] = { type: 'specific', children: new Set() };
                document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = true;
            }

            if (type === 'region') {
                // Select region + all its cities
                var region = findRegionInTree(countryId, destId);
                if (region) {
                    state[countryId].children.add(destId);
                    region.children.forEach(function(c) {
                        state[countryId].children.add(c.destination_id);
                    });
                }
            } else {
                state[countryId].children.add(destId);
            }

            checkAllChildrenSelected(countryId);
            renderChildren(countryId);
            updateBadge(countryId);
            updateSummary();

            // Scroll to the country
            if (countryEl) countryEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Wait for tree to load if needed
        if (loadedTree[countryId]) {
            doSelect();
        } else {
            var checkInterval = setInterval(function() {
                if (loadedTree[countryId]) {
                    clearInterval(checkInterval);
                    doSelect();
                }
            }, 100);
            // Safety timeout
            setTimeout(function() { clearInterval(checkInterval); }, 5000);
        }
    };

    {/literal}

    // ─── Load whitelisted children state from server ───
    {foreach from=$countries item=c}
    {if $c.is_whitelisted && $c.selection_type !== 'all'}
    (function(cid, cc) {ldelim}
        var xhr = new XMLHttpRequest();
        xhr.open('GET', treeUrl + '&country_code=' + encodeURIComponent(cc));
        xhr.onload = function() {ldelim}
            if (xhr.status === 200) {ldelim}
                var data = JSON.parse(xhr.responseText);
                var tree = [];
                var flat = [];
                (data.tree || []).forEach(function(region) {ldelim}
                    var regionItem = {ldelim}
                        destination_id: parseInt(region.destination_id),
                        name: region.name,
                        type: region.type || 'region',
                        children: []
                    {rdelim};
                    flat.push({ldelim} destination_id: regionItem.destination_id, name: regionItem.name, type: regionItem.type {rdelim});
                    (region.children || []).forEach(function(city) {ldelim}
                        var cityItem = {ldelim}
                            destination_id: parseInt(city.destination_id),
                            name: city.name,
                            type: city.type || 'city'
                        {rdelim};
                        regionItem.children.push(cityItem);
                        flat.push(cityItem);
                    {rdelim});
                    tree.push(regionItem);
                {rdelim});
                loadedTree[cid] = tree;
                loadedFlat[cid] = flat;
            {rdelim}
        {rdelim};
        xhr.send();
    {rdelim})({$c.destination_id}, '{$c.country_code}');
    {/if}
    {/foreach}

    {foreach from=$countries item=c}
    {if $c.is_whitelisted && $c.selection_type !== 'all' && $c.whitelisted_child_count > 0}
    (function(cid) {ldelim}
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '{""|fn_url}' + '?dispatch=sphinx_holidays.get_whitelist_children&country_id=' + cid);
        xhr.onload = function() {ldelim}
            if (xhr.status === 200) {ldelim}
                var data = JSON.parse(xhr.responseText);
                if (state[cid] && data.children) {ldelim}
                    state[cid].children = new Set(data.children.map(Number));
                    updateBadge(cid);
                    updateSummary();
                {rdelim}
            {rdelim}
        {rdelim};
        xhr.send();
    {rdelim})({$c.destination_id});
    {/if}
    {/foreach}

    {literal}
    // Initial summary
    updateSummary();
    {/literal}
})();
</script>

{/capture}

{include file="common/mainbox.tpl"
    title=__("sphinx_holidays.destination_whitelist")
    content=$smarty.capture.mainbox
}
