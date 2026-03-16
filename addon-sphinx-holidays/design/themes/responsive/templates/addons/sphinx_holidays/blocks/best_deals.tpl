{*
 * Sphinx Best Deals Widget Block
 *
 * Displays cached hotel/package deals from the Sphinx cache endpoint.
 * Loads deals via AJAX from the cache_deals controller mode.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

{assign var="deals_type" value=$block.properties.deals_type|default:"hotels"}
{assign var="deals_limit" value=$block.properties.deals_limit|default:6}
{assign var="deals_destination_id" value=$block.properties.destination_id|default:0}
{assign var="widget_id" value="sphinx_best_deals_`$block.block_id`"}

<div id="{$widget_id}" class="sphinx-best-deals-widget" data-type="{$deals_type}" data-limit="{$deals_limit}" data-destination-id="{$deals_destination_id}">

    <div class="sphinx-deals-loading" style="text-align: center; padding: 40px;">
        <i class="icon-refresh icon-spin"></i> {__("sphinx_holidays.loading_deals")|default:"Loading deals..."}
    </div>

    <div class="sphinx-deals-grid" style="display: none; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
        {* Populated by JavaScript *}
    </div>

    <div class="sphinx-deals-empty" style="display: none; text-align: center; padding: 20px; color: #666;">
        {__("sphinx_holidays.no_deals_available")|default:"No deals available at this time."}
    </div>

</div>

<script>
(function() {
    var widget = document.getElementById('{$widget_id}');
    if (!widget) return;

    var type = widget.getAttribute('data-type') || 'hotels';
    var limit = widget.getAttribute('data-limit') || 6;
    var destinationId = widget.getAttribute('data-destination-id') || 0;

    var url = '{"sphinx_booking.cache_deals"|fn_url}';
    url += (url.indexOf('?') > -1 ? '&' : '?') + 'type=' + type + '&limit=' + limit;
    if (destinationId > 0) {
        url += '&destination_id=' + destinationId;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        var loading = widget.querySelector('.sphinx-deals-loading');
        var grid = widget.querySelector('.sphinx-deals-grid');
        var empty = widget.querySelector('.sphinx-deals-empty');

        if (loading) loading.style.display = 'none';

        try {
            var data = JSON.parse(xhr.responseText);
            if (data.success && data.deals && data.deals.length > 0) {
                var html = '';
                for (var i = 0; i < data.deals.length; i++) {
                    var deal = data.deals[i];
                    var currency = data.currency || 'EUR';
                    var currSymbol = { 'EUR': '\u20ac', 'USD': '$', 'GBP': '\u00a3', 'RON': 'lei', 'BGN': '\u043b\u0432' }[currency] || currency;

                    html += '<div class="sphinx-deal-card" style="background: #fff; border: 1px solid #e0e7ef; border-radius: 8px; overflow: hidden;">';
                    if (deal.image) {
                        html += '<img src="' + deal.image + '" alt="' + (deal.hotel_name || '').replace(/"/g, '&quot;') + '" style="width: 100%; height: 180px; object-fit: cover;" loading="lazy">';
                    }
                    html += '<div style="padding: 12px;">';
                    html += '<h4 style="margin: 0 0 5px; color: #003580; font-size: 16px;">' + (deal.hotel_name || '') + '</h4>';
                    if (deal.destination) {
                        html += '<div style="font-size: 13px; color: #666; margin-bottom: 8px;">' + deal.destination + '</div>';
                    }
                    if (deal.star_rating > 0) {
                        html += '<div style="color: #f5a623; margin-bottom: 5px;">' + '\u2605'.repeat(deal.star_rating) + '</div>';
                    }
                    if (deal.room_name) {
                        html += '<div style="font-size: 13px; color: #333;">' + deal.room_name + '</div>';
                    }
                    if (deal.board_name) {
                        html += '<div style="font-size: 12px; color: #666;">' + deal.board_name + '</div>';
                    }
                    if (deal.check_in && deal.nights > 0) {
                        html += '<div style="font-size: 12px; color: #999; margin-top: 5px;">' + deal.nights + ' nights</div>';
                    }
                    html += '<div style="margin-top: 10px; font-size: 22px; font-weight: 700; color: #003580;">';
                    html += parseFloat(deal.price).toFixed(2).replace('.', ',') + ' ' + currSymbol;
                    html += '</div>';
                    html += '</div></div>';
                }
                if (grid) {
                    grid.innerHTML = html;
                    grid.style.display = 'grid';
                }
            } else {
                if (empty) empty.style.display = 'block';
            }
        } catch (e) {
            if (empty) empty.style.display = 'block';
        }
    };
    xhr.onerror = function() {
        var loading = widget.querySelector('.sphinx-deals-loading');
        var empty = widget.querySelector('.sphinx-deals-empty');
        if (loading) loading.style.display = 'none';
        if (empty) empty.style.display = 'block';
    };
    xhr.send();
})();
</script>
