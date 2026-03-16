{*
 * Sphinx Experience Booking Form
 *
 * Shows experience quote details and participant entry form.
 * Simpler than circuit booking — no optional services, no rooms.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

{capture name="mainbox"}

{if $sphinx_experience_booking}

<div class="sphinx-booking-form-page sphinx-experience-booking">

    {* Experience summary *}
    <div class="sphinx-booking-summary" style="background: #f5f9fc; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            {if $sphinx_experience_booking.image}
                <img src="{$sphinx_experience_booking.image}" alt="{$sphinx_experience_booking.title|escape:html}" style="width: 200px; height: 150px; object-fit: cover; border-radius: 8px;">
            {/if}
            <div style="flex: 1;">
                <h2 style="margin: 0 0 10px; color: #003580;">{$sphinx_experience_booking.title|escape:html}</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 15px; font-size: 14px; color: #555;">
                    <span><strong>{__("sphinx_holidays.date")|default:"Date"}:</strong> {$sphinx_experience_booking.departure_date|date_format:"%d.%m.%Y"}</span>
                    {if $sphinx_experience_booking.duration_description}
                        <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_experience_booking.duration_description|escape:html}</span>
                    {elseif $sphinx_experience_booking.duration_days > 0}
                        <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_experience_booking.duration_days} {__("travel_core.days")|default:"days"}</span>
                    {/if}
                    <span><strong>{__("sphinx_holidays.participants")|default:"Participants"}:</strong> {$sphinx_experience_booking.adults} {__("travel_core.adults")|default:"adults"}{if $sphinx_experience_booking.children > 0}, {$sphinx_experience_booking.children} {__("travel_core.children")|default:"children"}{/if}</span>
                </div>
            </div>
            <div style="text-align: right; min-width: 150px;">
                <div style="font-size: 28px; font-weight: 700; color: #003580;">
                    {$sphinx_experience_booking.total_price|number_format:2:",":"."} {$sphinx_experience_booking.currency}
                </div>
                <div style="font-size: 13px; color: #666;">{__("sphinx_holidays.total_price")|default:"Total price"}</div>
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
            <h3><i class="icon-user"></i> {__("sphinx_holidays.participant_details")|default:"Participant Details"}</h3>

            {section name="adult" start=1 loop=$sphinx_experience_booking.adults+1}
                <div class="guest-entry guest-entry-adult" style="padding: 15px; margin-bottom: 10px; background: #fff; border: 1px solid #e0e7ef; border-radius: 6px;">
                    <div style="font-weight: 600; margin-bottom: 10px; color: #003580;">
                        {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                        {if $smarty.section.adult.index == 1} ({__("travel_core.main_guest")|default:"Main Guest"}){/if}
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label>{__("travel_core.first_name")|default:"First Name"}</label>
                            <input type="text" name="guests[adult_{$smarty.section.adult.index}][first_name]" class="ty-input-text" required>
                            <input type="hidden" name="guests[adult_{$smarty.section.adult.index}][type]" value="adult">
                            {if $smarty.section.adult.index == 1}
                                <input type="hidden" name="guests[adult_1][is_holder]" value="1">
                            {/if}
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                            <input type="text" name="guests[adult_{$smarty.section.adult.index}][last_name]" class="ty-input-text" required>
                        </div>
                        <div style="flex: 0 0 150px;">
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
                    <div class="guest-entry guest-entry-child" style="padding: 15px; margin-bottom: 10px; background: #fffbf0; border: 1px solid #f0e0c0; border-radius: 6px;">
                        <div style="font-weight: 600; margin-bottom: 10px; color: #856404;">
                            {__("travel_core.child")|default:"Child"} {$smarty.section.child.index}
                            <span style="font-weight: normal; color: #999;">({$child_age} {__("travel_core.years_old")|default:"years old"})</span>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.first_name")|default:"First Name"}</label>
                                <input type="text" name="guests[child_{$smarty.section.child.index}][first_name]" class="ty-input-text" required>
                                <input type="hidden" name="guests[child_{$smarty.section.child.index}][type]" value="child">
                                <input type="hidden" name="guests[child_{$smarty.section.child.index}][age]" value="{$child_age}">
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                <input type="text" name="guests[child_{$smarty.section.child.index}][last_name]" class="ty-input-text" required>
                            </div>
                            <div style="flex: 0 0 150px;">
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
        <div class="sphinx-booking-submit" style="margin-top: 20px; text-align: center;">
            <button type="submit" class="sphinx-offer-book-btn" style="padding: 12px 40px; font-size: 18px;">
                {__("sphinx_holidays.add_to_cart_btn")|default:"Add to Cart"}
            </button>
        </div>
    </form>

</div>

{/if}

{/capture}

{include file="common/mainbox.tpl" title=__("sphinx_holidays.experience_booking_title", ["[default]" => "Experience Booking"]) content=$smarty.capture.mainbox}
