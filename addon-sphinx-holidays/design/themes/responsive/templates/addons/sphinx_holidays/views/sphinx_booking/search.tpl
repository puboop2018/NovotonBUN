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
    {* CS-Cart plural forms so Romanian renders "1 adult"/"2 adulți" correctly. *}
    {capture assign="sx_badge_guests"}{__("sphinx_holidays.n_adults", [$sx_badge_adults])|lower}{if $sx_badge_children > 0}, {__("sphinx_holidays.n_children", [$sx_badge_children])|lower}{/if}{/capture}
    {capture assign="sx_badge_html"}<div class="travel-availability-block sphinx-results-title" id="sphinx-results-title" data-party-suffix="{$sx_badge_guests|escape:html}"{if !$sphinx_search_results} style="display: none;"{/if}><span class="travel-availability-badge">✓ {__("sphinx_holidays.available")|default:"Available"}</span><div class="travel-availability-details" id="sphinx-availability-details"><span class="travel-availability-line" id="sphinx-availability-guests">{if $sphinx_search_results}{$sx_badge_guests}{/if}</span><span class="travel-availability-line" id="sphinx-availability-rooms">{if $sphinx_search_results}{__("sphinx_holidays.n_rooms", [$sx_badge_rooms])|lower} ({__("sphinx_holidays.n_offers", [$sx_badge_offers])|lower}){/if}</span></div></div>{/capture}

    {* ===== HOTEL HEADER — placed ABOVE the search form (novoton parity);
       availability badge on the right, same row layout as novoton ===== *}
    {if $sphinx_hotel_name}
        <div class="travel-hotel-header sphinx-hotel-header">
            <div class="travel-hotel-header-row">
                <div>
                    {* Shared hotel-identity header (name + stars + location +
                       map link) — travel_core component, ONE copy for all four
                       provider surfaces. Data: $travel_hotel_header
                       (HotelHeaderViewModel, assigned by the search controller). *}
                    {include file="addons/travel_core/components/hotel_header.tpl"
                        hh_extra_class="sphinx-hotel-header-name"
                        hh_location_class="sphinx-hotel-header-location"}
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
</script>
{* Poll + terms-modal behavior lives in a real JS file (vitest imports it
   directly — tests/js/sphinx-search-results.test.mjs); the config objects
   above are its only Smarty-fed input. *}
{script src="js/addons/sphinx_holidays/search-results.js"}


{* Loading/skeleton styles live in travel_core's search-results.css
   (travel-skeleton-*, travel-spinner, travel-loading-message). *}
