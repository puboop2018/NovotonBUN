{*
 * Sphinx Booking Form
 *
 * Displays the guest entry form for a verified Sphinx hotel offer.
 * Uses shared travel_core form components for DOB masking, validation,
 * and multi-room support. Styled by travel_core's booking-pages.css
 * (.travel-booking-page class contract).
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}



{if $sphinx_booking_data}

<div class="travel-booking-page sphinx-booking-form">

    {* Hotel & booking summary header *}
    <div class="travel-booking-summary travel-booking-summary--hero booking-summary-header">
        <h2>{$sphinx_booking_data.hotel_name|escape:html}</h2>
        <div class="travel-booking-meta">
            <span><i class="icon-calendar"></i> {$sphinx_booking_data.check_in|date_format:"%d.%m.%Y"} - {$sphinx_booking_data.check_out|date_format:"%d.%m.%Y"}</span>
            <span><i class="icon-moon"></i> {$sphinx_booking_data.nights} {__("travel_core.nights")|default:"nights"}</span>
            <span><i class="icon-home"></i> {$sphinx_booking_data.room_name|escape:html}</span>
            <span><i class="icon-food"></i> {$sphinx_booking_data.board_name|escape:html}</span>
        </div>
    </div>

    {* Price display *}
    <div class="travel-price-box booking-price-box">
        <div class="travel-price-label">{__("travel_core.total_price")|default:"Total price"}</div>
        <div class="travel-price-total price-total" aria-live="polite" aria-atomic="true">
            {$sphinx_booking_data.total_price|number_format:2:",":"."} {if $sphinx_booking_data.currency == 'EUR'}€{else}{$sphinx_booking_data.currency}{/if}
        </div>
        <div id="price-loading-indicator" style="display: none;"><i class="icon-refresh"></i></div>
    </div>

    {* Payment & cancellation terms (from the verified offer; hidden when the API sends none) *}
    {if $sphinx_booking_data.payment_terms || $sphinx_booking_data.cancellation_fees}
    <div class="travel-terms-box sphinx-booking-terms">
        {if $sphinx_booking_data.payment_terms}
        <div class="travel-terms-group">
            <strong>{__("sphinx_holidays.payment_terms")|default:"Payment terms"}</strong>
            <ul>
                {foreach $sphinx_booking_data.payment_terms as $_sx_term}
                <li>{$_sx_term|escape:html}</li>
                {/foreach}
            </ul>
        </div>
        {/if}
        {if $sphinx_booking_data.cancellation_fees}
        <div class="travel-terms-group">
            <strong>{__("sphinx_holidays.cancellation_policy")|default:"Cancellation policy"}</strong>
            <ul>
                {foreach $sphinx_booking_data.cancellation_fees as $_sx_fee}
                <li>{$_sx_fee|escape:html}</li>
                {/foreach}
            </ul>
        </div>
        {/if}
    </div>
    {/if}

    {* Guest entry form *}
    <form action="{"sphinx_booking.add_to_cart"|fn_url}" method="post" id="sphinx-booking-form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="offer_id" value="{$sphinx_booking_data.offer_id}">
        <input type="hidden" name="hotel_id" value="{$sphinx_booking_data.hotel_id}">
        <input type="hidden" name="product_id" value="{$sphinx_booking_data.product_id}">
        <input type="hidden" name="check_in" value="{$sphinx_booking_data.check_in}">
        <input type="hidden" name="check_out" value="{$sphinx_booking_data.check_out}">
        <input type="hidden" name="nights" value="{$sphinx_booking_data.nights}">
        <input type="hidden" name="adults" value="{$sphinx_booking_data.adults}">
        <input type="hidden" name="children" value="{$sphinx_booking_data.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_booking_data.children_ages}">
        <input type="hidden" name="total_price" value="{$sphinx_booking_data.total_price}">
        <input type="hidden" name="num_rooms" value="{$sphinx_booking_data.num_rooms|default:1}">
        <input type="hidden" name="rooms_data" value="{$sphinx_booking_data.rooms_data|json_encode|escape:html}">

        <div class="guest-names-section">
            <h3 class="travel-section-title"><i class="icon-user"></i> {__("travel_core.guest_details")|default:"Guest Details"}</h3>

            {* Guest name + DOB cards — shared travel_core component (same markup
               contract both providers post: guests[room{N}_{type}_{i}][...]). *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_booking_data.rooms_data
                     guest_num_rooms=$sphinx_booking_data.num_rooms
                     guest_label_prefix="travel_core"
                     show_adult_dob=true
                     child_dob_required=true
                     guard_expected_ages=true}
        </div>

        {* Contact (email/phone) is NOT collected here: CS-Cart checkout already
           collects it, and the booking submission reads it from the order. *}

        {* Submit *}
        <div class="travel-booking-submit sphinx-booking-submit">
            <button type="submit" class="travel-offer-book-btn sphinx-offer-book-btn">
                <i class="icon-shopping-cart"></i> {__("sphinx_holidays.add_to_cart_btn")|default:"Add to Cart"}
            </button>
        </div>

    </form>

</div>

{else}
    <div class="sphinx-no-booking-data">
        <p>{__("sphinx_holidays.booking_data_missing")|default:"Booking data not available. Please search again."}</p>
        <a href="{"index.index"|fn_url}" class="ty-btn ty-btn__secondary">{__("travel_core.search")|default:"Search"}</a>
    </div>
{/if}
