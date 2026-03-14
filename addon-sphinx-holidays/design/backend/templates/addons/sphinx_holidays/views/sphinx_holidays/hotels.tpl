{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{* Search/filter form *}
<form action="{""|fn_url}" method="get" name="sphinx_hotels_search_form" id="sphinx_hotels_search_form">
    <input type="hidden" name="dispatch" value="sphinx_holidays.hotels" />

    <div class="sidebar-row">
        <h6>{__("search")}</h6>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.country_code")}:</label>
            <select name="country_code" id="sphinx_country_filter">
                <option value="">--</option>
                {foreach from=$distinct_countries item=cc}
                    <option value="{$cc}" {if $search.country_code == $cc}selected{/if}>{$cc}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.region")}:</label>
            <select name="region_id" id="sphinx_region_filter">
                <option value="">{__("sphinx_holidays.all_regions")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.city_resort")}:</label>
            <select name="destination_id" id="sphinx_city_filter">
                <option value="">{__("sphinx_holidays.all_cities")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.sync_status")}:</label>
            <select name="sync_status">
                <option value="">{__("sphinx_holidays.all_statuses")}</option>
                <option value="active" {if $search.sync_status == "active"}selected{/if}>{__("sphinx_holidays.active")}</option>
                <option value="inactive" {if $search.sync_status == "inactive"}selected{/if}>{__("sphinx_holidays.inactive")}</option>
                <option value="error" {if $search.sync_status == "error"}selected{/if}>{__("error")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.destination_name")}:</label>
            <input type="text" name="q" value="{$search.q|escape:html}" size="20" placeholder="{__("sphinx_holidays.search_hotels")}" />
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </div>
</form>

{if $hotels}
<table class="table table-middle">
    <thead>
        <tr>
            <th width="120">ID</th>
            <th>{__("sphinx_holidays.destination_name")}</th>
            <th width="50">{__("sphinx_holidays.star_rating")}</th>
            <th width="80">{__("sphinx_holidays.country_code")}</th>
            <th>{__("sphinx_holidays.region")}</th>
            <th>{__("sphinx_holidays.destination_type")}</th>
            <th width="90">{__("sphinx_holidays.property_type")}</th>
            <th width="70">{__("sphinx_holidays.sync_status")}</th>
            <th width="140">{__("sphinx_holidays.last_synced")}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$hotels item=hotel}
        <tr>
            <td><code>{$hotel.hotel_id|escape:html|truncate:18}</code></td>
            <td>
                {$hotel.name|escape:html}
                {if $hotel.image_url}
                    <img src="{$hotel.image_url|escape:html}" alt="" style="height:20px; margin-left:5px; vertical-align:middle; border-radius:2px;" />
                {/if}
            </td>
            <td>
                {if $hotel.classification > 0}
                    {$hotel.classification}<span style="color:#f39c12;">&#9733;</span>
                {else}
                    -
                {/if}
            </td>
            <td>{$hotel.country_code|escape:html}</td>
            <td>{$hotel.region_name|escape:html}</td>
            <td>{$hotel.destination_name|escape:html}</td>
            <td>{$hotel.property_type|escape:html}</td>
            <td>
                <span class="status-badge status-{if $hotel.sync_status == 'active'}ok{elseif $hotel.sync_status == 'inactive'}pending{else}cancelled{/if}">
                    {$hotel.sync_status|escape:html}
                </span>
            </td>
            <td>{$hotel.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("sphinx_holidays.no_hotels")}</p>
{/if}

{include file="common/pagination.tpl"}

<script>
(function() {
    var countrySelect = document.getElementById('sphinx_country_filter');
    var regionSelect = document.getElementById('sphinx_region_filter');
    var citySelect = document.getElementById('sphinx_city_filter');

    var savedRegionId = '{$search.region_id|escape:javascript}';
    var savedDestinationId = '{$search.destination_id|escape:javascript}';

    function resetSelect(sel, defaultText) {
        sel.innerHTML = '<option value="">' + defaultText + '</option>';
    }

    function populateSelect(sel, items, idKey, nameKey, selectedVal, defaultText) {
        resetSelect(sel, defaultText);
        for (var i = 0; i < items.length; i++) {
            var opt = document.createElement('option');
            opt.value = items[i][idKey];
            var label = items[i][nameKey];
            if (items[i].hotel_count > 0) {
                label += ' (' + items[i].hotel_count + ')';
            }
            opt.textContent = label;
            if (String(items[i][idKey]) === String(selectedVal)) {
                opt.selected = true;
            }
            sel.appendChild(opt);
        }
    }

    function loadRegions(countryCode, preselect) {
        resetSelect(regionSelect, '{__("sphinx_holidays.all_regions")|escape:javascript}');
        resetSelect(citySelect, '{__("sphinx_holidays.all_cities")|escape:javascript}');
        if (!countryCode) return;

        fetch('{"sphinx_holidays.get_regions"|fn_url:"A"}&country_code=' + encodeURIComponent(countryCode))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.regions && data.regions.length > 0) {
                    populateSelect(regionSelect, data.regions, 'destination_id', 'name',
                        preselect || '', '{__("sphinx_holidays.all_regions")|escape:javascript}');
                    if (preselect) {
                        loadCities(preselect, savedDestinationId);
                    }
                }
            });
    }

    function loadCities(regionId, preselect) {
        resetSelect(citySelect, '{__("sphinx_holidays.all_cities")|escape:javascript}');
        if (!regionId) return;

        fetch('{"sphinx_holidays.get_cities"|fn_url:"A"}&region_id=' + encodeURIComponent(regionId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.cities && data.cities.length > 0) {
                    populateSelect(citySelect, data.cities, 'destination_id', 'name',
                        preselect || '', '{__("sphinx_holidays.all_cities")|escape:javascript}');
                }
            });
    }

    countrySelect.addEventListener('change', function() {
        loadRegions(this.value, '');
    });

    regionSelect.addEventListener('change', function() {
        loadCities(this.value, '');
    });

    // Restore state on page load if country was selected
    if (countrySelect.value) {
        loadRegions(countrySelect.value, savedRegionId);
    }
})();
</script>

{/capture}

{include file="common/mainbox.tpl" title="{__("sphinx_holidays.hotels")}" content=$smarty.capture.mainbox}
