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

    {* Availability status — same split layout as novoton's: a compact
       "✓ Available" pill with the guest-count confirmation as plain text
       below it ("N room(s), M offer(s) for X adults[, Y children]").
       Rooms = DISTINCT room types (two boards of one room count once); the
       party suffix ties availability to the searched guests. The COUNT LINE
       is (re)written by the poll JS as offers stream in (the pill is static);
       data-party-suffix carries the server-rendered party for that JS. *}
    {$sx_badge_room_keys = []}
    {foreach from=$sphinx_search_results item=__sx_r}
        {$__sx_rk = $__sx_r.room_name|default:$__sx_r.room_type|default:''}
        {$__sx_rk = $__sx_rk|trim|lower}
        {$sx_badge_room_keys[$__sx_rk] = 1}
    {/foreach}
    {$sx_badge_rooms = $sx_badge_room_keys|count}
    {$sx_badge_offers = $sphinx_search_results|count}
    {$sx_badge_adults = $sphinx_search_params.adults|default:0}
    {$sx_badge_children = $sphinx_search_params.children|default:0}
    {* Guests bullet (what was searched) — the party WITHOUT the "for" connector,
       since it now sits on its own line. Carried in data-party-suffix so the
       poll JS can rebuild the same bullet as offers stream in. *}
    {capture assign="sx_badge_guests"}{$sx_badge_adults} {if $sx_badge_adults == 1}{__("sphinx_holidays.adult")|default:"adult"|lower}{else}{__("sphinx_holidays.adults")|default:"adults"|lower}{/if}{if $sx_badge_children > 0}, {$sx_badge_children} {if $sx_badge_children == 1}{__("sphinx_holidays.child")|default:"child"|lower}{else}{__("sphinx_holidays.children")|default:"children"|lower}{/if}{/if}{/capture}
    {capture assign="sx_badge_html"}<div class="travel-availability-block sphinx-results-title" id="sphinx-results-title" data-party-suffix="{$sx_badge_guests|escape:html}"{if !$sphinx_search_results} style="display: none;"{/if}><span class="travel-availability-badge">✓ {__("sphinx_holidays.available")|default:"Available"}</span><div class="travel-availability-details" id="sphinx-availability-details"><span class="travel-availability-line" id="sphinx-availability-guests">{if $sphinx_search_results}{$sx_badge_guests}{/if}</span><span class="travel-availability-line" id="sphinx-availability-rooms">{if $sphinx_search_results}{$sx_badge_rooms} {if $sx_badge_rooms == 1}{__("sphinx_holidays.room")|default:"room"|lower}{else}{__("sphinx_holidays.rooms")|default:"rooms"|lower}{/if} ({$sx_badge_offers} {if $sx_badge_offers == 1}{__("sphinx_holidays.offer")|default:"offer"|lower}{else}{__("sphinx_holidays.offers")|default:"offers"|lower}{/if}){/if}</span></div></div>{/capture}

    {* ===== HOTEL HEADER — placed ABOVE the search form (novoton parity);
       availability badge on the right, same row layout as novoton ===== *}
    {if $sphinx_hotel_name}
        <div class="travel-hotel-header sphinx-hotel-header">
            <div class="travel-hotel-header-row">
                <div>
                    {* PDP-parity name: the theme's product-title class carries the
                       exact font/size/weight/color the product page uses. *}
                    <h1 class="ty-product-block-title sphinx-hotel-header-name">
                        {if $sphinx_search_params.product_id}
                            <a href="{"products.view?product_id=`$sphinx_search_params.product_id`"|fn_url}" class="travel-hotel-name-link"><bdi>{$sphinx_hotel_name|escape:html}</bdi></a>
                        {else}
                            <bdi>{$sphinx_hotel_name|escape:html}</bdi>
                        {/if}
                        {if $sphinx_hotel_stars}<span class="travel-hotel-stars sphinx-stars" role="img" aria-label="{__("sphinx_holidays.stars_rating", ["[rating]" => $sphinx_hotel_stars])|escape:html}">{"★"|str_repeat:$sphinx_hotel_stars}</span>{/if}
                    </h1>
                    {if $sphinx_hotel_location || $sphinx_hotel_map_url}
                        <p class="travel-hotel-location sphinx-hotel-header-location">
                            {* Text from HotelLocationLine (PDP pipeline); the " - "
                               separator mirrors main_info_title.post.tpl *}
                            {$sphinx_hotel_location|escape:html}{if $sphinx_hotel_map_url}{if $sphinx_hotel_location} - {/if}<a href="{$sphinx_hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-hotel-map-link">{__("sphinx_holidays.location_show_map")|default:"Location - show map"}</a>{/if}
                        </p>
                    {/if}
                </div>
                <div>
                    {$sx_badge_html nofilter}
                </div>
            </div>
        </div>
    {else}
        {$sx_badge_html nofilter}
    {/if}

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    <div class="travel-search-form-wrapper">
        {$booking_engine_html nofilter}
    </div>

    {* Loading skeleton — shown while JS polls for results. Styled by
       travel_core's search-results.css; display:none is JS-toggled state. *}
    <div class="sphinx-loading-skeleton" style="display: none;">
        <div class="travel-loading-message sphinx-loading-message">
            <div class="travel-spinner sphinx-spinner"></div>
            <span>{__("sphinx_holidays.searching_please_wait")|default:"Searching for live offers…"}</span>
            {if $sphinx_from_price}
                <div class="travel-from-price sphinx-from-price">
                    {__("sphinx_holidays.from")|default:"from"}
                    <strong>{$sphinx_from_price.price|number_format:2:",":"."} {$sphinx_from_price.currency|default:'EUR'|escape:html}</strong>
                </div>
            {/if}
        </div>
        {foreach from=[1,2,3] item=i}
            <div class="travel-offer-card sphinx-offer-card travel-skeleton-card sphinx-skeleton-card" aria-hidden="true">
                <div class="sphinx-offer-hotel">
                    <div class="travel-skeleton-img sphinx-skeleton-img"></div>
                    <div class="sphinx-offer-hotel-info">
                        <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-title sphinx-skeleton-title"></div>
                        <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-short sphinx-skeleton-short"></div>
                    </div>
                </div>
                <div class="sphinx-offer-details">
                    <div class="travel-skeleton-line sphinx-skeleton-line"></div>
                    <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-short sphinx-skeleton-short"></div>
                </div>
                <div class="sphinx-offer-price-action">
                    <div class="travel-skeleton-line sphinx-skeleton-line travel-skeleton-price sphinx-skeleton-price"></div>
                </div>
            </div>
        {/foreach}
    </div>

    {* Results container — the availability badge lives in the hotel header above *}
    <div class="sphinx-results-container" id="sphinx-results-container">
        {foreach from=$sphinx_search_results item=result name=results}
            <div class="travel-offer-card sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                {* Hotel info *}
                <div class="travel-offer-hotel sphinx-offer-hotel">
                    {if $result.hotel_image}
                        <img src="{$result.hotel_image}" alt="{$result.hotel_name|escape:html}" class="travel-offer-image sphinx-offer-image" width="88" height="64" loading="lazy">
                    {/if}
                    <div class="sphinx-offer-hotel-info">
                        <h3 class="travel-offer-hotel-name sphinx-offer-hotel-name">{$result.hotel_name|escape:html}</h3>
                        {if $result.star_rating}
                            <span class="travel-hotel-stars sphinx-stars" role="img" aria-label="{__("sphinx_holidays.stars_rating", ["[rating]" => $result.star_rating])|escape:html}">{"★"|str_repeat:$result.star_rating}</span>
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
                    {* Terms modal trigger — offer_id read from the card wrapper's data-offer-id *}
                    <a href="#" class="travel-terms-link sphinx-terms-link">{__("sphinx_holidays.cancellation_and_payment_terms")|default:"Condiții de Plată și Anulare"}</a>
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
                    {* Offer room/board names ride the Book-now URL so the
                       booking form + add_to_cart can fall back to them when
                       the verify response omits the names (display-only). *}
                    {$_sx_room_q = $result.room_name|default:$result.room_type|default:''|escape:url}
                    {$_sx_board_q = $result.board_name|default:$result.board_type|default:''|escape:url}
                    <a href="{"sphinx_booking.booking_form?offer_id=`$result.offer_id`&hotel_id=`$result.hotel_id`&product_id=`$result.product_id`&check_in=`$sphinx_search_params.check_in`&check_out=`$sphinx_search_params.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`&room_name=`$_sx_room_q`&board_name=`$_sx_board_q`"|fn_url}"
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
    <div class="travel-no-results sphinx-no-results" id="sphinx-no-results"{if !$_sx_show_empty} style="display: none;"{/if}
         data-alt-heading="{__("sphinx_holidays.try_nearby_dates")|default:"Try nearby dates:"|escape:html}"
         data-alt-from="{__("sphinx_holidays.alt_from")|default:"from"|escape:html}">
        <p>{__("sphinx_holidays.no_results")|default:"No availability for the selected dates. Please try different dates."}</p>
        <div id="sphinx-alt-dates" class="travel-alt-dates" style="display: none;"></div>

        {* "Contact me when available" — stored in the SHARED travel registry
           for INTERNAL follow-up only; no Sphinx API request is created. *}
        <div class="travel-request-box">
            <h4>{__("travel_core.request_alternatives_title")|default:"Can't find what you're looking for?"}</h4>
            <p>{__("travel_core.request_alternatives_desc")|default:"Leave your contact details and we'll get back to you with alternatives for your dates."}</p>
            <form method="post" action="{""|fn_url}" name="sphinx_alt_request_form">
                <input type="hidden" name="dispatch" value="sphinx_booking.request_alternatives">
                <input type="hidden" name="security_hash" value="{$security_hash}">
                <input type="hidden" name="hotel_id" value="{$sphinx_search_params.hotel_id|escape:html}">
                <input type="hidden" name="hotel_name" value="{$sphinx_hotel_name|escape:html}">
                <input type="hidden" name="product_id" value="{$sphinx_search_params.product_id|escape:html}">
                <input type="hidden" name="check_in" value="{$sphinx_search_params.check_in|escape:html}">
                <input type="hidden" name="check_out" value="{$sphinx_search_params.check_out|escape:html}">
                <input type="hidden" name="nights" value="{$sphinx_search_params.nights|escape:html}">
                <input type="hidden" name="adults" value="{$sphinx_search_params.adults|escape:html}">
                <input type="hidden" name="children" value="{$sphinx_search_params.children|escape:html}">
                <input type="hidden" name="children_ages" value="{$sphinx_search_params.children_ages|escape:html}">
                <input type="hidden" name="rooms" value="{$sphinx_search_params.rooms|escape:html}">
                {* Honeypot — humans never see it, bots fill everything *}
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;">
                <div class="travel-request-grid">
                    <input type="email" name="contact_email" required placeholder="{__("email")|default:"E-mail"}">
                    <input type="tel" name="contact_phone" placeholder="{__("phone")|default:"Phone"}">
                    <button type="submit" class="travel-offer-book-btn">{__("travel_core.send_request")|default:"Send"}</button>
                </div>
            </form>
        </div>
    </div>

</div>

{* On-demand payment/cancellation terms modal (loaded via sphinx_booking.offer_terms) *}
<div id="sphinx-terms-modal" class="travel-terms-modal" role="dialog" aria-modal="true" aria-labelledby="sphinx-terms-modal-title" style="display: none;">
    <div class="travel-terms-modal__box">
        <div class="travel-terms-modal__head">
            <h3 id="sphinx-terms-modal-title">{__("sphinx_holidays.cancellation_and_payment_terms")|default:"Condiții de Plată și Anulare"}</h3>
            <button type="button" class="travel-terms-modal__close" aria-label="{__("close")|default:"Close"}">&times;</button>
        </div>
        <div class="travel-terms-modal__body" id="sphinx-terms-modal-body"></div>
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
        nights: "{__("travel_core.nights")|default:"nights"|escape:javascript}",
        starsRating: "{__("sphinx_holidays.stars_rating", ["[rating]" => "%s"])|default:"%s-star rating"|escape:javascript}",
        cancellationAndPaymentTerms: "{__("sphinx_holidays.cancellation_and_payment_terms")|default:"Condiții de Plată și Anulare"|escape:javascript}",
        paymentTerms: "{__("sphinx_holidays.payment_terms")|default:"Termeni de plată"|escape:javascript}",
        cancellationPolicy: "{__("sphinx_holidays.cancellation_policy")|default:"Politica de anulare"|escape:javascript}",
        freeCancellationUntil: "{__("sphinx_holidays.free_cancellation_until")|default:"Anulare gratuită înainte de"|escape:javascript}",
        freeCancellation: "{__("sphinx_holidays.free_cancellation")|default:"Anulare gratuită"|escape:javascript}",
        termsLoading: "{__("sphinx_holidays.terms_loading")|default:"Se încarcă condițiile..."|escape:javascript}",
        termsUnavailable: "{__("sphinx_holidays.terms_unavailable")|default:"Condițiile nu sunt disponibile. Vă rugăm căutați din nou."|escape:javascript}",
        noTermsInfo: "{__("sphinx_holidays.no_terms_info")|default:"Nu există condiții specifice pentru această ofertă."|escape:javascript}",
        termsDueBy: "{__("sphinx_holidays.terms_due_by")|default:"De achitat"|escape:javascript}",
        termsPenalty: "{__("sphinx_holidays.terms_penalty")|default:"Penalizare"|escape:javascript}",
        termsNonRefundable: "{__("sphinx_holidays.terms_non_refundable")|default:"Nerambursabil"|escape:javascript}",
        termsUntil: "{__("sphinx_holidays.terms_until")|default:"până la"|escape:javascript}",
        termsFrom: "{__("sphinx_holidays.terms_from")|default:"de la"|escape:javascript}",
        close: "{__("close")|default:"Close"|escape:javascript}",
        available: "{__("sphinx_holidays.available")|default:"Available"|escape:javascript}",
        room: "{__("sphinx_holidays.room")|default:"room"|lower|escape:javascript}",
        rooms: "{__("sphinx_holidays.rooms")|default:"rooms"|lower|escape:javascript}",
        offer: "{__("sphinx_holidays.offer")|default:"offer"|lower|escape:javascript}",
        offers: "{__("sphinx_holidays.offers")|default:"offers"|lower|escape:javascript}"
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
    // Distinct room types seen so far — the badge counts room TYPES, not offers.
    var seenRoomKeys = {};
    var seenRoomCount = 0;

    // Rebuild the two bullet lines under the compact "✓ Available" pill as
    // offers stream in: guests ("X adults[, Y children]", from the
    // server-rendered data-party-suffix) and rooms/offers
    // ("N rooms (M offers)"). The pill itself is static server markup.
    function updateBadgeText() {
        if (!title) return;
        var l = (window.__sphinxConfig && window.__sphinxConfig.labels) || {};
        var roomLabel = (seenRoomCount === 1) ? (l.room || 'room') : (l.rooms || 'rooms');
        var offerLabel = (accumulated === 1) ? (l.offer || 'offer') : (l.offers || 'offers');
        var guests = title.getAttribute('data-party-suffix') || '';
        var guestsEl = document.getElementById('sphinx-availability-guests');
        var roomsEl = document.getElementById('sphinx-availability-rooms');
        if (guestsEl) {
            guestsEl.textContent = guests;
        }
        if (roomsEl) {
            roomsEl.textContent = seenRoomCount + ' ' + roomLabel
                + ' (' + accumulated + ' ' + offerLabel + ')';
        }
    }
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
            '&rooms=' + searchParams.rooms +
            // Offer room/board names: booking form + add_to_cart fall back to
            // these when the verify response omits the names (display-only).
            '&room_name=' + encodeURIComponent(result.room_name || result.room_type || '') +
            '&board_name=' + encodeURIComponent(result.board_name || result.board_type || '');

        var datesLine = searchParams.check_in && searchParams.check_out
            ? formatAltDate(searchParams.check_in) + '.' + searchParams.check_in.slice(0, 4) +
              ' - ' + formatAltDate(searchParams.check_out) + '.' + searchParams.check_out.slice(0, 4) +
              (searchParams.nights > 0 ? ' (' + searchParams.nights + ' ' + (labels.nights || 'nights') + ')' : '')
            : '';

        var starsLabel = stars
            ? (labels.starsRating || '%s-star rating').replace('%s', String(parseInt(result.star_rating, 10)))
            : '';

        var card = document.createElement('div');
        card.className = 'travel-offer-card sphinx-offer-card';
        card.setAttribute('data-offer-id', result.offer_id || '');
        card.innerHTML =
            '<div class="travel-offer-hotel sphinx-offer-hotel">' +
                (result.hotel_image
                    ? '<img src="' + result.hotel_image + '" alt="" class="travel-offer-image sphinx-offer-image" width="88" height="64" loading="lazy">'
                    : '') +
                '<div class="sphinx-offer-hotel-info">' +
                    '<h3 class="travel-offer-hotel-name sphinx-offer-hotel-name"></h3>' +
                    (stars ? '<span class="travel-hotel-stars sphinx-stars" role="img" aria-label="' + starsLabel + '">' + stars + '</span>' : '') +
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
                '<a href="#" class="travel-terms-link sphinx-terms-link">' + (labels.cancellationAndPaymentTerms || 'Condiții de Plată și Anulare') + '</a>' +
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
            var roomKey = String(results[i].room_name || results[i].room_type || '').trim().toLowerCase();
            if (!seenRoomKeys[roomKey]) {
                seenRoomKeys[roomKey] = true;
                seenRoomCount++;
            }
            container.appendChild(renderCard(results[i]));
            accumulated++;
        }
        if (title) {
            title.style.display = '';
            updateBadgeText();
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
        var html = '<strong>' + heading + '</strong><div class="travel-alt-date-list">';
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
            html += '<a class="ty-btn travel-alt-date-chip" href="' + link.toString() + '">' + label + '</a>';
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

{* Terms modal: on-demand load + open/close, delegated so it covers both the
   server-rendered and poll-rendered cards. *}
<script>
{literal}
(function() {
    var cfg = (window.__sphinxConfig && window.__sphinxConfig.labels) || {};
    var modal = document.getElementById('sphinx-terms-modal');
    if (!modal) return;
    var body = document.getElementById('sphinx-terms-modal-body');
    var lastFocus = null;
    var cache = {};

    function esc(v) {
        var d = document.createElement('div');
        d.textContent = (v == null) ? '' : String(v);
        return d.innerHTML;
    }
    // CS-Cart returns the literal "_key.name" for a MISSING language var —
    // a truthy string, so `cfg.x || fallback` never fires. Treat empty or
    // "_"-prefixed values as missing so a raw key can never render.
    function lbl(v, fb) {
        v = (v == null) ? '' : String(v);
        return (v === '' || v.charAt(0) === '_') ? fb : v;
    }
    function openModal() {
        lastFocus = document.activeElement;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        var c = modal.querySelector('.travel-terms-modal__close');
        if (c) c.focus();
    }
    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function section(title, lines) {
        if (!lines || !lines.length) return '';
        var items = '';
        for (var i = 0; i < lines.length; i++) items += '<li>' + esc(lines[i]) + '</li>';
        return '<div class="travel-terms-modal__section"><strong>' + esc(title) + '</strong><ul>' + items + '</ul></div>';
    }
    function fmtAmount(a, cur) {
        var n = parseFloat(a || 0);
        var s = (n % 1 === 0) ? String(Math.round(n)) : n.toFixed(2);
        return cur ? s + ' ' + cur : s;
    }
    function pctNum(p) {
        if (p == null || isNaN(parseFloat(p))) return '';
        var n = Math.round(parseFloat(p) * 10) / 10;
        return (n % 1 === 0) ? String(Math.round(n)) : String(n);
    }
    function fmtPct(p) {
        var s = pctNum(p);
        return s === '' ? '' : ' <span class="travel-terms-timeline__pct">(' + esc(s) + '%)</span>';
    }
    // One timeline PER schedule (payment vs cancellation) \u2014 deliberately NOT
    // merged: a reader who only cares about payment shouldn't have to parse
    // cancellation nodes, and vice versa. Rules arrive date-sorted from the
    // endpoint. Copy is strictly "due by" (checkout charges once; nothing is
    // auto-charged).
    function cap(s) {
        s = String(s || '');
        return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function renderTrack(rules, heading, kind, total, cur) {
        if (!rules || !rules.length) return '';
        // Each node is headed by ITS DATE at the timeline dot ("P\u00e2n\u0103 la
        // 20.06.2026" / "De la 01.07.2026"), with a terse amount row below.
        var dateLabel = kind === 'pay' ? lbl(cfg.termsUntil, 'p\u00e2n\u0103 la') : lbl(cfg.termsFrom, 'de la');
        var html = '<p class="travel-terms-timeline__heading">' + esc(heading) + '</p>';
        html += '<ul class="travel-terms-timeline">';
        for (var i = 0; i < rules.length; i++) {
            var r = rules[i];
            html += '<li class="travel-terms-timeline__node">';
            // Date gutter LEFT of the rail (tracker style): small preposition
            // over a bold date, right-aligned against the dot.
            html += '<div class="travel-terms-timeline__date">' + esc(cap(dateLabel)) + ' <strong>' + esc(r.date) + '</strong></div>';
            if (kind === 'pay') {
                html += '<div class="travel-terms-timeline__row travel-terms-timeline__row--pay">'
                    + esc(lbl(cfg.termsDueBy, 'De achitat')) + ': <strong>'
                    + esc(fmtAmount(r.amount, cur)) + '</strong>' + fmtPct(r.percent) + '</div>';
            } else {
                var nonref = total > 0 && parseFloat(r.amount) >= total
                    ? ' <span class="travel-terms-timeline__tag--nonref">' + esc(lbl(cfg.termsNonRefundable, 'Nerambursabil')) + '</span>'
                    : '';
                // Penalty severity leads: "Penalizare 20%: 763 EUR" — the
                // percent is the instantly comparable signal; the amount is
                // the consequence detail (payment rows stay amount-first).
                var pctLead = pctNum(r.percent);
                html += '<div class="travel-terms-timeline__row travel-terms-timeline__row--cancel">'
                    + esc(lbl(cfg.termsPenalty, 'Penalizare')) + (pctLead !== '' ? ' <strong>' + esc(pctLead) + '%</strong>' : '') + ': <strong>'
                    + esc(fmtAmount(r.amount, cur)) + '</strong>' + nonref + '</div>';
            }
            html += '</li>';
        }
        html += '</ul>';
        return html;
    }
    function renderTerms(data) {
        var html = '';
        if (data.is_free && !data.free_until) {
            html += '<div class="travel-terms-modal__free">\u2713 ' + esc(lbl(cfg.freeCancellation, 'Anulare gratuit\u0103')) + '</div>';
        } else if (data.free_until) {
            // free_until IS the first penalty date (earliest `since`), so the
            // copy must read "before <date>" \u2014 "until" would wrongly imply
            // the date itself is still free.
            html += '<div class="travel-terms-modal__free">\u2713 ' + esc(lbl(cfg.freeCancellationUntil, 'Anulare gratuit\u0103 \u00eenainte de')) + ' <strong>' + esc(data.free_until) + '</strong></div>';
        }
        // PER-TRACK shape mapping. The API is binary per block \u2014 structured
        // `rules` XOR prose `text` (populated only "when we cannot parse the
        // fees"), never partial. A track with rules renders as a timeline; a
        // track without renders the API's parsed text lines. Never decide
        // globally: a prose payment track must not be dropped just because
        // the cancellation track happens to be structured.
        var total = parseFloat(data.schedule_total || 0);
        var cur = data.currency || '';
        var payHeading = lbl(cfg.paymentTerms, 'Termeni de plat\u0103');
        var cancelHeading = lbl(cfg.cancellationPolicy, 'Politica de anulare');
        html += (data.payment_rules && data.payment_rules.length)
            ? renderTrack(data.payment_rules, payHeading, 'pay', total, cur)
            : section(payHeading, data.payment_terms);
        html += (data.cancellation_rules && data.cancellation_rules.length)
            ? renderTrack(data.cancellation_rules, cancelHeading, 'cancel', total, cur)
            : section(cancelHeading, data.cancellation_fees);
        if (html === '') html = '<p>' + esc(lbl(cfg.noTermsInfo, 'Nu exist\u0103 condi\u021bii specifice pentru aceast\u0103 ofert\u0103.')) + '</p>';
        body.innerHTML = html;
    }
    function loadTerms(offerId) {
        if (cache[offerId]) { renderTerms(cache[offerId]); return; }
        body.innerHTML = '<div class="travel-terms-modal__loading"><span class="travel-spinner"></span> ' + esc(lbl(cfg.termsLoading, 'Se \u00eencarc\u0103 condi\u021biile...')) + '</div>';
        fetch('index.php?dispatch=sphinx_booking.offer_terms&offer_id=' + encodeURIComponent(offerId), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || data.status !== 'ok') {
                    body.innerHTML = '<p class="travel-terms-modal__unavailable">' + esc(lbl(cfg.termsUnavailable, 'Condi\u021biile nu sunt disponibile. V\u0103 rug\u0103m c\u0103uta\u021bi din nou.')) + '</p>';
                    return;
                }
                cache[offerId] = data;
                renderTerms(data);
            })
            .catch(function() {
                body.innerHTML = '<p class="travel-terms-modal__unavailable">' + esc(lbl(cfg.termsUnavailable, 'Condi\u021biile nu sunt disponibile. V\u0103 rug\u0103m c\u0103uta\u021bi din nou.')) + '</p>';
            });
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest ? e.target.closest('.sphinx-terms-link') : null;
        if (link) {
            e.preventDefault();
            var cardEl = link.closest('[data-offer-id]');
            var offerId = cardEl ? cardEl.getAttribute('data-offer-id') : '';
            if (!offerId) return;
            openModal();
            loadTerms(offerId);
            return;
        }
        if (e.target === modal || (e.target.closest && e.target.closest('.travel-terms-modal__close'))) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
})();
{/literal}
</script>

{* Loading/skeleton styles live in travel_core's search-results.css
   (travel-skeleton-*, travel-spinner, travel-loading-message). *}
