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

    {* All values the script needs ride as data attributes so the <script>
       below can be one pure literal block — inline JS object braces otherwise
       parse as Smarty tags and kill template compilation. *}
    <div id="eurosite-whitelist-data"
         data-whitelist="{$eurosite_whitelist_json|escape:html}"
         data-cities-url="{"eurosite.get_cities"|fn_url}"
         data-txt-loading="{__("loading", ["[default]" => "Loading..."])|escape:html}"
         data-txt-no-cities="{__("eurosite.no_cities", ["[default]" => "No cities found."])|escape:html}"
         data-txt-failed="{__("eurosite.request_failed", ["[default]" => "Request failed."])|escape:html}"></div>

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
{literal}
(function () {
    var dataEl = document.getElementById('eurosite-whitelist-data');
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
})();
{/literal}
</script>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.whitelist_title", ["[default]" => "Eurosite — Destination whitelist"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
