{*
 * Sphinx Package Search Block
 *
 * Storefront entry point for the package vertical (flight/bus + hotel):
 * a search form posting to sphinx_booking.package_search. The destination /
 * departure / transport selects are populated via AJAX from the
 * cron-synced sphinx_package_routes cache (cache_deals?type=package_routes)
 * — no provider API call to render the block.
 *
 * @package SphinxHolidays
 *}

{assign var="pkg_widget_id" value="sphinx_package_search_`$block.block_id`"}

<div id="{$pkg_widget_id}" class="sphinx-package-search-widget" style="background: #fff; border: 1px solid #e0e7ef; border-radius: 8px; padding: 16px;">
    <form action="{""|fn_url}" method="get" name="sphinx_package_search_form_{$block.block_id}">
        <input type="hidden" name="dispatch" value="sphinx_booking.package_search" />

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_destination")|default:"Destination"} *</label>
                <select name="destination_id" required data-role="pkg-destination" style="width: 100%;">
                    <option value="">{__("sphinx_holidays.pkg_loading")|default:"Loading..."}</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_departure_city")|default:"Departure city"}</label>
                <select name="departure_id" data-role="pkg-departure" style="width: 100%;">
                    <option value="">{__("sphinx_holidays.pkg_any")|default:"Any"}</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_transport")|default:"Transport"}</label>
                <select name="transport" data-role="pkg-transport" style="width: 100%;">
                    <option value="">{__("sphinx_holidays.pkg_any")|default:"Any"}</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_departure_date")|default:"Departure date"} *</label>
                <input type="date" name="departure_date" required data-role="pkg-date" style="width: 100%;" />
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_nights")|default:"Nights"}</label>
                <select name="nights" style="width: 100%;">
                    {foreach from=[5, 6, 7, 8, 9, 10, 11, 12, 14] item=n}
                        <option value="{$n}" {if $n == 7}selected{/if}>{$n}</option>
                    {/foreach}
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_adults")|default:"Adults"}</label>
                <select name="adults" style="width: 100%;">
                    {foreach from=[1, 2, 3, 4, 5, 6] item=a}
                        <option value="{$a}" {if $a == 2}selected{/if}>{$a}</option>
                    {/foreach}
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_children")|default:"Children"}</label>
                <select name="children" data-role="pkg-children" style="width: 100%;">
                    {foreach from=[0, 1, 2, 3, 4] item=c}
                        <option value="{$c}">{$c}</option>
                    {/foreach}
                </select>
            </div>
            <div data-role="pkg-ages-wrap" style="display: none;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">{__("sphinx_holidays.pkg_children_ages")|default:"Children ages"}</label>
                <input type="text" name="children_ages" placeholder="{__("sphinx_holidays.pkg_children_ages_hint")|default:"e.g. 5,9"}" style="width: 100%;" />
            </div>
            <div>
                <button type="submit" class="ty-btn ty-btn__primary" style="width: 100%;">{__("sphinx_holidays.pkg_search")|default:"Search packages"}</button>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    var widget = document.getElementById('{$pkg_widget_id}');
    if (!widget) return;

    // Today as the date-picker floor.
    var dateInput = widget.querySelector('[data-role="pkg-date"]');
    if (dateInput) {
        var today = new Date();
        var iso = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);
        dateInput.setAttribute('min', iso);
    }

    // Children ages input appears only when children are travelling.
    var childrenSelect = widget.querySelector('[data-role="pkg-children"]');
    var agesWrap = widget.querySelector('[data-role="pkg-ages-wrap"]');
    if (childrenSelect && agesWrap) {
        childrenSelect.addEventListener('change', function() {
            agesWrap.style.display = parseInt(childrenSelect.value, 10) > 0 ? '' : 'none';
        });
    }

    // Fill destination/departure/transport from the synced route cache.
    var url = '{"sphinx_booking.cache_deals"|fn_url}';
    url += (url.indexOf('?') > -1 ? '&' : '?') + 'type=package_routes';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        var data;
        try {
            data = JSON.parse(xhr.responseText);
        } catch (e) {
            return;
        }
        if (!data || !data.success) return;

        var destSelect = widget.querySelector('[data-role="pkg-destination"]');
        if (destSelect) {
            var destHtml = '<option value="">--</option>';
            var destinations = data.destinations || [];
            for (var i = 0; i < destinations.length; i++) {
                destHtml += '<option value="' + destinations[i].id + '">' + destinations[i].name + '</option>';
            }
            destSelect.innerHTML = destHtml;
        }

        var depSelect = widget.querySelector('[data-role="pkg-departure"]');
        if (depSelect) {
            var departures = data.departures || [];
            for (var j = 0; j < departures.length; j++) {
                var depOpt = document.createElement('option');
                depOpt.value = departures[j].id;
                depOpt.textContent = departures[j].name;
                depSelect.appendChild(depOpt);
            }
        }

        var trSelect = widget.querySelector('[data-role="pkg-transport"]');
        if (trSelect) {
            var transports = data.transport_types || [];
            for (var k = 0; k < transports.length; k++) {
                var trOpt = document.createElement('option');
                trOpt.value = transports[k];
                trOpt.textContent = transports[k].charAt(0).toUpperCase() + transports[k].slice(1);
                trSelect.appendChild(trOpt);
            }
        }
    };
    xhr.send();
})();
</script>
