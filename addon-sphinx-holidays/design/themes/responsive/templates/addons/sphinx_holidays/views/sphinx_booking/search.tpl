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

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    <div class="travel-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {* Loading skeleton — shown while JS polls for results *}
    <div class="sphinx-loading-skeleton" style="display: none;">
        <div class="sphinx-loading-message">
            <div class="sphinx-spinner"></div>
            <span>{__("sphinx_holidays.searching_please_wait")|default:"Searching for hotels... This may take up to a minute."}</span>
        </div>
        {foreach from=[1,2,3] item=i}
            <div class="sphinx-offer-card sphinx-skeleton-card">
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
            <h2 class="sphinx-results-title" id="sphinx-results-title">
                {__("sphinx_holidays.search_results", ["[count]" => $sphinx_search_results|count])|default:"`$sphinx_search_results|count` results found"}
            </h2>
        {else}
            <h2 class="sphinx-results-title" id="sphinx-results-title" style="display: none;">
                <span id="sphinx-results-count">0</span> {__("sphinx_holidays.results_found")|default:"results found"}
            </h2>
        {/if}

        {foreach from=$sphinx_search_results item=result name=results}
            <div class="sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                {* Hotel info *}
                <div class="sphinx-offer-hotel">
                    {if $result.hotel_image}
                        <img src="{$result.hotel_image}" alt="{$result.hotel_name|escape:html}" class="sphinx-offer-image" loading="lazy">
                    {/if}
                    <div class="sphinx-offer-hotel-info">
                        <h3 class="sphinx-offer-hotel-name">{$result.hotel_name|escape:html}</h3>
                        {if $result.star_rating}
                            <span class="sphinx-stars">{"★"|str_repeat:$result.star_rating}</span>
                        {/if}
                        {if $result.destination}
                            <span class="sphinx-offer-location">{$result.destination|escape:html}</span>
                        {/if}
                    </div>
                </div>

                {* Offer details *}
                <div class="sphinx-offer-details">
                    <div class="sphinx-offer-room">
                        <strong>{$result.room_name|default:$result.room_type|escape:html}</strong>
                    </div>
                    <div class="sphinx-offer-board">
                        {$result.board_name|default:$result.board_type|escape:html}
                    </div>
                    <div class="sphinx-offer-dates">
                        {$sphinx_search_params.check_in|date_format:"%d.%m.%Y"} - {$sphinx_search_params.check_out|date_format:"%d.%m.%Y"}
                        ({$sphinx_search_params.nights} {__("travel_core.nights")|default:"nights"})
                    </div>
                </div>

                {* Price and action *}
                <div class="sphinx-offer-price-action">
                    <div class="sphinx-offer-price">
                        <span class="sphinx-price-amount">{$result.price|number_format:2:",":"."}</span>
                        <span class="sphinx-price-currency">{$sphinx_search_params.currency|default:'EUR'}</span>
                        {if $sphinx_search_params.nights > 0}
                            <span class="sphinx-price-per-night">
                                ({($result.price / $sphinx_search_params.nights)|number_format:2:",":"."} / {__("sphinx_holidays.per_night")|default:"night"})
                            </span>
                        {/if}
                    </div>
                    <a href="{"sphinx_booking.booking_form?offer_id=`$result.offer_id`&hotel_id=`$result.hotel_id`&product_id=`$result.product_id`&check_in=`$sphinx_search_params.check_in`&check_out=`$sphinx_search_params.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`"|fn_url}"
                       class="sphinx-offer-book-btn">
                        {__("sphinx_holidays.book_now")|default:"Book now"}
                    </a>
                </div>

            </div>
        {/foreach}
    </div>

    {* No-results state *}
    {if !$sphinx_search_results && $sphinx_search_status !== 'pending'}
        <div class="sphinx-no-results" id="sphinx-no-results">
            <p>{__("sphinx_holidays.no_results")|default:"No hotels found for your search criteria. Please try different dates or destination."}</p>
        </div>
    {/if}

</div>

{* Async polling logic *}
<script>
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
    var cursor = null;
    var pollCount = 0;
    var maxPolls = 30;
    var pollInterval = 2000;
    var maxResults = 50;
    var pollUrl = window.TravelBookingConfig && window.TravelBookingConfig.searchPollDispatch
        ? window.TravelBookingConfig.searchPollDispatch
        : (document.body.getAttribute('data-fn-search-poll-url') || '');

    var searchParams = {{/literal}{
        check_in: "{$sphinx_search_params.check_in|escape:javascript}",
        check_out: "{$sphinx_search_params.check_out|escape:javascript}",
        nights: {$sphinx_search_params.nights|default:0},
        currency: "{$sphinx_search_params.currency|default:'EUR'|escape:javascript}",
        adults: {$sphinx_search_params.adults|default:2},
        children: {$sphinx_search_params.children|default:0},
        children_ages: "{$sphinx_search_params.children_ages|escape:javascript}",
        rooms: {$sphinx_search_params.rooms|default:1}
    }{literal};

    function renderCard(result) {
        var stars = '';
        if (result.star_rating) {
            for (var i = 0; i < parseInt(result.star_rating, 10); i++) stars += '★';
        }
        var price = parseFloat(result.price || 0).toFixed(2).replace('.', ',');
        var perNight = searchParams.nights > 0
            ? ' <span class="sphinx-price-per-night">(' +
              (parseFloat(result.price) / searchParams.nights).toFixed(2).replace('.', ',') +
              ' / night)</span>'
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

        var card = document.createElement('div');
        card.className = 'sphinx-offer-card';
        card.setAttribute('data-offer-id', result.offer_id || '');
        card.innerHTML =
            '<div class="sphinx-offer-hotel">' +
                (result.hotel_image
                    ? '<img src="' + result.hotel_image + '" alt="" class="sphinx-offer-image" loading="lazy">'
                    : '') +
                '<div class="sphinx-offer-hotel-info">' +
                    '<h3 class="sphinx-offer-hotel-name"></h3>' +
                    (stars ? '<span class="sphinx-stars">' + stars + '</span>' : '') +
                    (result.destination ? '<span class="sphinx-offer-location"></span>' : '') +
                '</div>' +
            '</div>' +
            '<div class="sphinx-offer-details">' +
                '<div class="sphinx-offer-room"><strong class="sx-room"></strong></div>' +
                '<div class="sphinx-offer-board sx-board"></div>' +
            '</div>' +
            '<div class="sphinx-offer-price-action">' +
                '<div class="sphinx-offer-price">' +
                    '<span class="sphinx-price-amount">' + price + '</span> ' +
                    '<span class="sphinx-price-currency">' + (result.currency || searchParams.currency) + '</span>' +
                    perNight +
                '</div>' +
                '<a href="' + bookingUrl + '" class="sphinx-offer-book-btn">Book now</a>' +
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
            if (accumulated >= maxResults) break;
            container.appendChild(renderCard(results[i]));
            accumulated++;
        }
        if (title) {
            title.style.display = '';
            if (countEl) countEl.textContent = accumulated;
        }
    }

    function finish() {
        if (skeleton) skeleton.style.display = 'none';
        if (accumulated === 0 && noResults) {
            noResults.style.display = '';
        }
    }

    function poll() {
        if (pollCount >= maxPolls || accumulated >= maxResults) {
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
                appendResults(data.results || []);
                cursor = data.next_cursor || null;

                if (data.status === 'completed' || !cursor) {
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
