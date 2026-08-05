{capture name="mainbox"}

<div class="travel-admin-panel" id="eurosite-whitelist">

    {if !$eurosite_countries_synced}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.whitelist_needs_countries", ["[default]" => "The country catalog has not been synced yet — run the 'countries' sync from the dashboard first. City lists fall back to live API calls."])}
        </div>
    {/if}

    <p class="muted">
        {__("eurosite.whitelist_hint", ["[default]" => "Only whitelisted destinations are synced and searchable on the storefront. Tick a country to include all of its cities, or expand it and pick specific cities."])}
    </p>

    <form action="{""|fn_url}" method="post" id="eurosite-whitelist-form">
        <input type="hidden" name="dispatch" value="eurosite.save_whitelist" />
        <input type="hidden" name="whitelist_json" id="eurosite-whitelist-json" value="" />

        <div id="eurosite-country-list">
            {foreach from=$eurosite_countries item=country}
                {assign var="cc" value=$country.country_code}
                {assign var="wl" value=$eurosite_whitelist_map.$cc}
                <div class="eurosite-country-row" data-country="{$cc}" style="border-bottom: 1px solid #eee; padding: 6px 0;">
                    <label class="checkbox inline" style="min-width: 260px;">
                        <input type="checkbox" class="eurosite-country-all" data-country="{$cc}"
                               {if $wl && $wl.all}checked{/if} />
                        <strong>{$country.name|escape:html}</strong> <code>{$cc}</code>
                        {if $wl && !$wl.all && $wl.cities}
                            <span class="label">{$wl.cities|count} {__("eurosite.cities_selected", ["[default]" => "cities"])}</span>
                        {/if}
                    </label>
                    <a href="#" class="eurosite-expand" data-country="{$cc}">{__("eurosite.pick_cities", ["[default]" => "pick specific cities"])}</a>
                    <div class="eurosite-city-box" data-country="{$cc}" style="display:none; margin: 6px 0 6px 24px;"></div>
                </div>
            {/foreach}
        </div>

        <div class="buttons-container" style="margin-top: 14px;">
            <button type="submit" class="btn btn-primary">{__("save")}</button>
            <a href="{"eurosite.manage"|fn_url}" class="btn">{__("eurosite.back_to_dashboard", ["[default]" => "Back to dashboard"])}</a>
        </div>
    </form>
</div>

<script>
(function () {
    var whitelist = {$eurosite_whitelist_json nofilter};   // country => {ldelim}all, cities[]{rdelim}
    var cityCache = {ldelim}{rdelim};

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
                + '<input type="checkbox" class="eurosite-city" data-country="' + cc + '" value="' + city.code + '"' + checked + '> '
                + city.name + ' <code>' + city.code + '</code>' + own + '</label> ';
        });
        box.innerHTML = html || '<span class="muted">{__("eurosite.no_cities", ["[default]" => "No cities found."])}</span>';
    }

    document.querySelectorAll('.eurosite-expand').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var cc = link.getAttribute('data-country');
            var box = cityBox(cc);
            if (box.style.display !== 'none') { box.style.display = 'none'; return; }
            box.style.display = 'block';
            if (cityCache[cc]) { return; }
            box.innerHTML = '<span class="muted">{__("loading", ["[default]" => "Loading..."])}</span>';
            fetch('{"eurosite.get_cities"|fn_url nofilter}&country=' + encodeURIComponent(cc), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { box.innerHTML = '<span class="text-error">' + (data.error || 'error') + '</span>'; return; }
                    cityCache[cc] = true;
                    renderCities(cc, data.cities);
                })
                .catch(function () { box.innerHTML = '<span class="text-error">request failed</span>'; });
        });
    });

    document.getElementById('eurosite-whitelist-form').addEventListener('submit', function () {
        var entries = [];
        document.querySelectorAll('.eurosite-country-all').forEach(function (cb) {
            var cc = cb.getAttribute('data-country');
            if (cb.checked) {
                entries.push({country_code: cc, city_code: '', selection_type: 'all'});
                return;                                  // whole country wins
            }
            var boxChecked = document.querySelectorAll('.eurosite-city[data-country="' + cc + '"]:checked');
            if (boxChecked.length) {
                boxChecked.forEach(function (cityCb) {
                    entries.push({country_code: cc, city_code: cityCb.value, selection_type: 'specific'});
                });
            } else if (!cityCache[cc] && whitelist[cc] && !whitelist[cc].all && whitelist[cc].cities) {
                // country never expanded this session — keep its saved cities
                whitelist[cc].cities.forEach(function (code) {
                    entries.push({country_code: cc, city_code: code, selection_type: 'specific'});
                });
            }
        });
        document.getElementById('eurosite-whitelist-json').value = JSON.stringify(entries);
    });
})();
</script>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.whitelist_title", ["[default]" => "Eurosite — Destination whitelist"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
