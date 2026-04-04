{script src="js/lib/select2/select2.min.js"}

{* ── Sidebar: Filter Panel ── *}
{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="sphinx_hotels_filter_form" id="sphinx_hotels_filter_form">
        <input type="hidden" name="dispatch" value="sphinx_holidays.hotels" />

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
            <label>{__("sphinx_holidays.classification")}:</label>
            <select name="classification">
                <option value="">{__("sphinx_holidays.all_classifications")}</option>
                {foreach from=$distinct_classifications item=cl}
                    {if $cl == 0}
                        <option value="0" {if $search.classification == '0' && $search.classification != ''}selected{/if}>{__("sphinx_holidays.unclassified")}</option>
                    {else}
                        <option value="{$cl}" {if $search.classification == $cl && $search.classification != ''}selected{/if}>{$cl}&#9733;</option>
                    {/if}
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("sphinx_holidays.property_type")}:</label>
            <select name="property_type">
                <option value="">{__("sphinx_holidays.all_property_types")}</option>
                {foreach from=$distinct_property_types item=pt}
                    <option value="{$pt|escape:html}" {if $search.property_type == $pt}selected{/if}>{$pt|escape:html}</option>
                {/foreach}
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
            <label>{__("sphinx_holidays.link_status")}:</label>
            <select name="link_status">
                <option value="">{__("sphinx_holidays.all_link_statuses")}</option>
                <option value="linked" {if $search.link_status == "linked"}selected{/if}>{__("sphinx_holidays.linked")}</option>
                <option value="orphan" {if $search.link_status == "orphan"}selected{/if}>{__("sphinx_holidays.orphan")}</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("name")}:</label>
            <input type="hidden" name="q" id="hotel_search_q" value="{$search.q|escape:html}" />
            <select id="hotel_name_select2" style="width: 100%;">
                {if $search.q}
                    <option value="{$search.q|escape:html}" selected>{$search.q|escape:html}</option>
                {/if}
            </select>
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}


{* ── Main Content ── *}
{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{* $sort_url_base is built in the controller with proper URL encoding *}

<form action="{"sphinx_holidays.bulk_update_hotels"|fn_url}" method="post" name="sphinx_bulk_form" id="sphinx_bulk_form">
<input type="hidden" name="security_hash" value="{$security_hash}" />
<input type="hidden" name="bulk_status" value="active" id="sphinx_bulk_action" />

{if $hotels}
<table class="table table-middle table-striped">
    <thead>
        <tr>
            {* Select-all checkbox *}
            <th width="30">
                <input type="checkbox" class="select-all-hotels" onclick="toggleAllHotels(this)" />
            </th>

            {* ID — sortable *}
            <th width="100">
                <a href="{"`$sort_url_base`&sort_by=hotel_id&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    ID
                    {if $search.sort_by == 'hotel_id'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Name — sortable *}
            <th>
                <a href="{"`$sort_url_base`&sort_by=name&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("name")}
                    {if $search.sort_by == 'name'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Classification — sortable *}
            <th width="100">
                <a href="{"`$sort_url_base`&sort_by=classification&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.classification")}
                    {if $search.sort_by == 'classification'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Country — sortable *}
            <th width="80">
                <a href="{"`$sort_url_base`&sort_by=country_code&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.country_code")}
                    {if $search.sort_by == 'country_code'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Region — not sortable *}
            <th>{__("sphinx_holidays.region")}</th>

            {* City/Destination — not sortable *}
            <th>{__("sphinx_holidays.city_resort")}</th>

            {* Type — sortable *}
            <th width="90">
                <a href="{"`$sort_url_base`&sort_by=property_type&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.property_type")}
                    {if $search.sort_by == 'property_type'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Product link *}
            <th width="80">{__("sphinx_holidays.product")}</th>

            {* Status — sortable *}
            <th width="80">
                <a href="{"`$sort_url_base`&sort_by=sync_status&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.sync_status")}
                    {if $search.sort_by == 'sync_status'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>

            {* Last Synced — sortable *}
            <th width="140">
                <a href="{"`$sort_url_base`&sort_by=last_synced_at&sort_order=`$search.sort_order_toggle`"|fn_url}">
                    {__("sphinx_holidays.last_synced")}
                    {if $search.sort_by == 'last_synced_at'}
                        {if $search.sort_order == 'asc'}&#9650;{else}&#9660;{/if}
                    {/if}
                </a>
            </th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$hotels item=hotel}
        <tr>
            {* Checkbox *}
            <td>
                <input type="checkbox" name="hotel_ids[]" value="{$hotel.hotel_id|escape:html}" class="cm-item-hotel" />
            </td>

            {* Hotel ID *}
            <td>
                <code style="font-size: 11px;">{$hotel.hotel_id|escape:html|truncate:18}</code>
            </td>

            {* Name + thumbnail *}
            <td>
                <div style="display:flex; align-items:center; gap:8px;">
                    {if $hotel.image_url}
                        <img src="{$hotel.image_url|escape:html}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:3px; flex-shrink:0;" loading="lazy" />
                    {else}
                        <div class="no-image" style="width:40px; height:40px; flex-shrink:0; margin:0;">
                            <span class="cs-icon glyph-image" title="{__("no_image")}"></span>
                        </div>
                    {/if}
                    <span>{$hotel.name|escape:html}</span>
                </div>
            </td>

            {* Classification *}
            <td>
                {if $hotel.classification > 0}
                    {$hotel.classification}<span style="color:#f39c12;">&#9733;</span>
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>

            {* Country *}
            <td>{$hotel.country_code|escape:html}</td>

            {* Region *}
            <td>{$hotel.region_name|escape:html}</td>

            {* City/Destination *}
            <td>{$hotel.destination_name|escape:html}</td>

            {* Property Type *}
            <td>{$hotel.property_type|escape:html}</td>

            {* Product Link *}
            <td>
                {if $hotel.product_id > 0}
                    <a href="{"products.update?product_id=`$hotel.product_id`"|fn_url}" title="{__("sphinx_holidays.product")} #{$hotel.product_id}">
                        <i class="icon-link"></i> #{$hotel.product_id}
                    </a>
                {else}
                    <span class="muted">-</span>
                {/if}
            </td>

            {* Status *}
            <td>
                {if $hotel.sync_status == 'active'}
                    <span class="label label-success" title="{__("sphinx_holidays.active_hint")}">{__("sphinx_holidays.active")}</span>
                {elseif $hotel.sync_status == 'inactive'}
                    <span class="label label-warning" title="{__("sphinx_holidays.inactive_hint")}">{__("sphinx_holidays.inactive")}</span>
                {else}
                    <span class="label label-important" title="{__("sphinx_holidays.error_hint")}">{__("error")}</span>
                {/if}
            </td>

            {* Last Synced *}
            <td>{$hotel.last_synced_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{* ── Bulk Action Buttons ── *}
<div class="well well-small" style="margin-top: 10px;">
    <strong>{__("sphinx_holidays.with_selected")}:</strong>
    <a href="#" class="btn btn-mini btn-success" onclick="document.getElementById('sphinx_bulk_action').value='active'; document.getElementById('sphinx_bulk_form').submit(); return false;">
        <i class="icon-ok"></i> {__("sphinx_holidays.bulk_activate")}
    </a>
    <a href="#" class="btn btn-mini btn-warning" onclick="document.getElementById('sphinx_bulk_action').value='inactive'; document.getElementById('sphinx_bulk_form').submit(); return false;">
        <i class="icon-ban-circle"></i> {__("sphinx_holidays.bulk_deactivate")}
    </a>
    <a href="#" class="btn btn-mini" onclick="document.getElementById('sphinx_bulk_form').action = '{"sphinx_holidays.bulk_sync_images"|fn_url}'; document.getElementById('sphinx_bulk_form').submit(); return false;">
        <i class="icon-picture"></i> {__("sphinx_holidays.bulk_sync_images")}
    </a>
    <a href="#" class="btn btn-mini btn-danger" onclick="if(confirm('{__("sphinx_holidays.bulk_delete_confirm")|escape:"javascript"}')) {ldelim} document.getElementById('sphinx_bulk_form').action = '{"sphinx_holidays.bulk_delete_hotels"|fn_url}'; document.getElementById('sphinx_bulk_form').submit(); {rdelim} return false;">
        <i class="icon-trash"></i> {__("sphinx_holidays.bulk_delete")}
    </a>
</div>

{else}
    <p class="no-items">{__("sphinx_holidays.no_hotels")}</p>
{/if}

{include file="common/pagination.tpl"}

</form>

{* ── JavaScript: Cascading selects + bulk select ── *}
<script>
(function() {ldelim}
    var countrySelect = document.getElementById('sphinx_country_filter');
    var regionSelect = document.getElementById('sphinx_region_filter');
    var citySelect = document.getElementById('sphinx_city_filter');

    var savedRegionId = '{$search.region_id|escape:javascript}';
    var savedDestinationId = '{$search.destination_id|escape:javascript}';

    function resetSelect(sel, defaultText) {ldelim}
        sel.innerHTML = '<option value="">' + defaultText + '</option>';
    {rdelim}

    function populateSelect(sel, items, idKey, nameKey, selectedVal, defaultText) {ldelim}
        resetSelect(sel, defaultText);
        for (var i = 0; i < items.length; i++) {ldelim}
            var opt = document.createElement('option');
            opt.value = items[i][idKey];
            var label = items[i][nameKey];
            if (items[i].hotel_count > 0) {ldelim}
                label += ' (' + items[i].hotel_count + ')';
            {rdelim}
            opt.textContent = label;
            if (String(items[i][idKey]) === String(selectedVal)) {ldelim}
                opt.selected = true;
            {rdelim}
            sel.appendChild(opt);
        {rdelim}
    {rdelim}

    function loadRegions(countryCode, preselect) {ldelim}
        resetSelect(regionSelect, '{__("sphinx_holidays.all_regions")|escape:javascript}');
        resetSelect(citySelect, '{__("sphinx_holidays.all_cities")|escape:javascript}');
        if (!countryCode) return;

        fetch('{"sphinx_holidays.get_regions"|fn_url:"A"}&country_code=' + encodeURIComponent(countryCode))
            .then(function(r) {ldelim} return r.json(); {rdelim})
            .then(function(data) {ldelim}
                if (data.regions && data.regions.length > 0) {ldelim}
                    populateSelect(regionSelect, data.regions, 'destination_id', 'name',
                        preselect || '', '{__("sphinx_holidays.all_regions")|escape:javascript}');
                    if (preselect) {ldelim}
                        loadCities(preselect, savedDestinationId);
                    {rdelim}
                {rdelim}
            {rdelim});
    {rdelim}

    function loadCities(regionId, preselect) {ldelim}
        resetSelect(citySelect, '{__("sphinx_holidays.all_cities")|escape:javascript}');
        if (!regionId) return;

        fetch('{"sphinx_holidays.get_cities"|fn_url:"A"}&region_id=' + encodeURIComponent(regionId))
            .then(function(r) {ldelim} return r.json(); {rdelim})
            .then(function(data) {ldelim}
                if (data.cities && data.cities.length > 0) {ldelim}
                    populateSelect(citySelect, data.cities, 'destination_id', 'name',
                        preselect || '', '{__("sphinx_holidays.all_cities")|escape:javascript}');
                {rdelim}
            {rdelim});
    {rdelim}

    countrySelect.addEventListener('change', function() {ldelim}
        loadRegions(this.value, '');
    {rdelim});

    regionSelect.addEventListener('change', function() {ldelim}
        loadCities(this.value, '');
    {rdelim});

    // Restore state on page load if country was selected
    if (countrySelect.value) {ldelim}
        loadRegions(countrySelect.value, savedRegionId);
    {rdelim}

    // ─── Bulk select/deselect all ───

    window.toggleAllHotels = function(source) {ldelim}
        var checkboxes = document.querySelectorAll('.cm-item-hotel');
        for (var i = 0; i < checkboxes.length; i++) {ldelim}
            checkboxes[i].checked = source.checked;
        {rdelim}
    {rdelim};

    // ─── Hotel name autocomplete (Select2 + AJAX) ───
    if (typeof $ !== 'undefined' && typeof $.fn.select2 !== 'undefined') {ldelim}
        (function() {ldelim}
            var $sel = $('#hotel_name_select2');
            var $hidden = $('#hotel_search_q');

            $sel.select2({ldelim}
                ajax: {ldelim}
                    url: '{"sphinx_holidays.search_hotels"|fn_url:"A"}',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {ldelim}
                        return {ldelim} q: params.term {rdelim};
                    {rdelim},
                    processResults: function(data) {ldelim}
                        return {ldelim}
                            results: (data.results || []).map(function(r) {ldelim}
                                var stars = r.classification > 0 ? ' ' + r.classification + '\u2605' : '';
                                var loc = r.destination_name
                                    ? r.destination_name + ', ' + r.country_code
                                    : r.country_code;
                                return {ldelim}
                                    id: r.name,
                                    text: r.name + stars + '  \u2014 ' + loc
                                {rdelim};
                            {rdelim})
                        {rdelim};
                    {rdelim},
                    cache: true
                {rdelim},
                minimumInputLength: 2,
                placeholder: '{__("sphinx_holidays.search_hotels")|escape:"javascript"}',
                allowClear: true,
                width: '100%'
            {rdelim});

            // On selection: copy name to hidden input and auto-submit form
            $sel.on('select2:select', function(e) {ldelim}
                $hidden.val(e.params.data.id);
                document.getElementById('sphinx_hotels_filter_form').submit();
            {rdelim});

            // On clear: reset hidden input and auto-submit form
            $sel.on('select2:unselecting', function() {ldelim}
                $hidden.val('');
                setTimeout(function() {ldelim}
                    document.getElementById('sphinx_hotels_filter_form').submit();
                {rdelim}, 50);
            {rdelim});
        {rdelim})();
    {rdelim}

{rdelim})();
</script>

{/capture}

{include file="common/mainbox.tpl"
    title="{__('sphinx_holidays.hotels')}"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
