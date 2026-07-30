{*
    Shared booking-form summary sidebar — the left column of the 2-column
    booking page, rendered identically for every provider (2 surfaces x 2
    themes = this file + its nova_theme mirror, synced by `composer mirror`).

    Data contract: $travel_booking_sidebar, assigned from
    Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel::toViewArray().
    Every value arrives pre-formatted and pre-localized by the provider
    controller, so there is not a single provider branch in this markup — a
    field a provider cannot supply (package, features, cancellation) simply
    arrives empty and its block is skipped.

    Four cards, top to bottom:
      1. Hotel      hero image + stars/badge row + name + location + features
      2. Summary    package, dates, "you selected", rooms, board, change link
      3. Price      optional strikethrough + reduction, then the total
      4. Cancel     policy lines + the full-charge headline when it applies

    The hero is rendered through common/image.tpl so its size follows the
    store's Settings -> Thumbnails -> "Products list thumbnail width/height",
    exactly like a catalog thumbnail.

    NOTE: the price box keeps the .booking-price-box / .price-total classes and
    novoton's #novoton-total-price + price-state ids — booking-form.js and
    booking-form-validation.js address them directly to write refreshed prices.
*}
{$tbs = $travel_booking_sidebar}
{if $tbs}
<aside class="travel-bsidebar">

    {* ── 1. Hotel ─────────────────────────────────────────────────────── *}
    <section class="travel-bcard travel-bcard--hotel">
        {* Hero: the store's Settings -> Thumbnails WIDTH is the source of
           truth, the height is derived from the card's 3:2 landscape ratio.
           Passing the stored thumbnail height instead produced the tall,
           squeezed crop this replaced — CS-Cart generated a portrait thumb and
           the CSS had nothing to correct it with. The container enforces the
           ratio, `object-fit: cover` does the cropping. *}
        {if $tbs.image_pair}
            {$tbs_hero_w = $settings.Thumbnails.product_lists_thumbnail_width|default:400}
            <div class="travel-bsidebar-hero">
                {include file="common/image.tpl"
                    images=$tbs.image_pair
                    image_width=$tbs_hero_w
                    image_height=($tbs_hero_w * 2 / 3)|round
                    no_ids=true
                }
            </div>
        {elseif $tbs.image_url}
            <div class="travel-bsidebar-hero">
                <img src="{$tbs.image_url|escape:html}" alt="{$tbs.name|escape:html}" loading="lazy">
            </div>
        {/if}

        <div class="travel-bcard__body">
            {* Stars ABOVE the name, availability badge right-aligned on the
               same row. novoton's booking-form.js rewrites #availability-badge
               after each re-price, so the id must live here. *}
            <div class="travel-bsidebar-toprow">
                {if $tbs.stars > 0}
                    <span class="travel-hotel-stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $tbs.stars])|escape:html}">{"★"|str_repeat:$tbs.stars}</span>
                {else}
                    <span class="travel-hotel-stars"></span>
                {/if}
                <span class="travel-hero-badge{if !$tbs.available} travel-hero-badge--on-request{/if}" id="availability-badge">
                    {if $tbs.available}&#10003; {__("travel_core.available")}{else}{__("travel_core.on_request")}{/if}
                </span>
            </div>

            <h1 class="travel-bsidebar-name">
                {if $tbs.product_id}
                    <bdi><a href="{"products.view?product_id=`$tbs.product_id`"|fn_url}" target="_blank" rel="noopener" class="product-title travel-hotel-name-link" title="{$tbs.name|escape:html}">{$tbs.name|escape:html}</a></bdi>
                {else}
                    <bdi>{$tbs.name|escape:html}</bdi>
                {/if}
            </h1>

            {if $tbs.location_line || $tbs.map_url}
                <p class="travel-hotel-location travel-bsidebar-location">{$tbs.location_line|escape:html}{if $tbs.map_url}{if $tbs.location_line} - {/if}<a href="{$tbs.map_url|escape:html}" target="_blank" rel="noopener" class="travel-hotel-map-link">{__("travel_core.location_show_map")}</a>{/if}</p>
            {/if}

            {if $tbs.features}
                <ul class="travel-bsidebar-features">
                    {foreach from=$tbs.features item="tbs_feature"}
                        <li>{$tbs_feature|escape:html}</li>
                    {/foreach}
                </ul>
            {/if}
        </div>
    </section>

    {* ── 2. Booking summary ───────────────────────────────────────────── *}
    <section class="travel-bcard travel-bcard--summary">
        <h3 class="travel-bcard__title">{__("travel_core.your_booking_details")}</h3>
        <div class="travel-bcard__body">

            {if $tbs.package_name}
                <div class="travel-bsidebar-package">
                    <span class="travel-bsidebar-package__label">{__("travel_core.package")}</span>
                    <strong>{$tbs.package_name|escape:html}</strong>
                </div>
            {/if}

            <div class="travel-bsidebar-dates">
                <div class="travel-bsidebar-date">
                    <span>{__("travel_core.check_in")}</span>
                    <strong>{$tbs.check_in|escape:html}</strong>
                    {if $tbs.check_in_weekday}<em>{$tbs.check_in_weekday|escape:html}</em>{/if}
                </div>
                <div class="travel-bsidebar-date">
                    <span>{__("travel_core.check_out")}</span>
                    <strong>{$tbs.check_out|escape:html}</strong>
                    {if $tbs.check_out_weekday}<em>{$tbs.check_out_weekday|escape:html}</em>{/if}
                </div>
            </div>

            <hr class="travel-bsidebar-rule">

            {* "7 nights, 1 room for 2 adults and 1 child" — each count is a
               CS-Cart plural form ("[n] x|[n] y"), then joined by a sentence
               key so Romanian keeps its own word order and "și". *}
            <div class="travel-bsidebar-selected">
                <span class="travel-bsidebar-selected__label">{__("travel_core.you_selected")}</span>
                {capture assign="tbs_nights"}{__("travel_core.n_nights", ["[n]" => $tbs.nights])}{/capture}
                {capture assign="tbs_rooms"}{__("travel_core.n_rooms", ["[n]" => $tbs.rooms])}{/capture}
                {capture assign="tbs_adults"}{__("travel_core.n_adults", ["[n]" => $tbs.adults])}{/capture}
                <strong class="travel-bsidebar-selected__value">
                    {if $tbs.children > 0}
                        {capture assign="tbs_children"}{__("travel_core.n_children", ["[n]" => $tbs.children])}{/capture}
                        {__("travel_core.selected_line_with_children", ["[nights]" => $tbs_nights, "[rooms]" => $tbs_rooms, "[adults]" => $tbs_adults, "[children]" => $tbs_children])}
                    {else}
                        {__("travel_core.selected_line", ["[nights]" => $tbs_nights, "[rooms]" => $tbs_rooms, "[adults]" => $tbs_adults])}
                    {/if}
                </strong>
            </div>

            {if $tbs.room_lines}
                <ul class="travel-bsidebar-rooms">
                    {foreach from=$tbs.room_lines item="tbs_room"}
                        <li>{$tbs_room.qty}x {$tbs_room.name|escape:html}</li>
                    {/foreach}
                </ul>
            {/if}

            {if $tbs.board_name}
                <div class="travel-bsidebar-board">{$tbs.board_name|escape:html}</div>
            {/if}

            {if $tbs.change_url}
                <a class="travel-bsidebar-change" href="{$tbs.change_url|fn_url}">{__("travel_core.change_selection")}</a>
            {/if}
        </div>
    </section>

    {* ── 3. Price ─────────────────────────────────────────────────────── *}
    <section class="travel-bcard travel-bcard--price">
        <div class="travel-bcard__body booking-price-box travel-price-box">
            <div id="price-error-message" class="travel-price-error travel-is-hidden"></div>

            {if $tbs.old_total}
                <div class="travel-bsidebar-pricerow">
                    <span>{__("travel_core.original_price")}</span>
                    <span class="travel-bsidebar-oldprice">{$tbs.old_total nofilter}</span>
                </div>
            {/if}

            <div class="travel-bsidebar-total">
                <span class="travel-price-label">{__("travel_core.total_price")|default:"Total"}</span>
                <span class="price-total" id="novoton-total-price">{$tbs.total nofilter}</span>
            </div>

            <span id="price-unverified-badge" class="travel-price-unverified travel-is-hidden"></span>
            <a href="#" id="refresh-price-link" class="travel-price-refresh travel-is-hidden" onclick="if (window.refreshPrice) { refreshPrice(); } return false;"></a>
        </div>
    </section>

    {* ── 4. Cancellation ──────────────────────────────────────────────── *}
    {* Hidden until it has content: novoton fills it from the price
       re-verification that already runs on page load (no extra API call), so
       an empty card must not flash an empty policy at the guest. *}
    <section class="travel-bcard travel-bcard--cancel{if !$tbs.cancel_lines && !$tbs.cancel_full_amount && !$tbs.cancel_free_until} travel-is-hidden{/if}" id="travel-cancel-card">
        <h3 class="travel-bcard__title">{__("travel_core.cancel_cost_title")}</h3>
        <div class="travel-bcard__body">
            {* Free-cancellation deadline first and in green — the same
               treatment (and wording) the search-results card gives it, so the
               guest sees one consistent promise from search to checkout. *}
            <div class="travel-bsidebar-freecancel{if !$tbs.cancel_free_until} travel-is-hidden{/if}" id="travel-cancel-free">
                &#10003; {__("travel_core.free_cancellation_until")} <strong id="travel-cancel-free-date">{$tbs.cancel_free_until|escape:html}</strong>
            </div>
            {if $tbs.cancel_full_amount}
                <div class="travel-bsidebar-cancelrow">
                    <mark class="travel-bsidebar-cancelhl">{__("travel_core.cancel_you_will_pay")}</mark>
                    <strong>{$tbs.cancel_full_amount nofilter}</strong>
                </div>
            {/if}
            <ul class="travel-bsidebar-cancel" id="travel-cancel-lines">
                {foreach from=$tbs.cancel_lines item="tbs_cancel"}
                    <li>{$tbs_cancel|escape:html}</li>
                {/foreach}
            </ul>
        </div>
    </section>

</aside>
{/if}
