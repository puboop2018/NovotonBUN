{*
 * Eurosite Touring — guest booking form.
 *
 * POST contract mirrors the shared travel_core guest cards
 * (guests[room1_{type}_{i}][last_name|first_name|type|dob(...)]) with one
 * eurosite addition: a [gender] select per guest (AddBookingRequest TGender).
 * The offer itself rides ONLY as offer_key — add_to_cart re-reads the
 * server-side snapshot for every price/occupancy fact.
 *}

{include file="addons/travel_core/components/travel_i18n.tpl"}

<div class="travel-search-results-page eurosite-booking-form-page">

    <h1>{$eurosite_offer.product_name|escape:html}</h1>
    <div class="eurosite-hotel-city">{$eurosite_offer.city_name|escape:html}</div>

    <div class="eurosite-booking-layout">
        <form action="{""|fn_url}" method="post" class="eurosite-booking-form cm-processed-form">
            <input type="hidden" name="dispatch" value="eurosite_booking.add_to_cart" />
            <input type="hidden" name="offer_key" value="{$eurosite_offer_key}" />

            <h3>{__("eurosite.guests", ["[default]" => "Guests"])}</h3>

            {* loop is EXCLUSIVE upper bound: start=1 loop=N+1 → N adults
               (start=1 loop=N would silently drop the last adult) *}
            {section name=adult loop=$eurosite_adults+1 start=1}
                {$i = $smarty.section.adult.index}
                <div class="guest-entry guest-entry-adult">
                    <div class="travel-guest-label">{__("eurosite.adult", ["[default]" => "Adult"])} {$i}</div>
                    <div class="eurosite-guest-fields">
                        <input type="text" name="guests[room1_adult_{$i}][last_name]" required
                               placeholder="{__("eurosite.last_name", ["[default]" => "Last name"])}" />
                        <input type="text" name="guests[room1_adult_{$i}][first_name]" required
                               placeholder="{__("eurosite.first_name", ["[default]" => "First name"])}" />
                        <select name="guests[room1_adult_{$i}][gender]">
                            <option value="B">{__("eurosite.gender_male", ["[default]" => "M"])}</option>
                            <option value="F">{__("eurosite.gender_female", ["[default]" => "F"])}</option>
                        </select>
                        <input type="date" name="guests[room1_adult_{$i}][dob]"
                               title="{__("eurosite.date_of_birth", ["[default]" => "Date of birth"])}" />
                        <input type="hidden" name="guests[room1_adult_{$i}][type]" value="adult" />
                        <input type="hidden" name="guests[room1_adult_{$i}][room]" value="1" />
                        {if $i == 1}<input type="hidden" name="guests[room1_adult_1][is_holder]" value="1" />{/if}
                    </div>
                </div>
            {/section}

            {foreach from=$eurosite_children_ages item=age key=ci}
                {$n = $ci + 1}
                <div class="guest-entry guest-entry-child">
                    <div class="travel-guest-label">{__("eurosite.child", ["[default]" => "Child"])} {$n} ({$age} {__("eurosite.years", ["[default]" => "years"])})</div>
                    <div class="eurosite-guest-fields">
                        <input type="text" name="guests[room1_child_{$n}][last_name]" required
                               placeholder="{__("eurosite.last_name", ["[default]" => "Last name"])}" />
                        <input type="text" name="guests[room1_child_{$n}][first_name]" required
                               placeholder="{__("eurosite.first_name", ["[default]" => "First name"])}" />
                        <input type="date" name="guests[room1_child_{$n}][dob]" required
                               title="{__("eurosite.date_of_birth", ["[default]" => "Date of birth"])}" />
                        <input type="hidden" name="guests[room1_child_{$n}][gender]" value="C" />
                        <input type="hidden" name="guests[room1_child_{$n}][type]" value="child" />
                        <input type="hidden" name="guests[room1_child_{$n}][age]" value="{$age}" />
                        <input type="hidden" name="guests[room1_child_{$n}][room]" value="1" />
                    </div>
                </div>
            {/foreach}

            <h3>{__("eurosite.contact", ["[default]" => "Contact"])}</h3>
            <div class="eurosite-guest-fields">
                <input type="email" name="guest_email" required placeholder="{__("email")}" />
                <input type="tel" name="guest_phone" required placeholder="{__("phone")}" />
            </div>

            <div class="eurosite-conditions">
                <h3>{__("eurosite.cancellation_and_payment_terms", ["[default]" => "Condiții de Anulare și Plată"])}</h3>
                {if $eurosite_payment_lines}
                    <div class="eurosite-modal-section"><strong>{__("eurosite.payment_terms", ["[default]" => "Termeni de plată"])}:</strong>
                        <ul>{foreach from=$eurosite_payment_lines item=line}<li>{$line|escape:html}</li>{/foreach}</ul>
                    </div>
                {/if}
                <div class="eurosite-modal-section"><strong>{__("eurosite.cancellation_terms", ["[default]" => "Condiții de anulare"])}:</strong>
                    {if $eurosite_cancellation_fees}
                        <table class="eurosite-fees-table"><tbody>
                            {foreach from=$eurosite_cancellation_fees item=fee}
                                <tr><td>{$fee.from} &ndash; {$fee.to}</td><td>{$fee.value}</td></tr>
                            {/foreach}
                        </tbody></table>
                    {else}
                        <p>{__("eurosite.fees_confirmed_at_booking", ["[default]" => "Condițiile de anulare vor fi confirmate la rezervare."])}</p>
                    {/if}
                </div>
            </div>

            <button type="submit" class="ty-btn ty-btn__primary eurosite-submit-btn">
                {__("eurosite.continue_to_checkout", ["[default]" => "Continuă spre checkout"])}
            </button>
        </form>

        <aside class="eurosite-booking-sidebar">
            <h3>{__("eurosite.your_stay", ["[default]" => "Sejurul tău"])}</h3>
            <div class="eurosite-sidebar-row">{$eurosite_offer.check_in} &rarr; {$eurosite_offer.check_out}</div>
            {foreach from=$eurosite_offer.rooms item=room}
                <div class="eurosite-sidebar-row">{$room.name|escape:html}</div>
            {/foreach}
            {foreach from=$eurosite_offer.meals item=meal}
                <div class="eurosite-sidebar-row">{$meal.name|escape:html}</div>
            {/foreach}
            <div class="eurosite-sidebar-row">
                {$eurosite_adults} {__("eurosite.adults", ["[default]" => "adulți"])}{if $eurosite_children_ages}, {$eurosite_children_ages|count} {__("eurosite.children", ["[default]" => "copii"])}{/if}
            </div>
            {if $eurosite_offer.availability == 'OnRequest'}
                <div class="eurosite-sidebar-row eurosite-availability--request">
                    {__("eurosite.on_request_note", ["[default]" => "Disponibilitate la cerere — rezervarea se confirmă ulterior."])}
                </div>
            {/if}
            <div class="eurosite-sidebar-total">
                <span>{__("eurosite.total", ["[default]" => "Total"])}</span>
                <strong>{$eurosite_offer.price} {$eurosite_offer.currency}</strong>
            </div>
        </aside>
    </div>
</div>
