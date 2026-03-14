{*
 * Sphinx Booking Form
 *
 * Displays the guest entry form for a verified Sphinx hotel offer.
 * Uses shared travel_core form components for DOB masking, validation,
 * and multi-room support.
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}

{capture name="mainbox"}

{if $sphinx_booking_data}

<div class="novoton-reservation-form sphinx-booking-form">

    {* Hotel & booking summary header *}
    <div class="booking-summary-header" style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); color: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h2 style="margin: 0 0 10px;">{$sphinx_booking_data.hotel_name|escape:html}</h2>
        <div style="display: flex; flex-wrap: wrap; gap: 15px; font-size: 14px;">
            <span><i class="icon-calendar"></i> {$sphinx_booking_data.check_in|date_format:"%d.%m.%Y"} - {$sphinx_booking_data.check_out|date_format:"%d.%m.%Y"}</span>
            <span><i class="icon-moon"></i> {$sphinx_booking_data.nights} {__("travel_core.nights")|default:"nights"}</span>
            <span><i class="icon-home"></i> {$sphinx_booking_data.room_name|escape:html}</span>
            <span><i class="icon-food"></i> {$sphinx_booking_data.board_name|escape:html}</span>
        </div>
    </div>

    {* Price display *}
    <div class="booking-price-box" style="text-align: right; margin-bottom: 20px; padding: 15px; background: #f5f9fc; border-radius: 8px;">
        <div style="font-size: 13px; color: #666;">{__("travel_core.total_price")|default:"Total price"}</div>
        <div class="price-total" style="font-size: 28px; font-weight: 700; color: #003580;">
            {$sphinx_booking_data.total_price|number_format:2:",":"."} {if $sphinx_booking_data.currency == 'EUR'}€{else}{$sphinx_booking_data.currency}{/if}
        </div>
        <div id="price-loading-indicator" style="display: none;"><i class="icon-refresh"></i></div>
    </div>

    {* Guest entry form *}
    <form action="{"sphinx_booking.add_to_cart"|fn_url}" method="post" id="sphinx-booking-form">
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
        <input type="hidden" name="num_rooms" value="1">

        <div class="guest-names-section">
            <h3><i class="icon-user"></i> {__("travel_core.guest_details")|default:"Guest Details"}</h3>

            {* Generate adult guest fields *}
            {section name="adult" start=1 loop=$sphinx_booking_data.adults+1}
                <div class="guest-entry guest-entry-adult" style="padding: 15px; margin-bottom: 10px; background: #fff; border: 1px solid #e0e7ef; border-radius: 6px;">
                    <div style="font-weight: 600; margin-bottom: 10px; color: #003580;">
                        {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                        {if $smarty.section.adult.index == 1} ({__("travel_core.main_guest")|default:"Main Guest"}){/if}
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label>{__("travel_core.first_name")|default:"First Name"}</label>
                            <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][first_name]"
                                   class="ty-input-text" required placeholder="{__("travel_core.first_name")|default:"First Name"}">
                            <input type="hidden" name="guests[room1_adult_{$smarty.section.adult.index}][type]" value="adult">
                            <input type="hidden" name="guests[room1_adult_{$smarty.section.adult.index}][room]" value="1">
                            {if $smarty.section.adult.index == 1}
                                <input type="hidden" name="guests[room1_adult_1][is_holder]" value="1">
                            {/if}
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                            <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][last_name]"
                                   class="ty-input-text" required placeholder="{__("travel_core.last_name")|default:"Last Name"}">
                        </div>
                        <div style="flex: 0 0 150px;">
                            <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                            <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][dob]"
                                   class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10"
                                   onkeydown="TravelBooking.handleDobKeydown(event)"
                                   oninput="TravelBooking.applyDobMask(this)">
                        </div>
                    </div>
                </div>
            {/section}

            {* Generate child guest fields *}
            {if $sphinx_booking_data.children > 0}
                {assign var="children_ages_arr" value=","|explode:$sphinx_booking_data.children_ages}
                {section name="child" start=1 loop=$sphinx_booking_data.children+1}
                    {assign var="child_age" value=$children_ages_arr[$smarty.section.child.index-1]|default:0}
                    <div class="guest-entry guest-entry-child" data-original-age="{$child_age}" style="padding: 15px; margin-bottom: 10px; background: #fffbf0; border: 1px solid #f0e0c0; border-radius: 6px;">
                        <div style="font-weight: 600; margin-bottom: 10px; color: #856404;">
                            {__("travel_core.child")|default:"Child"} {$smarty.section.child.index}
                            <span style="font-weight: normal; color: #999;"> ({$child_age} {__("travel_core.years_old")|default:"years old"})</span>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.first_name")|default:"First Name"}</label>
                                <input type="text" name="guests[room1_child_{$smarty.section.child.index}][first_name]"
                                       class="ty-input-text" required placeholder="{__("travel_core.first_name")|default:"First Name"}">
                                <input type="hidden" name="guests[room1_child_{$smarty.section.child.index}][type]" value="child">
                                <input type="hidden" name="guests[room1_child_{$smarty.section.child.index}][age]" value="{$child_age}">
                                <input type="hidden" name="guests[room1_child_{$smarty.section.child.index}][room]" value="1">
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                <input type="text" name="guests[room1_child_{$smarty.section.child.index}][last_name]"
                                       class="ty-input-text" required placeholder="{__("travel_core.last_name")|default:"Last Name"}">
                            </div>
                            <div style="flex: 0 0 150px;">
                                <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                <input type="text" name="guests[room1_child_{$smarty.section.child.index}][dob]"
                                       id="dob_r1_c{$smarty.section.child.index}"
                                       class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10" required
                                       onkeydown="TravelBooking.handleDobKeydown(event)"
                                       oninput="TravelBooking.applyDobMask(this)">
                                <span id="child_age_display_r1_c{$smarty.section.child.index}" class="sphinx-age-display" style="font-size: 12px; color: #666;"></span>
                            </div>
                        </div>
                    </div>
                {/section}
            {/if}
        </div>

        {* Contact info *}
        <div class="contact-section" style="margin-top: 20px; padding: 15px; background: #f5f9fc; border-radius: 6px;">
            <h3><i class="icon-envelope"></i> {__("travel_core.contact_info")|default:"Contact Information"}</h3>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label>{__("travel_core.email")|default:"Email"}</label>
                    <input type="email" name="contact[email]" class="ty-input-text" placeholder="email@example.com">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label>{__("travel_core.phone")|default:"Phone"}</label>
                    <input type="tel" name="contact[phone]" class="ty-input-text" placeholder="+40...">
                </div>
            </div>
        </div>

        {* Submit *}
        <div style="margin-top: 20px; text-align: center;">
            <button type="submit" class="ty-btn ty-btn__primary" style="padding: 12px 40px; font-size: 16px; background: #003580; border: none; border-radius: 6px; color: #fff; cursor: pointer;">
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

{/capture}

{include file="common/mainbox.tpl" title=__("sphinx_holidays.booking_form_title", ["[default]" => "Complete Your Booking"]) content=$smarty.capture.mainbox}
