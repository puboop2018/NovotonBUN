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
            <div style="margin-bottom: 12px;">
                <input type="text" id="wl_search" class="input-large"
                       placeholder="{__("sphinx_holidays.search_destinations")}"
                       style="width: 100%; box-sizing: border-box;" />
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
                        <div class="wl-children-grid" id="wl_grid_{$c.destination_id}" style="display:flex; flex-wrap:wrap; gap: 4px 16px;">
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
    var loadedChildren = {ldelim}{rdelim}; // countryId => [{ destination_id, name, type }]
    var treeUrl = '{"sphinx_holidays.get_destinations_tree"|fn_url}';
    var searchUrl = '{"sphinx_holidays.search_destinations"|fn_url}';

    // Initialize state from server data
    {foreach from=$countries item=c}
    {if $c.is_whitelisted}
    state[{$c.destination_id}] = {ldelim}
        type: '{$c.selection_type}',
        children: new Set()
    {rdelim};
    {/if}
    {/foreach}

    // Load whitelisted children IDs from DB via inline data
    {literal}
    // We'll load children on expand; for now mark the country-level state

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
        if (loadedChildren[countryId]) {
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
                var items = [];
                (data.tree || []).forEach(function(region) {
                    items.push({ destination_id: parseInt(region.destination_id), name: region.name, type: region.type || 'region' });
                    (region.children || []).forEach(function(city) {
                        items.push({ destination_id: parseInt(city.destination_id), name: city.name, type: city.type || 'city' });
                    });
                });
                loadedChildren[countryId] = items;
                renderChildren(countryId);
            }
        };
        xhr.send();
    }

    function renderChildren(countryId) {
        var grid = document.getElementById('wl_grid_' + countryId);
        var items = loadedChildren[countryId] || [];
        if (items.length === 0) {
            grid.innerHTML = '<span class="muted" style="font-size:12px;">No regions/cities found</span>';
            return;
        }
        var isAll = state[countryId] && state[countryId].type === 'all';
        var selectedChildren = state[countryId] ? state[countryId].children : new Set();

        var html = '';
        items.forEach(function(item) {
            var checked = isAll || selectedChildren.has(item.destination_id) ? ' checked' : '';
            var indent = item.type !== 'region' ? 'padding-left:16px;' : 'font-weight:600;';
            html += '<label style="display:inline-flex; align-items:center; gap:4px; min-width:180px; font-size:13px;' + indent + '">' +
                '<input type="checkbox" class="wl-child-cb" value="' + item.destination_id + '" ' +
                'data-country="' + countryId + '"' + checked +
                ' onchange="onChildToggle(this,' + countryId + ',' + item.destination_id + ')" />' +
                item.name + '</label>';
        });
        grid.innerHTML = html;
    }

    window.onCountryToggle = function(cb, countryId) {
        if (cb.checked) {
            state[countryId] = { type: 'all', children: new Set() };
            // Check the "select all" checkbox if expanded
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = true;
            // Check all child checkboxes if loaded
            if (loadedChildren[countryId]) {
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
            // Uncheck all children, keep country checked with empty specific
            state[countryId] = { type: 'specific', children: new Set() };
            var childCbs = document.querySelectorAll('.wl-child-cb[data-country="' + countryId + '"]');
            childCbs.forEach(function(c) { c.checked = false; });
        }
        updateBadge(countryId);
        updateSummary();
    };

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

        // Check if all children are now selected → switch to 'all'
        var items = loadedChildren[countryId] || [];
        if (items.length > 0 && state[countryId].children.size >= items.length) {
            state[countryId].type = 'all';
            state[countryId].children = new Set();
            var selectAllCb = document.querySelector('.wl-select-all-children[data-country="' + countryId + '"]');
            if (selectAllCb) selectAllCb.checked = true;
        }

        // If no children selected, uncheck country
        if (state[countryId].type === 'specific' && state[countryId].children.size === 0) {
            delete state[countryId];
            document.querySelector('.wl-country-cb[value="' + countryId + '"]').checked = false;
        }

        updateBadge(countryId);
        updateSummary();
    };

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
                // Count loaded children or use a placeholder
                regionCount += (loadedChildren[cid] || []).length || 0;
            } else {
                regionCount += state[cid].children.size;
            }
        }
        document.getElementById('wl_summary_countries').textContent = countryCount;
        document.getElementById('wl_summary_regions').textContent = regionCount;
    }

    window.submitWhitelist = function() {
        var form = document.getElementById('whitelist_form');
        var container = document.getElementById('wl_hidden_inputs');
        container.innerHTML = '';
        var idx = 0;
        for (var countryId in state) {
            // Add country entry
            container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][destination_id]" value="' + countryId + '" />';
            container.innerHTML += '<input type="hidden" name="whitelist[' + idx + '][selection_type]" value="' + state[countryId].type + '" />';
            idx++;
            // If specific, add each child
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

    // Search filter
    var searchInput = document.getElementById('wl_search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var items = document.querySelectorAll('.wl-country');
            items.forEach(function(el) {
                var name = el.dataset.name || '';
                el.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // Load whitelisted children state from server
    {/literal}
    {foreach from=$countries item=c}
    {if $c.is_whitelisted && $c.selection_type !== 'all'}
    (function(cid, cc) {ldelim}
        // Fetch whitelisted child IDs for this country
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '{$config.current_url|fn_url}' + '?dispatch=sphinx_holidays.get_destinations_tree&country_code=' + cc);
        xhr.onload = function() {ldelim}
            if (xhr.status === 200) {ldelim}
                var data = JSON.parse(xhr.responseText);
                var items = [];
                (data.tree || []).forEach(function(region) {ldelim}
                    items.push({ldelim} destination_id: parseInt(region.destination_id), name: region.name, type: 'region' {rdelim});
                    (region.children || []).forEach(function(city) {ldelim}
                        items.push({ldelim} destination_id: parseInt(city.destination_id), name: city.name, type: 'city' {rdelim});
                    {rdelim});
                {rdelim});
                loadedChildren[cid] = items;

                // Now load the whitelisted child IDs from the DB
                var whitelistedIds = new Set();
                {literal}
                var wlXhr = new XMLHttpRequest();
                wlXhr.open('GET', '{/literal}{""|fn_url}{literal}' + '?dispatch=sphinx_holidays.get_whitelist_children&country_id=' + cid);
                // We'll use a simpler approach: just check which items are in the whitelist table
                {/literal}
                // Simplified: state was already set, just mark children as loaded
                if (state[cid] && state[cid].type === 'specific') {ldelim}
                    // Children IDs will be loaded from inline data
                {rdelim}
            {rdelim}
        {rdelim};
        xhr.send();
    {rdelim})({$c.destination_id}, '{$c.country_code}');
    {/if}
    {/foreach}

    {literal}
    // Load whitelisted children from inline data
    {/literal}
    {foreach from=$countries item=c}
    {if $c.is_whitelisted && $c.selection_type !== 'all' && $c.whitelisted_child_count > 0}
    (function(cid) {ldelim}
        // Query whitelisted children for this country from the controller
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
