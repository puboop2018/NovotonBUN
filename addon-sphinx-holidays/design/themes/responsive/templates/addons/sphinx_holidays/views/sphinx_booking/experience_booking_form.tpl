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

            {section name="adult" start=1 loop=$sphinx_experience_booking.adults+1}
                <div class="guest-entry guest-entry-adult">
                    <div class="travel-guest-label">
                        {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                        {if $smarty.section.adult.index == 1} <span class="travel-holder-tag">{__("travel_core.main_guest")|default:"Main Guest"}</span>{/if}
                    </div>
                    <div class="travel-guest-grid">
                        <div class="travel-guest-field">
                            <label>{__("travel_core.first_name")|default:"First Name"}</label>
                            <input type="text" name="guests[adult_{$smarty.section.adult.index}][first_name]" class="ty-input-text" required>
                            <input type="hidden" name="guests[adult_{$smarty.section.adult.index}][type]" value="adult">
                            {if $smarty.section.adult.index == 1}
                                <input type="hidden" name="guests[adult_1][is_holder]" value="1">
                            {/if}
                        </div>
                        <div class="travel-guest-field">
                            <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                            <input type="text" name="guests[adult_{$smarty.section.adult.index}][last_name]" class="ty-input-text" required>
                        </div>
                        <div class="travel-guest-field travel-guest-field--dob">
                            <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                            <input type="text" name="guests[adult_{$smarty.section.adult.index}][dob]"
                                   class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10" required
                                   onkeydown="TravelBooking.handleDobKeydown(event)" oninput="TravelBooking.applyDobMask(this)">
                        </div>
                    </div>
                </div>
            {/section}

            {if $sphinx_experience_booking.children > 0}
                {assign var="exp_children_ages" value=","|explode:$sphinx_experience_booking.children_ages}
                {section name="child" start=1 loop=$sphinx_experience_booking.children+1}
                    {assign var="child_age" value=$exp_children_ages[$smarty.section.child.index-1]|default:0}
                    <div class="guest-entry guest-entry-child">
                        <div class="travel-guest-label travel-guest-label--child">
                            {__("travel_core.child")|default:"Child"} {$smarty.section.child.index}
                            <span class="travel-guest-age-note">({$child_age} {__("travel_core.years_old")|default:"years old"})</span>
                        </div>
                        <div class="travel-guest-grid">
                            <div class="travel-guest-field">
                                <label>{__("travel_core.first_name")|default:"First Name"}</label>
                                <input type="text" name="guests[child_{$smarty.section.child.index}][first_name]" class="ty-input-text" required>
                                <input type="hidden" name="guests[child_{$smarty.section.child.index}][type]" value="child">
                                <input type="hidden" name="guests[child_{$smarty.section.child.index}][age]" value="{$child_age}">
                            </div>
                            <div class="travel-guest-field">
                                <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                <input type="text" name="guests[child_{$smarty.section.child.index}][last_name]" class="ty-input-text" required>
                            </div>
                            <div class="travel-guest-field travel-guest-field--dob">
                                <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                <input type="text" name="guests[child_{$smarty.section.child.index}][dob]"
                                       class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10" required
                                       onkeydown="TravelBooking.handleDobKeydown(event)" oninput="TravelBooking.applyDobMask(this)">
                            </div>
                        </div>
                    </div>
                {/section}
            {/if}
        </div>

        {* Contact info *}
        {include file="addons/sphinx_holidays/views/sphinx_booking/components/contact_fields.tpl"}

        {* Submit *}
        <div class="travel-booking-submit sphinx-booking-submit">
            <button type="submit" class="travel-offer-book-btn sphinx-offer-book-btn">
                {__("sphinx_holidays.add_to_cart_btn")|default:"Add to Cart"}
            </button>
        </div>
    </form>

</div>

{/if}


