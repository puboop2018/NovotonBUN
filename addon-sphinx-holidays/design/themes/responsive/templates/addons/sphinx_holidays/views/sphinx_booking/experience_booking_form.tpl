{*
 * Sphinx Experience Booking Form
 *
 * Shows experience quote details and participant entry form.
 * Simpler than circuit booking — no optional services, no rooms.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}



{if $sphinx_experience_booking}

<div class="travel-booking-page sphinx-booking-form-page sphinx-experience-booking">

    {* Experience summary *}
    <div class="travel-booking-summary sphinx-booking-summary">
        <div class="travel-booking-row">
            {if $sphinx_experience_booking.image}
                <img src="{$sphinx_experience_booking.image}" alt="{$sphinx_experience_booking.title|escape:html}" class="travel-booking-thumb">
            {/if}
            <div class="travel-booking-main">
                <h2>{$sphinx_experience_booking.title|escape:html}</h2>
                <div class="travel-booking-meta">
                    <span><strong>{__("sphinx_holidays.date")|default:"Date"}:</strong> {$sphinx_experience_booking.departure_date|date_format:"%d.%m.%Y"}</span>
                    {if $sphinx_experience_booking.duration_description}
                        <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_experience_booking.duration_description|escape:html}</span>
                    {elseif $sphinx_experience_booking.duration_days > 0}
                        <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_experience_booking.duration_days} {__("travel_core.days")|default:"days"}</span>
                    {/if}
                    <span><strong>{__("sphinx_holidays.participants")|default:"Participants"}:</strong> {$sphinx_experience_booking.adults} {__("travel_core.adults")|default:"adults"}{if $sphinx_experience_booking.children > 0}, {$sphinx_experience_booking.children} {__("travel_core.children")|default:"children"}{/if}</span>
                </div>
            </div>
            <div class="travel-booking-price-col">
                <div class="travel-price-total">
                    {$sphinx_experience_booking.total_price|number_format:2:",":"."} {$sphinx_experience_booking.currency}
                </div>
                <div class="travel-price-label">{__("sphinx_holidays.total_price")|default:"Total price"}</div>
            </div>
        </div>
    </div>

    {* Participant entry form *}
    <form action="{"sphinx_booking.experience_add_to_cart"|fn_url}" method="post" id="sphinx-experience-booking-form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        <input type="hidden" name="offer_id" value="{$sphinx_experience_booking.offer_id}">
        <input type="hidden" name="experience_id" value="{$sphinx_experience_booking.experience_id}">
        <input type="hidden" name="departure_date" value="{$sphinx_experience_booking.departure_date}">
        <input type="hidden" name="title" value="{$sphinx_experience_booking.title|escape:html}">
        <input type="hidden" name="duration_days" value="{$sphinx_experience_booking.duration_days}">
        <input type="hidden" name="adults" value="{$sphinx_experience_booking.adults}">
        <input type="hidden" name="children" value="{$sphinx_experience_booking.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_experience_booking.children_ages}">
        <input type="hidden" name="total_price" value="{$sphinx_experience_booking.total_price}">
        <input type="hidden" name="base_price" value="{$sphinx_experience_booking.base_price}">
        <input type="hidden" name="currency" value="{$sphinx_experience_booking.currency}">

        <div class="guest-names-section">
            <h3 class="travel-section-title"><i class="icon-user"></i> {__("sphinx_holidays.participant_details")|default:"Participant Details"}</h3>

            {* Participant cards — shared travel_core component in ROOMLESS mode:
               emits the experience wire contract (guests[adult_{i}] /
               guests[child_{i}], no room prefix, no [room] field — these key
               names reach the Sphinx API unchanged). The synthesized single
               "room" comes from the controller (normalizeRoomsForDisplay).
               guard_expected_ages arms the client-side DOB-vs-priced-age
               check matching the server gate in experience_add_to_cart. *}
            {include file="addons/travel_core/components/booking_guest_cards.tpl"
                     guest_rooms=$sphinx_experience_booking.rooms_data
                     guest_num_rooms=1
                     guest_roomless=true
                     guest_label_prefix="travel_core"
                     show_adult_dob=true
                     adult_dob_required=true
                     child_dob_required=true
                     guard_expected_ages=true}
        </div>

        {* Contact (email/phone) is NOT collected here: CS-Cart checkout already
           collects it, and the booking submission reads it from the order. *}

        {* Submit *}
        <div class="travel-booking-submit sphinx-booking-submit">
            <button type="submit" class="travel-offer-book-btn sphinx-offer-book-btn">
                {__("sphinx_holidays.add_to_cart_btn")|default:"Add to Cart"}
            </button>
        </div>
    </form>

</div>

{/if}


