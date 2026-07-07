{*
 * Sphinx Hotel Search Results
 *
 * Displays offer cards returned from the Sphinx API. For new searches
 * (status=pending), the page loads with a skeleton loader and JS polls
 * sphinx_booking.search_poll for incremental results. Cached searches
 * render inline immediately.
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}

<div class="travel-search-results-page sphinx-search-results"
     data-search-id="{$sphinx_search_id|escape:html}"
     data-search-status="{$sphinx_search_status|default:'idle'}">

    {* ===== HOTEL HEADER — styled by travel_core's shared search-results.css ===== *}
    {if $sphinx_hotel_name}
        <div class="travel-hotel-header sphinx-hotel-header">
            <h1 class="sphinx-hotel-header-name">
                {$sphinx_hotel_name|escape:html}
                {if $sphinx_hotel_stars}<span class="travel-hotel-stars sphinx-stars">{"★"|str_repeat:$sphinx_hotel_stars}</span>{/if}
            </h1>
            {if $sphinx_hotel_location}
                <p class="travel-hotel-location sphinx-hotel-header-location">{$sphinx_hotel_location|escape:html}</p>
            {/if}
        </div>
    {/if}

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    <div class="travel-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {* Loading skeleton — shown while JS polls for results *}
    <div class="sphinx-loading-skeleton" style="display: none;">
        <div class="sphinx-loading-message">
            <div class="sphinx-spinner"></div>
            <span>{__("sphinx_holidays.searching_please_wait")|default:"Searching for live offers…"}</span>
            {if $sphinx_from_price}
                <div class="sphinx-from-price" style="margin-top: 8px; font-size: 15px; color: #003580;">
                    {__("sphinx_holidays.from")|default:"from"}
                    <strong>{$sphinx_from_price.price|number_format:2:",":"."} {$sphinx_from_price.currency|default:'EUR'|escape:html}</strong>
                </div>
            {/if}
        </div>
        {foreach from=[1,2,3] item=i}
            <div class="travel-offer-card sphinx-offer-card sphinx-skeleton-card">
                <div class="sphinx-offer-hotel">
                    <div class="sphinx-skeleton-img"></div>
                    <div class="sphinx-offer-hotel-info">
                        <div class="sphinx-skeleton-line sphinx-skeleton-title"></div>
                        <div class="sphinx-skeleton-line sphinx-skeleton-short"></div>
                    </div>
                </div>
                <div class="sphinx-offer-details">
                    <div class="sphinx-skeleton-line"></div>
                    <div class="sphinx-skeleton-line sphinx-skeleton-short"></div>
                </div>
                <div class="sphinx-offer-price-action">
                    <div class="sphinx-skeleton-line sphinx-skeleton-price"></div>
                </div>
            </div>
        {/foreach}
    </div>

    {* Results container *}
    <div class="sphinx-results-container" id="sphinx-results-container">
        {if $sphinx_search_results}
            <div class="travel-availability-badge sphinx-results-title" id="sphinx-results-title">
                ✓ {__("sphinx_holidays.search_results", ["[count]" => $sphinx_search_results|count])|default:"`$sphinx_search_results|count` results found"}
            </div>
        {else}
            <div class="travel-availability-badge sphinx-results-title" id="sphinx-results-title" style="display: none;">
                ✓ <span id="sphinx-results-count">0</span> {__("sphinx_holidays.results_found")|default:"results found"}
            </div>
        {/if}

        {foreach from=$sphinx_search_results item=result name=results}
            <div class="travel-offer-card sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                {* Hotel info *}
                <div class="travel-offer-hotel sphinx-offer-hotel">
                    {if $result.hotel_image}
                        <img src="{$result.hotel_image}" alt="{$result.hotel_name|escape:html}" class="travel-offer-image sphinx-offer-image" loading="lazy">
                    {/if}
                    <div class="sphinx-offer-hotel-info">
                        <h3 class="travel-offer-hotel-name sphinx-offer-hotel-name">{$result.hotel_name|escape:html}</h3>
                        {if $result.star_rating}
                            <span class="travel-hotel-stars sphinx-stars">{"★"|str_repeat:$result.star_rating}</span>
                        {/if}
                        {if $result.destination}
                            <span class="travel-offer-location sphinx-offer-location">{$result.destination|escape:html}</span>
                        {/if}
                    </div>
                </div>

                {* Offer details *}
                <div class="travel-offer-details sphinx-offer-details">
                    <div class="travel-offer-room sphinx-offer-room">
                        {$result.room_name|default:$result.room_type|escape:html}
                    </div>
                    <div class="travel-offer-board sphinx-offer-board">
                        {$result.board_name|default:$result.board_type|escape:html}
                    </div>
                    <div class="travel-offer-dates sphinx-offer-dates">
                        {$sphinx_search_params.check_in|date_format:"%d.%m.%Y"} - {$sphinx_search_params.check_out|date_format:"%d.%m.%Y"}
                        ({$sphinx_search_params.nights} {__("travel_core.nights")|default:"nights"})
                    </div>
                    {if $result.confirmation == 'immediate'}
                        <span class="travel-offer-badge--instant">✓ {__("sphinx_holidays.instant_confirmation")|default:"Instant confirmation"}</span>
                    {/if}
                </div>

                {* Price and action *}
                <div class="travel-offer-price-action sphinx-offer-price-action">
                    <div class="travel-offer-price sphinx-offer-price">
                        <span class="travel-price-amount sphinx-price-amount">{$result.price|number_format:2:",":"."}</span>
                        <span class="travel-price-currency sphinx-price-currency">{$sphinx_search_params.currency|default:'EUR'}</span>
                        {if $sphinx_search_params.nights > 0}
                            <span class="travel-price-per-night sphinx-price-per-night">
                                {($result.price / $sphinx_search_params.nights)|number_format:2:",":"."} / {__("sphinx_holidays.per_night")|default:"night"}
                            </span>
                        {/if}
                        <span class="travel-price-includes">{__("sphinx_holidays.includes_taxes")|default:"Includes taxes and commissions"}</span>
                    </div>
                    <a href="{"sphinx_booking.booking_form?offer_id=`$result.offer_id`&hotel_id=`$result.hotel_id`&product_id=`$result.product_id`&check_in=`$sphinx_search_params.check_in`&check_out=`$sphinx_search_params.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`"|fn_url}"
                       class="travel-offer-book-btn sphinx-offer-book-btn">
                        {__("sphinx_holidays.book_now")|default:"Book now"}
                    </a>
                </div>

            </div>
        {/foreach}
    </div>

    {* No-results state — always in the DOM so the async poller (which runs when
       status='pending') can reveal it once polling completes with 0 offers.
       Visible immediately only when a server-side search already finished empty
       (completed/error); hidden while pending/idle or when offers exist. *}
    {assign var="_sx_show_empty" value=(!$sphinx_search_results && ($sphinx_search_status == 'completed' || $sphinx_search_status == 'error'))}
    <div class="sphinx-no-results" id="sphinx-no-results"{if !$_sx_show_empty} style="display: none;"{/if}
         data-alt-heading="{__("sphinx_holidays.try_nearby_dates")|default:"Try nearby dates:"|escape:html}"
         data-alt-from="{__("sphinx_holidays.alt_from")|default:"from"|escape:html}">
        <p>{__("sphinx_holidays.no_results")|default:"No availability for the selected dates. Please try different dates."}</p>
        <div id="sphinx-alt-dates" style="display: none; margin-top: 12px;"></div>
    </div>

</div>

{* Async polling logic *}
<script>
window.__sphinxSearchParams = {
    check_in: "{$sphinx_search_params.check_in|default:''|escape:javascript}",
    check_out: "{$sphinx_search_params.check_out|default:''|escape:javascript}",
    nights: {$sphinx_search_params.nights|default:0},
    currency: "{$sphinx_search_params.currency|default:'EUR'|escape:javascript}",
    adults: {$sphinx_search_params.adults|default:2},
    children: {$sphinx_search_params.children|default:0},
    children_ages: "{$sphinx_search_params.children_ages|default:''|escape:javascript}",
    rooms: {$sphinx_search_params.rooms|default:1}
};
window.__sphinxConfig = {
    maxPolls: {$sphinx_max_polls|default:30},
    pollInterval: 250,
    labels: {
        perNight: "{__("sphinx_holidays.per_night")|default:"night"|escape:javascript}",
        instantConfirmation: "{__("sphinx_holidays.instant_confirmation")|default:"Instant confirmation"|escape:javascript}",
        includesTaxes: "{__("sphinx_holidays.includes_taxes")|default:"Includes taxes and commissions"|escape:javascript}",
        bookNow: "{__("sphinx_holidays.book_now")|default:"Book now"|escape:javascript}",
        nights: "{__("travel_core.nights")|default:"nights"|escape:javascript}"
    }
};
{literal}
(function() {
    var root = document.querySelector('.sphinx-search-results');
    if (!root) return;

    var searchId = root.getAttribute('data-search-id');
    var status = root.getAttribute('data-search-status');
    if (!searchId || status !== 'pending') return;

    var container = document.getElementById('sphinx-results-container');
    var title = document.getElementById('sphinx-results-title');
    var countEl = document.getElementById('sphinx-results-count');
    var skeleton = document.querySelector('.sphinx-loading-skeleton');
    var noResults = document.getElementById('sphinx-no-results');

    if (skeleton) skeleton.style.display = 'block';
    if (noResults) noResults.style.display = 'none';

    var accumulated = 0;
    var revealed = false;
    var cursor = null;
    var pollCount = 0;
    var lastAlternatives = [];
    var cfg = window.__sphinxConfig || {};
    var maxPolls = cfg.maxPolls || 30;
    var pollInterval = cfg.pollInterval || 250;
    var pollUrl = window.TravelBookingConfig && window.TravelBookingConfig.searchPollDispatch
        ? window.TravelBookingConfig.searchPollDispatch
        : (document.body.getAttribute('data-fn-search-poll-url') || '');

    var searchParams = window.__sphinxSearchParams || {};

    function renderCard(result) {
        var stars = '';
        if (result.star_rating) {
            for (var i = 0; i < parseInt(result.star_rating, 10); i++) stars += '★';
        }
        var labels = cfg.labels || {};
        var price = parseFloat(result.price || 0).toFixed(2).replace('.', ',');
        var perNight = searchParams.nights > 0
            ? '<span class="travel-price-per-night sphinx-price-per-night">' +
              (parseFloat(result.price) / searchParams.nights).toFixed(2).replace('.', ',') +
              ' / ' + (labels.perNight || 'night') + '</span>'
            : '';

        var bookingUrl = 'index.php?dispatch=sphinx_booking.booking_form' +
            '&offer_id=' + encodeURIComponent(result.offer_id || '') +
            '&hotel_id=' + encodeURIComponent(result.hotel_id || '') +
            '&product_id=' + encodeURIComponent(result.product_id || '') +
            '&check_in=' + encodeURIComponent(searchParams.check_in) +
            '&check_out=' + encodeURIComponent(searchParams.check_out) +
            '&adults=' + searchParams.adults +
            '&children=' + searchParams.children +
            '&children_ages=' + encodeURIComponent(searchParams.children_ages) +
            '&rooms=' + searchParams.rooms;

        var datesLine = searchParams.check_in && searchParams.check_out
            ? formatAltDate(searchParams.check_in) + '.' + searchParams.check_in.slice(0, 4) +
              ' - ' + formatAltDate(searchParams.check_out) + '.' + searchParams.check_out.slice(0, 4) +
              (searchParams.nights > 0 ? ' (' + searchParams.nights + ' ' + (labels.nights || 'nights') + ')' : '')
            : '';

        var card = document.createElement('div');
        card.className = 'travel-offer-card sphinx-offer-card';
        card.setAttribute('data-offer-id', result.offer_id || '');
        card.innerHTML =
            '<div class="travel-offer-hotel sphinx-offer-hotel">' +
                (result.hotel_image
                    ? '<img src="' + result.hotel_image + '" alt="" class="travel-offer-image sphinx-offer-image" loading="lazy">'
                    : '') +
                '<div class="sphinx-offer-hotel-info">' +
                    '<h3 class="travel-offer-hotel-name sphinx-offer-hotel-name"></h3>' +
                    (stars ? '<span class="travel-hotel-stars sphinx-stars">' + stars + '</span>' : '') +
                    (result.destination ? '<span class="travel-offer-location sphinx-offer-location"></span>' : '') +
                '</div>' +
            '</div>' +
            '<div class="travel-offer-details sphinx-offer-details">' +
                '<div class="travel-offer-room sphinx-offer-room"><span class="sx-room"></span></div>' +
                '<div class="travel-offer-board sphinx-offer-board sx-board"></div>' +
                (datesLine ? '<div class="travel-offer-dates sphinx-offer-dates">' + datesLine + '</div>' : '') +
                (result.confirmation === 'immediate'
                    ? '<span class="travel-offer-badge--instant">✓ ' + (labels.instantConfirmation || 'Instant confirmation') + '</span>'
                    : '') +
            '</div>' +
            '<div class="travel-offer-price-action sphinx-offer-price-action">' +
                '<div class="travel-offer-price sphinx-offer-price">' +
                    '<span class="travel-price-amount sphinx-price-amount">' + price + '</span> ' +
                    '<span class="travel-price-currency sphinx-price-currency">' + (result.currency || searchParams.currency) + '</span>' +
                    perNight +
                    '<span class="travel-price-includes">' + (labels.includesTaxes || 'Includes taxes and commissions') + '</span>' +
                '</div>' +
                '<a href="' + bookingUrl + '" class="travel-offer-book-btn sphinx-offer-book-btn">' + (labels.bookNow || 'Book now') + '</a>' +
            '</div>';

        // Set text nodes safely to avoid XSS
        card.querySelector('.sphinx-offer-hotel-name').textContent = result.hotel_name || '';
        if (result.destination) card.querySelector('.sphinx-offer-location').textContent = result.destination;
        card.querySelector('.sx-room').textContent = result.room_name || result.room_type || '';
        card.querySelector('.sx-board').textContent = result.board_name || result.board_type || '';

        return card;
    }

    function appendResults(results) {
        if (!results || !results.length) return;
        for (var i = 0; i < results.length; i++) {
            container.appendChild(renderCard(results[i]));
            accumulated++;
        }
        if (title) {
            title.style.display = '';
            if (countEl) countEl.textContent = accumulated;
        }
    }

    function reveal() {
        // First offers in: drop the skeleton so the user sees results
        // immediately, while the poll loop keeps draining in the background.
        if (revealed) return;
        revealed = true;
        if (skeleton) skeleton.style.display = 'none';
    }

    function formatAltDate(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] : iso;
    }

    function renderAlternatives() {
        var box = document.getElementById('sphinx-alt-dates');
        if (!box || !noResults || !lastAlternatives.length) return;
        var heading = noResults.getAttribute('data-alt-heading') || 'Try nearby dates:';
        var fromLabel = noResults.getAttribute('data-alt-from') || 'from';
        var html = '<strong>' + heading + '</strong><div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;">';
        for (var i = 0; i < lastAlternatives.length; i++) {
            var alt = lastAlternatives[i];
            if (!alt || !alt.check_in || !alt.check_out) continue;
            var link = new URL(window.location.href);
            link.searchParams.set('check_in', alt.check_in);
            link.searchParams.set('check_out', alt.check_out);
            var label = formatAltDate(alt.check_in) + ' – ' + formatAltDate(alt.check_out);
            if (alt.price > 0) {
                label += ' · ' + fromLabel + ' ' + Number(alt.price).toFixed(0) + ' ' + (alt.currency === 'EUR' ? '€' : (alt.currency || ''));
            }
            html += '<a class="ty-btn" href="' + link.toString() + '" style="padding:6px 12px;">' + label + '</a>';
        }
        html += '</div>';
        box.innerHTML = html;
        box.style.display = '';
    }

    function finish() {
        if (skeleton) skeleton.style.display = 'none';
        if (accumulated === 0 && noResults) {
            noResults.style.display = '';
            renderAlternatives();
        }
    }

    function poll() {
        if (pollCount >= maxPolls) {
            finish();
            return;
        }
        pollCount++;

        var url = 'index.php?dispatch=sphinx_booking.search_poll' +
                  '&search_id=' + encodeURIComponent(searchId) +
                  (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'error') {
                    finish();
                    return;
                }
                if (data.alternatives && data.alternatives.length) {
                    lastAlternatives = data.alternatives;
                }
                appendResults(data.results || []);

                // Render early: as soon as the hotel has offers, show them and
                // drop the skeleton for a fast first paint. Do NOT stop — keep
                // polling so search_poll drains the stream and caches the
                // COMPLETE set (also catches offers split across pages).
                // docs/adr/0001-availability-early-render-and-metrics.md
                if (accumulated > 0) {
                    reveal();
                }

                // Keep polling while a cursor remains (the continuation signal);
                // maxPolls bounds the loop as a safety net. On a maxPolls bail we
                // do NOT finalize, so a partial set is never cached as complete.
                cursor = data.next_cursor || null;
                if (!cursor) {
                    finish();
                    return;
                }
                setTimeout(poll, pollInterval);
            })
            .catch(function() {
                finish();
            });
    }

    // Kick off polling
    poll();
})();
{/literal}
</script>

<style>
.sphinx-loading-skeleton .sphinx-loading-message {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f0f4f8;
    border-radius: 6px;
    margin-bottom: 20px;
    color: #555;
    font-size: 14px;
}
.sphinx-spinner {
    width: 20px;
    height: 20px;
    border: 3px solid #cfd8dc;
    border-top-color: #003580;
    border-radius: 50%;
    animation: sphinx-spin 0.8s linear infinite;
}
@keyframes sphinx-spin { to { transform: rotate(360deg); } }
.sphinx-skeleton-card { opacity: 0.6; }
.sphinx-skeleton-img {
    width: 120px;
    height: 80px;
    background: linear-gradient(90deg, #eee 0%, #f5f5f5 50%, #eee 100%);
    background-size: 200% 100%;
    animation: sphinx-shimmer 1.5s infinite;
    border-radius: 4px;
}
.sphinx-skeleton-line {
    height: 14px;
    background: linear-gradient(90deg, #eee 0%, #f5f5f5 50%, #eee 100%);
    background-size: 200% 100%;
    animation: sphinx-shimmer 1.5s infinite;
    border-radius: 3px;
    margin: 6px 0;
}
.sphinx-skeleton-title { width: 70%; height: 18px; }
.sphinx-skeleton-short { width: 40%; }
.sphinx-skeleton-price { width: 100px; height: 24px; }
@keyframes sphinx-shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
</style>
