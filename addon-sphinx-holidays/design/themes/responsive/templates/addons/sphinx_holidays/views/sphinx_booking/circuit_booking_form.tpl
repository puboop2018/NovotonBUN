{*
 * Sphinx Circuit Booking Form
 *
 * Shows circuit quote details, optional services selection,
 * and guest entry form.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}

{capture name="mainbox"}

{if $sphinx_circuit_booking}

<div class="sphinx-booking-form-page sphinx-circuit-booking">

    {* Circuit summary *}
    <div class="sphinx-booking-summary" style="background: #f5f9fc; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            {if $sphinx_circuit_booking.image}
                <img src="{$sphinx_circuit_booking.image}" alt="{$sphinx_circuit_booking.title|escape:html}" style="width: 200px; height: 150px; object-fit: cover; border-radius: 8px;">
            {/if}
            <div style="flex: 1;">
                <h2 style="margin: 0 0 10px; color: #003580;">{$sphinx_circuit_booking.title|escape:html}</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 15px; font-size: 14px; color: #555;">
                    <span><strong>{__("sphinx_holidays.departure")|default:"Departure"}:</strong> {$sphinx_circuit_booking.departure_date|date_format:"%d.%m.%Y"}</span>
                    <span><strong>{__("sphinx_holidays.duration")|default:"Duration"}:</strong> {$sphinx_circuit_booking.duration_days} {__("travel_core.days")|default:"days"} / {$sphinx_circuit_booking.duration_nights} {__("travel_core.nights")|default:"nights"}</span>
                    {if $sphinx_circuit_booking.transport_type}
                        <span><strong>{__("sphinx_holidays.transport")|default:"Transport"}:</strong> {$sphinx_circuit_booking.transport_type|capitalize|escape:html}</span>
                    {/if}
                    {if $sphinx_circuit_booking.meal_type}
                        <span><strong>{__("sphinx_holidays.meal")|default:"Meal"}:</strong> {$sphinx_circuit_booking.meal_type|escape:html}</span>
                    {/if}
                </div>
                {if $sphinx_circuit_booking.departure_name}
                    <div style="margin-top: 5px; font-size: 13px; color: #666;">
                        {__("sphinx_holidays.departure_from")|default:"Departure from"}: {$sphinx_circuit_booking.departure_name|escape:html}
                    </div>
                {/if}
            </div>
            <div style="text-align: right; min-width: 150px;">
                <div style="font-size: 28px; font-weight: 700; color: #003580;">
                    {$sphinx_circuit_booking.total_price|number_format:2:",":"."} {$sphinx_circuit_booking.currency}
                </div>
                <div style="font-size: 13px; color: #666;">{__("sphinx_holidays.total_price")|default:"Total price"}</div>
            </div>
        </div>
    </div>

    {* Flight info (if available) *}
    {if $sphinx_circuit_booking.flight}
        <div class="sphinx-flight-info" style="background: #fff; border: 1px solid #e0e7ef; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 10px; color: #003580;"><i class="icon-plane"></i> {__("sphinx_holidays.flight_info")|default:"Flight Information"}</h3>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                {if $sphinx_circuit_booking.flight.outbound}
                    <div style="flex: 1; min-width: 250px;">
                        <strong>{__("sphinx_holidays.outbound")|default:"Outbound"}</strong>
                        <div>{$sphinx_circuit_booking.flight.outbound.departure.name|escape:html} &rarr; {$sphinx_circuit_booking.flight.outbound.arrival.name|escape:html}</div>
                        <div style="color: #666; font-size: 13px;">{$sphinx_circuit_booking.flight.outbound.airline.name|escape:html} {$sphinx_circuit_booking.flight.outbound.flight_number}</div>
                    </div>
                {/if}
                {if $sphinx_circuit_booking.flight.inbound}
                    <div style="flex: 1; min-width: 250px;">
                        <strong>{__("sphinx_holidays.inbound")|default:"Return"}</strong>
                        <div>{$sphinx_circuit_booking.flight.inbound.departure.name|escape:html} &rarr; {$sphinx_circuit_booking.flight.inbound.arrival.name|escape:html}</div>
                        <div style="color: #666; font-size: 13px;">{$sphinx_circuit_booking.flight.inbound.airline.name|escape:html} {$sphinx_circuit_booking.flight.inbound.flight_number}</div>
                    </div>
                {/if}
            </div>
        </div>
    {/if}

    {* Guest entry form *}
    <form action="{"sphinx_booking.circuit_add_to_cart"|fn_url}" method="post" id="sphinx-circuit-booking-form">
        <input type="hidden" name="offer_id" value="{$sphinx_circuit_booking.offer_id}">
        <input type="hidden" name="circuit_id" value="{$sphinx_circuit_booking.circuit_id}">
        <input type="hidden" name="departure_date" value="{$sphinx_circuit_booking.departure_date}">
        <input type="hidden" name="departure_id" value="{$sphinx_circuit_booking.departure_id}">
        <input type="hidden" name="departure_name" value="{$sphinx_circuit_booking.departure_name|escape:html}">
        <input type="hidden" name="title" value="{$sphinx_circuit_booking.title|escape:html}">
        <input type="hidden" name="transport_type" value="{$sphinx_circuit_booking.transport_type}">
        <input type="hidden" name="duration_days" value="{$sphinx_circuit_booking.duration_days}">
        <input type="hidden" name="duration_nights" value="{$sphinx_circuit_booking.duration_nights}">
        <input type="hidden" name="adults" value="{$sphinx_circuit_booking.adults}">
        <input type="hidden" name="children" value="{$sphinx_circuit_booking.children}">
        <input type="hidden" name="children_ages" value="{$sphinx_circuit_booking.children_ages}">
        <input type="hidden" name="total_price" value="{$sphinx_circuit_booking.total_price}">
        <input type="hidden" name="base_price" value="{$sphinx_circuit_booking.base_price}">
        <input type="hidden" name="currency" value="{$sphinx_circuit_booking.currency}">
        <input type="hidden" name="rooms_json" value="{$sphinx_circuit_booking.rooms|json_encode|escape:html}">

        {* Optional services *}
        {if $sphinx_circuit_booking.additional_services}
            <div class="sphinx-services-section" style="margin-bottom: 20px;">
                <h3><i class="icon-cog"></i> {__("sphinx_holidays.additional_services")|default:"Additional Services"}</h3>
                {foreach $sphinx_circuit_booking.additional_services as $service}
                    <div style="padding: 10px 15px; margin-bottom: 8px; background: #fff; border: 1px solid #e0e7ef; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
                        {if $service.type == 'mandatory'}
                            <input type="checkbox" name="services[]" value="{$service.code}" checked disabled>
                            <span style="color: #999; font-size: 12px;">({__("sphinx_holidays.mandatory")|default:"Mandatory"})</span>
                        {else}
                            <input type="checkbox" name="services[]" value="{$service.code}" {if $service.selected}checked{/if}>
                        {/if}
                        <div style="flex: 1;">
                            <strong>{$service.title|escape:html}</strong>
                            {if $service.description}
                                <div style="font-size: 13px; color: #666;">{$service.description|escape:html}</div>
                            {/if}
                        </div>
                        <div style="font-weight: 600; color: #003580;">
                            +{$service.pricing.selling_price|number_format:2:",":"."} {$service.pricing.currency}
                        </div>
                    </div>
                {/foreach}
            </div>
        {/if}

        {* Guest details — reuse the same pattern as hotel booking *}
        <div class="guest-names-section">
            <h3><i class="icon-user"></i> {__("travel_core.guest_details")|default:"Guest Details"}</h3>

            {* Rooms from quote *}
            {if $sphinx_circuit_booking.rooms}
                {foreach $sphinx_circuit_booking.rooms as $room_idx => $room}
                    {assign var="room_num" value=$room_idx+1}
                    {assign var="room_adults" value=$room.adults|default:$sphinx_circuit_booking.adults}
                    {assign var="room_children_ages" value=$room.children_ages|default:[]}

                    {if count($sphinx_circuit_booking.rooms) > 1}
                        <div class="sphinx-room-header" style="margin-top: {if $room_idx > 0}25px{else}0{/if}; padding: 10px 15px; background: #e8f0fe; border-radius: 6px 6px 0 0; border: 1px solid #c5d5ea; border-bottom: none;">
                            <strong style="color: #003580;">{__("travel_core.room")|default:"Room"} {$room_num}</strong>
                            {if $room.name} &mdash; {$room.name|escape:html}{/if}
                        </div>
                    {/if}

                    {section name="adult" start=1 loop=$room_adults+1}
                        <div class="guest-entry guest-entry-adult" style="padding: 15px; margin-bottom: 10px; background: #fff; border: 1px solid #e0e7ef; border-radius: 6px;">
                            <div style="font-weight: 600; margin-bottom: 10px; color: #003580;">
                                {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                                {if $room_idx == 0 && $smarty.section.adult.index == 1} ({__("travel_core.main_guest")|default:"Main Guest"}){/if}
                            </div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <label for="circuit_guest_first_{$room_num|default:1}_{$smarty.section.adult.index|default:$child_idx+1}">{__("travel_core.first_name")|default:"First Name"}</label>
                                    <input type="text" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][first_name]" class="ty-input-text" required>
                                    <input type="hidden" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][type]" value="adult">
                                    <input type="hidden" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][room]" value="{$room_num}">
                                    {if $room_idx == 0 && $smarty.section.adult.index == 1}
                                        <input type="hidden" name="guests[room1_adult_1][is_holder]" value="1">
                                    {/if}
                                </div>
                                <div style="flex: 1; min-width: 200px;">
                                    <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                    <input type="text" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][last_name]" class="ty-input-text" required>
                                </div>
                                <div style="flex: 0 0 150px;">
                                    <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                    <input type="text" name="guests[room{$room_num}_adult_{$smarty.section.adult.index}][dob]"
                                           class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10" required
                                           onkeydown="TravelBooking.handleDobKeydown(event)" oninput="TravelBooking.applyDobMask(this)">
                                </div>
                            </div>
                        </div>
                    {/section}

                    {if $room_children_ages}
                        {foreach $room_children_ages as $child_idx => $child_age}
                            <div class="guest-entry guest-entry-child" style="padding: 15px; margin-bottom: 10px; background: #fffbf0; border: 1px solid #f0e0c0; border-radius: 6px;">
                                <div style="font-weight: 600; margin-bottom: 10px; color: #856404;">
                                    {__("travel_core.child")|default:"Child"} {$child_idx+1}
                                    <span style="font-weight: normal; color: #999;">({$child_age} {__("travel_core.years_old")|default:"years old"})</span>
                                </div>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <div style="flex: 1; min-width: 200px;">
                                        <label for="circuit_guest_first_{$room_num|default:1}_{$smarty.section.adult.index|default:$child_idx+1}">{__("travel_core.first_name")|default:"First Name"}</label>
                                        <input type="text" name="guests[room{$room_num}_child_{$child_idx+1}][first_name]" class="ty-input-text" required>
                                        <input type="hidden" name="guests[room{$room_num}_child_{$child_idx+1}][type]" value="child">
                                        <input type="hidden" name="guests[room{$room_num}_child_{$child_idx+1}][age]" value="{$child_age}">
                                        <input type="hidden" name="guests[room{$room_num}_child_{$child_idx+1}][room]" value="{$room_num}">
                                    </div>
                                    <div style="flex: 1; min-width: 200px;">
                                        <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                        <input type="text" name="guests[room{$room_num}_child_{$child_idx+1}][last_name]" class="ty-input-text" required>
                                    </div>
                                    <div style="flex: 0 0 150px;">
                                        <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                        <input type="text" name="guests[room{$room_num}_child_{$child_idx+1}][dob]"
                                               class="ty-input-text dob-masked-input" placeholder="DD/MM/YYYY" maxlength="10" required
                                               onkeydown="TravelBooking.handleDobKeydown(event)" oninput="TravelBooking.applyDobMask(this)">
                                    </div>
                                </div>
                            </div>
                        {/foreach}
                    {/if}
                {/foreach}
            {else}
                {* Fallback: simple adult fields *}
                {section name="adult" start=1 loop=$sphinx_circuit_booking.adults+1}
                    <div class="guest-entry guest-entry-adult" style="padding: 15px; margin-bottom: 10px; background: #fff; border: 1px solid #e0e7ef; border-radius: 6px;">
                        <div style="font-weight: 600; margin-bottom: 10px; color: #003580;">
                            {__("travel_core.adult")|default:"Adult"} {$smarty.section.adult.index}
                            {if $smarty.section.adult.index == 1} ({__("travel_core.main_guest")|default:"Main Guest"}){/if}
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.first_name")|default:"First Name"}</label>
                                <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][first_name]" class="ty-input-text" required>
                                <input type="hidden" name="guests[room1_adult_{$smarty.section.adult.index}][type]" value="adult">
                                <input type="hidden" name="guests[room1_adult_{$smarty.section.adult.index}][room]" value="1">
                                {if $smarty.section.adult.index == 1}<input type="hidden" name="guests[room1_adult_1][is_holder]" value="1">{/if}
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label>{__("travel_core.last_name")|default:"Last Name"}</label>
                                <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][last_name]" class="ty-input-text" required>
                            </div>
                            <div style="flex: 0 0 150px;">
                                <label>{__("travel_core.date_of_birth")|default:"Date of Birth"}</label>
                                <input type="text" name="guests[room1_adult_{$smarty.section.adult.index}][dob]"
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

{include file="common/mainbox.tpl" title=__("sphinx_holidays.circuit_booking_title", ["[default]" => "Circuit Booking"]) content=$smarty.capture.mainbox}
