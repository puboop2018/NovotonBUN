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

            {foreach $sphinx_booking_data.rooms_data as $room_idx => $room}
                {assign var="room_num" value=$room_idx+1}

                {* Room header (only shown for multi-room) *}
                {if $sphinx_booking_data.num_rooms > 1}
                    <div class="travel-room-header sphinx-room-header">
                        <strong>{__("travel_core.room")|default:"Room"} {$room_num}</strong>
                        {if $room.room_name} &mdash; {$room.room_name|escape:html}{/if}
                        {if $room.board_name} ({$room.board_name|escape:html}){/if}
                    </div>
                {/if}

                {* Generate adult guest fields for this room *}
                {assign var="room_adults" value=$room.adults|default:$sphinx_booking_data.adults}
                {section name="adult" start=1 loop=$room_adults+1}
                    <div class="guest-entry guest-entry-adult">
                        <div class="travel-guest-label">
                            {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                            {if $room_idx == 0 && $smarty.section.adult.index == 1} <span class="travel-holder-tag">{__("travel_core.main_guest")|default:"Main Guest"}</span>{/if}
                        </div>
                        <div class="travel-guest-grid">
                            <div class="travel-guest-field">
                                <label for="sphinx_r{$room_num}_a{$smarty.section.adult.index}_first">{__("travel_core.first_name")|default:"First Name"}</label>
                                <input type="text" id="sphinx_r{$room_num}_a{$smarty.section.adult.index}_first"
                                       name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][first_name]"
                                       class="ty-input-text" required aria-required="true" placeholder="{__("travel_core.first_name")|default:"First Name"}">
                                <input type="hidden" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][type]" value="adult">
                                <input type="hidden" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][room]" value="{$room_num}">
                                {if $room_idx == 0 && $smarty.section.adult.index == 1}
                                    <input type="hidden" name="guests[room1_adult_1][is_holder]" value="1">
                                {/if}
                            </div>
                            <div class="travel-guest-field">
                                <label for="sphinx_r{$room_num}_a{$smarty.section.adult.index}_last">{__("travel_core.last_name")|default:"Last Name"}</label>
                                <input type="text" id="sphinx_r{$room_num}_a{$smarty.section.adult.index}_last"
                                       name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][last_name]"
                                       class="ty-input-text" required aria-required="true" placeholder="{__("travel_core.last_name")|default:"Last Name"}">
                            </div>
                            <div class="travel-guest-field travel-guest-field--dob">
                                <label for="sphinx_r{$room_num}_a{$smarty.section.adult.index}_dob">{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                <input type="text" id="sphinx_r{$room_num}_a{$smarty.section.adult.index}_dob"
                                       name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][dob]"
                                       class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10"
                                       onkeydown="TravelBooking.handleDobKeydown(event)"
                                       oninput="TravelBooking.applyDobMask(this)">
                            </div>
                        </div>
                    </div>
                {/section}

                {* Generate child guest fields for this room *}
                {assign var="room_children" value=$room.children|default:0}
                {if $room_children > 0}
                    {assign var="room_child_ages" value=$room.childrenAges|default:[]}
                    {section name="child" start=1 loop=$room_children+1}
                        {assign var="child_age" value=$room_child_ages[$smarty.section.child.index-1]|default:0}
                        <div class="guest-entry guest-entry-child" data-original-age="{$child_age}">
                            <div class="travel-guest-label travel-guest-label--child">
                                {__("travel_core.child")|default:"Child"} {$smarty.section.child.index}
                                <span class="travel-guest-age-note"> ({$child_age} {__("travel_core.years_old")|default:"years old"})</span>
                            </div>
                            <div class="travel-guest-grid">
                                <div class="travel-guest-field">
                                    <label for="sphinx_r{$room_num}_c{$smarty.section.child.index}_first">{__("travel_core.first_name")|default:"First Name"}</label>
                                    <input type="text" id="sphinx_r{$room_num}_c{$smarty.section.child.index}_first"
                                           name="guests[room{$room_num}_child_{$smarty.section.child.index}][first_name]"
                                           class="ty-input-text" required aria-required="true" placeholder="{__("travel_core.first_name")|default:"First Name"}">
                                    <input type="hidden" name="guests[room{$room_num}_child_{$smarty.section.child.index}][type]" value="child">
                                    <input type="hidden" name="guests[room{$room_num}_child_{$smarty.section.child.index}][age]" value="{$child_age}">
                                    <input type="hidden" name="guests[room{$room_num}_child_{$smarty.section.child.index}][room]" value="{$room_num}">
                                </div>
                                <div class="travel-guest-field">
                                    <label for="sphinx_r{$room_num}_c{$smarty.section.child.index}_last">{__("travel_core.last_name")|default:"Last Name"}</label>
                                    <input type="text" id="sphinx_r{$room_num}_c{$smarty.section.child.index}_last"
                                           name="guests[room{$room_num}_child_{$smarty.section.child.index}][last_name]"
                                           class="ty-input-text" required aria-required="true" placeholder="{__("travel_core.last_name")|default:"Last Name"}">
                                </div>
                                <div class="travel-guest-field travel-guest-field--dob">
                                    <label for="dob_r{$room_num}_c{$smarty.section.child.index}">{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                    <input type="text" id="dob_r{$room_num}_c{$smarty.section.child.index}"
                                           name="guests[room{$room_num}_child_{$smarty.section.child.index}][dob]"
                                           class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10"
                                           required aria-required="true"
                                           onkeydown="TravelBooking.handleDobKeydown(event)"
                                           oninput="TravelBooking.applyDobMask(this)">
                                    <span id="child_age_display_r{$room_num}_c{$smarty.section.child.index}" class="travel-age-display sphinx-age-display"></span>
                                </div>
                            </div>
                        </div>
                    {/section}
                {/if}
            {/foreach}
        </div>

        {* Contact info — shared component (was a hand-copied duplicate) *}
        {include file="addons/sphinx_holidays/views/sphinx_booking/components/contact_fields.tpl"}

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
