{*
 * Shared guest-entry cards for provider booking forms.
 *
 * One source of the per-adult / per-child name + DOB cards both providers'
 * booking forms render. Uses the shared travel_core design classes
 * (.travel-guest-*, .guest-entry-*), the shared client i18n vocabulary
 * (travel_core.* labels), and the shared DOB masking API (TravelBooking.*
 * from booking-form-validation.js). The POST field contract is
 * guests[room{N}_{type}_{i}][...] — identical across providers so each
 * add_to_cart parses the same structure.
 *
 * Parameters:
 *   guest_rooms       (array,  required) rooms_data — each: adults, children,
 *                                        childrenAges[], room_name, board_name.
 *   guest_num_rooms   (int,    default 1) show per-room headers when > 1.
 *   guest_label_prefix(string, default 'travel_core') langvar namespace for labels.
 *   guest_extra_class (string, default '') optional provider CSS hook on the wrapper.
 *   show_adult_dob    (bool,   default true) render an adult DOB field.
 *   adult_dob_required(bool,   default false) mark the adult DOB required
 *                                        (package/circuit manifests need it;
 *                                        hotel adult DOB stays optional).
 *   child_dob_required(bool,   default true) mark child DOB required.
 *   guard_expected_ages(bool,  default false) stamp data-expected-age on child
 *                                        DOB inputs so the shared JS blocks a
 *                                        DOB that contradicts the searched age
 *                                        (fixed-price providers like sphinx).
 *   guest_roomless    (bool,   default false) participant mode (experiences):
 *                                        field names guests[adult_{i}] /
 *                                        guests[child_{i}] with NO room{N}_
 *                                        prefix and NO [room] hidden — these
 *                                        key names reach the provider API, so
 *                                        the wire contract must not change.
 *                                        Room headers never render.
 *
 * @package TravelCore
 *}
{$_gp = $guest_label_prefix|default:'travel_core'}
{$_show_adult_dob = $show_adult_dob|default:true}
{$_child_dob_required = $child_dob_required|default:true}
{$_num_rooms = $guest_num_rooms|default:1}
{$_extra_class = $guest_extra_class|default:''}
{$_roomless = $guest_roomless|default:false}
{* Optional name prefill (edit mode): map keyed like the input names —
   "{npfx}adult_1" / "{npfx}child_2" => [last_name, first_name]. Empty by
   default, so non-edit renders are byte-identical. *}
{$_prefill = $guest_prefill|default:[]}

{foreach $guest_rooms as $room_idx => $room}
    {assign var="room_num" value=$room_idx+1}
    {* Field-name prefix: '' in roomless (participant) mode, room{N}_ otherwise *}
    {if $_roomless}{$_npfx = ''}{else}{$_npfx = "room`$room_num`_"}{/if}

    {* Room header — only when the booking spans more than one room *}
    {if $_num_rooms > 1 && !$_roomless}
        <div class="travel-room-header {$_extra_class}">
            <strong>{__("`$_gp`.room")|default:"Room"} {$room_num}</strong>
            {if $room.room_name} &mdash; {$room.room_name|escape:html}{/if}
            {if $room.board_name} ({$room.board_name|escape:html}){/if}
        </div>
    {/if}

    {* Adults *}
    {assign var="room_adults" value=$room.adults|default:1}
    {section name="adult" start=1 loop=$room_adults+1}
        <div class="guest-entry guest-entry-adult">
            <div class="travel-guest-label">
                {__("`$_gp`.adult")|default:"Adult"} {$smarty.section.adult.index}
                {if $room_idx == 0 && $smarty.section.adult.index == 1} <span class="travel-holder-tag">{__("`$_gp`.main_guest")|default:"Main Guest"}</span>{/if}
            </div>
            <div class="travel-guest-grid">
                {* Nume (last name) FIRST, Prenume second — novoton field order *}
                <div class="travel-guest-field">
                    <label for="guest_r{$room_num}_a{$smarty.section.adult.index}_last">{__("`$_gp`.last_name")|default:"Last Name"}</label>
                    {$_pf_key = "`$_npfx`adult_`$smarty.section.adult.index`"}
                    <input type="text" id="guest_r{$room_num}_a{$smarty.section.adult.index}_last"
                           name="guests[{$_npfx}adult_{$smarty.section.adult.index}][last_name]"
                           value="{$_prefill.$_pf_key.last_name|default:''|escape:html}"
                           class="ty-input-text" required aria-required="true" placeholder="{__("`$_gp`.last_name")|default:"Last Name"}">
                    <input type="hidden" name="guests[{$_npfx}adult_{$smarty.section.adult.index}][type]" value="adult">
                    {if !$_roomless}
                    <input type="hidden" name="guests[{$_npfx}adult_{$smarty.section.adult.index}][room]" value="{$room_num}">
                    {/if}
                    {if $room_idx == 0 && $smarty.section.adult.index == 1}
                        <input type="hidden" name="guests[{$_npfx}adult_1][is_holder]" value="1">
                    {/if}
                </div>
                <div class="travel-guest-field">
                    <label for="guest_r{$room_num}_a{$smarty.section.adult.index}_first">{__("`$_gp`.first_name")|default:"First Name"}</label>
                    <input type="text" id="guest_r{$room_num}_a{$smarty.section.adult.index}_first"
                           name="guests[{$_npfx}adult_{$smarty.section.adult.index}][first_name]"
                           value="{$_prefill.$_pf_key.first_name|default:''|escape:html}"
                           class="ty-input-text" required aria-required="true" placeholder="{__("`$_gp`.first_name")|default:"First Name"}">
                </div>
                {if $_show_adult_dob}
                <div class="travel-guest-field travel-guest-field--dob">
                    <label for="guest_r{$room_num}_a{$smarty.section.adult.index}_dob">{__("`$_gp`.date_of_birth")|default:"Date of Birth"} <span class="travel-muted-note">(ex: 27/05/1990)</span></label>
                    <input type="tel" id="guest_r{$room_num}_a{$smarty.section.adult.index}_dob"
                           name="guests[{$_npfx}adult_{$smarty.section.adult.index}][dob]"
                           class="ty-input-text dob-masked-input js-dob-basics" placeholder="ZZ/LL/AAAA" maxlength="10"
                           inputmode="numeric" autocomplete="off"
                           {if $adult_dob_required|default:false}required aria-required="true"{/if}
                           onkeydown="TravelBooking.handleDobKeydown(event)"
                           oninput="TravelBooking.applyDobMask(this)">
                </div>
                {/if}
            </div>
        </div>
    {/section}

    {* Children *}
    {assign var="room_children" value=$room.children|default:0}
    {if $room_children > 0}
        {assign var="room_child_ages" value=$room.childrenAges|default:[]}
        {section name="child" start=1 loop=$room_children+1}
            {assign var="child_age" value=$room_child_ages[$smarty.section.child.index-1]|default:0}
            <div class="guest-entry guest-entry-child" data-original-age="{$child_age}">
                <div class="travel-guest-label travel-guest-label--child">
                    {__("`$_gp`.child")|default:"Child"} {$smarty.section.child.index}
                    <span class="travel-guest-age-note"> ({$child_age} {__("`$_gp`.years_old")|default:"years old"})</span>
                </div>
                <div class="travel-guest-grid">
                    {* Nume (last name) FIRST, Prenume second — novoton field order *}
                    <div class="travel-guest-field">
                        <label for="guest_r{$room_num}_c{$smarty.section.child.index}_last">{__("`$_gp`.last_name")|default:"Last Name"}</label>
                        {$_pf_key = "`$_npfx`child_`$smarty.section.child.index`"}
                        <input type="text" id="guest_r{$room_num}_c{$smarty.section.child.index}_last"
                               name="guests[{$_npfx}child_{$smarty.section.child.index}][last_name]"
                               value="{$_prefill.$_pf_key.last_name|default:''|escape:html}"
                               class="ty-input-text" required aria-required="true" placeholder="{__("`$_gp`.last_name")|default:"Last Name"}">
                        <input type="hidden" name="guests[{$_npfx}child_{$smarty.section.child.index}][type]" value="child">
                        <input type="hidden" name="guests[{$_npfx}child_{$smarty.section.child.index}][age]" value="{$child_age}">
                        {if !$_roomless}
                        <input type="hidden" name="guests[{$_npfx}child_{$smarty.section.child.index}][room]" value="{$room_num}">
                        {/if}
                    </div>
                    <div class="travel-guest-field">
                        <label for="guest_r{$room_num}_c{$smarty.section.child.index}_first">{__("`$_gp`.first_name")|default:"First Name"}</label>
                        <input type="text" id="guest_r{$room_num}_c{$smarty.section.child.index}_first"
                               name="guests[{$_npfx}child_{$smarty.section.child.index}][first_name]"
                               value="{$_prefill.$_pf_key.first_name|default:''|escape:html}"
                               class="ty-input-text" required aria-required="true" placeholder="{__("`$_gp`.first_name")|default:"First Name"}">
                    </div>
                    <div class="travel-guest-field travel-guest-field--dob">
                        <label for="dob_r{$room_num}_c{$smarty.section.child.index}">{__("`$_gp`.date_of_birth")|default:"Date of Birth"} <span class="travel-muted-note">(ex: 27/05/2020)</span></label>
                        {* data-expected-age arms the shared JS + server guard: the DOB
                           must imply THIS age at check-in (the age the offer was priced
                           for). Fixed-price providers only — novoton re-prices instead. *}
                        <input type="tel" id="dob_r{$room_num}_c{$smarty.section.child.index}"
                               name="guests[{$_npfx}child_{$smarty.section.child.index}][dob]"
                               class="ty-input-text dob-masked-input js-dob-basics" placeholder="ZZ/LL/AAAA" maxlength="10"
                               inputmode="numeric" autocomplete="off"
                               {if $_child_dob_required}required aria-required="true"{/if}
                               {if $guard_expected_ages|default:false}data-expected-age="{$child_age}"{/if}
                               onkeydown="TravelBooking.handleDobKeydown(event)"
                               oninput="TravelBooking.applyDobMask(this)">
                        <span id="child_age_display_r{$room_num}_c{$smarty.section.child.index}" class="travel-age-display {$_extra_class}"></span>
                    </div>
                </div>
            </div>
        {/section}
    {/if}
{/foreach}
