{*
 * Novoton Booking Form - Guest Entry
 * v2.7.0-A72 - Added DOB future date validation (client + server-side)
 *}

{style src="css/addons/novoton_holidays/styles.css"}
{* Booking-form styles now come from travel_core booking-pages.css (.travel-booking-page classes); the embedded <style> block was removed. *}

{* Helper function to format room type with full name *}
{function name="format_room_type" room_id=""}
    {* Fix URL encoding: decode %2b to +, then restore any + lost to space *}
    {$room_clean = $room_id|replace:'%2b':'+'|replace:'%2B':'+'}
    {$room_clean = preg_replace('/(\d)\s+(\d)/', '$1+$2', $room_clean)}
    {if strpos($room_clean, 'DBL') !== false}
        {__("novoton_holidays.room_type_double")} ({$room_clean})
    {elseif strpos($room_clean, 'SGL') !== false}
        {__("novoton_holidays.room_type_single")} ({$room_clean})
    {elseif strpos($room_clean, 'APP') !== false}
        {__("novoton_holidays.room_type_apartment")} ({$room_clean})
    {elseif strpos($room_clean, 'STU') !== false}
        {__("novoton_holidays.room_type_studio")} ({$room_clean})
    {elseif strpos($room_clean, 'MAISONNETE') !== false || strpos($room_clean, 'MAIS') !== false}
        {__("novoton_holidays.room_type_maisonette")} ({$room_clean})
    {elseif strpos($room_clean, 'FAMILY') !== false || strpos($room_clean, 'FAM') !== false}
        {__("novoton_holidays.room_type_family")} ({$room_clean})
    {elseif strpos($room_clean, 'SUITE') !== false}
        {__("novoton_holidays.room_type_suite")} ({$room_clean})
    {elseif strpos($room_clean, 'TRP') !== false || strpos($room_clean, 'TRIPLE') !== false}
        {__("novoton_holidays.room_type_triple")} ({$room_clean})
    {else}
        {$room_clean}
    {/if}
{/function}

<div class="travel-booking-page novoton-reservation-form">
    <form action="{if $is_edit_mode}{fn_url("novoton_booking.update_booking")}{else}{fn_url("novoton_booking.add_to_cart")}{/if}" method="post" id="novoton-booking-form">
        <input type="hidden" name="security_hash" value="{$security_hash}" />
        {if $is_edit_mode}
            <input type="hidden" name="booking_id" value="{$booking_id}" />
            <input type="hidden" name="cart_id" value="{$cart_id}" />
        {/if}
        <input type="hidden" name="hotel_id" value="{$booking_data.hotel_id}" />
        <input type="hidden" name="room_id" value="{$booking_data.room_id}" />
        <input type="hidden" name="board_id" value="{$booking_data.board_id}" />
        <input type="hidden" name="check_in" value="{$booking_data.check_in}" />
        <input type="hidden" name="check_out" value="{$booking_data.check_out}" />
        <input type="hidden" name="nights" value="{$booking_data.nights}" />
        <input type="hidden" name="adults" value="{$booking_data.adults}" />
        <input type="hidden" name="children" value="{$booking_data.children}" />
        <input type="hidden" name="children_ages" value="{$booking_data.children_ages}" />
        <input type="hidden" name="total_price" value="{$booking_data.total_price}" />
        <input type="hidden" name="product_id" value="{$product_id}" />
        <input type="hidden" name="package_name" value="{$package_name|default:$booking_data.package_name|default:''}" />
        <input type="hidden" name="num_rooms" value="{$booking_data.num_rooms|default:1}" />
        {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}
            <input type="hidden" name="rooms_data" value="{$booking_data.rooms_data|json_encode|escape:'html'}" />
        {elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}
            <input type="hidden" name="rooms_data" value="{$booking_data.rooms_data|escape:'html'}" />
        {else}
            <input type="hidden" name="rooms_data" value="" />
        {/if}
        {* Terms are now fetched directly from API at checkout - no need for hidden fields *}
        
        {* Header — minimalist light card (parity with the search results
           header): PDP title + bdi, gold stars, inline availability pill,
           location line with map-pin. *}
        <div class="travel-reservation-header">
            <h1 class="ty-product-block-title"><bdi>{$hotel_name|default:'Hotel'}</bdi>{if $hotel_stars} <span class="travel-hotel-stars" aria-hidden="true">{$hotel_stars}</span>{/if}</h1>
            <span class="travel-hero-badge" id="availability-badge">
                {if $booking_data.is_on_request}
                    {__("novoton_holidays.on_request")}
                {else}
                    ✓ {__("novoton_holidays.available")}
                {/if}
            </span>
            {* Sanitized location line + map link (HotelLocationLine/HotelMapUrl),
               same as the search card and the PDP; the " - " separator mirrors
               main_info_title.post.tpl. Falls back to city/region/country when
               the sanitized line is empty. *}
            <div class="travel-hotel-location">{if $hotel_location_line}{$hotel_location_line|escape:html}{else}{$hotel_city}{if $hotel_region}, {$hotel_region}{/if}{if $hotel_country}, {$hotel_country}{/if}{/if}{if $hotel_map_url}{if $hotel_location_line || $hotel_city} - {/if}<a href="{$hotel_map_url|escape:html}" target="_blank" rel="noopener" class="travel-hotel-map-link">{__("novoton_holidays.location_show_map")|default:"Location - show map"}</a>{/if}</div>
        </div>
        
        {* Body *}
        <div class="travel-reservation-body">
            
            {* Booking Details *}
            <div class="travel-detail-row">
                {if $hotel_image}
                <div class="travel-detail-image">
                    <img src="{$hotel_image}" alt="{$hotel_name}">
                </div>
                {/if}
                
                <div class="travel-detail-info travel-info-summary">
                    <span class="travel-info-key">{__("novoton_holidays.package")}:</span>
                    <span class="travel-info-val">
                        {if $package_name && $package_name != $hotel_name}
                            {$package_name|replace:'%2b':'+'|replace:'%2B':'+'}
                        {elseif $booking_data.package_name}
                            {$booking_data.package_name|replace:'%2b':'+'|replace:'%2B':'+'}
                        {else}
                            {$hotel_name}
                        {/if}
                    </span>
                    
                    <span class="travel-info-key">{__("novoton_holidays.check_in")}:</span>
                    <span class="travel-info-val travel-info-val--highlight">{$booking_data.check_in|date_format:$settings.Appearance.date_format}, {$booking_data.check_in|date_format:"%A"}</span>
                    
                    <span class="travel-info-key">{__("novoton_holidays.check_out")}:</span>
                    <span class="travel-info-val travel-info-val--highlight">{$booking_data.check_out|date_format:$settings.Appearance.date_format}, {$booking_data.check_out|date_format:"%A"}</span>
                    
                    <span class="travel-info-key">{__("novoton_holidays.stay")|default:"Cazare"}:</span>
                    <span class="travel-info-val">{$booking_data.nights} {if $booking_data.nights == 1}{__("novoton_holidays.night")}{else}{__("novoton_holidays.nights")}{/if}</span>
                    
                    {* Multi-room type display *}
                    {if $booking_data.num_rooms > 1 && $booking_data.rooms_data}
                        <span class="travel-info-key">{__("novoton_holidays.rooms")}:</span>
                        <span class="travel-info-val">{$booking_data.num_rooms} {__("novoton_holidays.rooms")}</span>
                        
                        {foreach from=$booking_data.rooms_data item=room_info key=room_idx}
                            {$room_num = $room_idx + 1}
                            <span class="travel-info-key travel-info-key--sub">-> {__("novoton_holidays.room_number")} {$room_num}:</span>
                            <span class="travel-info-val room-type-full" data-room-num="{$room_num}">
                                {if $room_info.room_display}
                                    {$room_info.room_display}
                                {elseif $room_info.room_name}
                                    {$room_info.room_name}
                                {elseif $room_info.room_id}
                                    {call name="format_room_type" room_id=$room_info.room_id}
                                {else}
                                    {call name="format_room_type" room_id=$booking_data.room_id}
                                {/if}
                                {if $room_info.board_name}
                                    - {$room_info.board_name}
                                {elseif $room_info.board_id}
                                    - {$room_info.board_id}
                                {/if}
                                {if $room_info.price}
                                    ({fn_novoton_holidays_format_price($room_info.price|default:0, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter})
                                {/if}
                            </span>
                        {/foreach}
                    {else}
                        <span class="travel-info-key">{__("novoton_holidays.room_type")}:</span>
                        <span class="travel-info-val room-type-full">
                            {if $booking_data.room_type_display}
                                {$booking_data.room_type_display|replace:'%2b':'+'|replace:'%2B':'+'|replace:' 2 1':' 2 +1'|replace:' 2 2':' 2 +2'|replace:' 3 1':' 3 +1'|replace:' 3 2':' 3 +2'}
                            {elseif $booking_data.rooms_data && $booking_data.rooms_data[0].room_type_display}
                                {$booking_data.rooms_data[0].room_type_display|replace:'%2b':'+'|replace:'%2B':'+'|replace:' 2 1':' 2 +1'|replace:' 2 2':' 2 +2'|replace:' 3 1':' 3 +1'|replace:' 3 2':' 3 +2'}
                            {elseif $booking_data.rooms_data && $booking_data.rooms_data[0].room_name}
                                {$booking_data.rooms_data[0].room_name|replace:'%2b':'+'|replace:'%2B':'+'|replace:' 2 1':' 2 +1'|replace:' 2 2':' 2 +2'|replace:' 3 1':' 3 +1'|replace:' 3 2':' 3 +2'}
                            {else}
                                {call name="format_room_type" room_id=$booking_data.room_id}
                            {/if}
                        </span>
                        
                        <span class="travel-info-key">{__("novoton_holidays.board")}:</span>
                        {* Localized label with the raw code in parens, e.g.
                           "Demipensiune (HB +)" — same as the search card. *}
                        <span class="travel-info-val">{fn_novoton_holidays_format_board_name($booking_data.board_id|default:'')}</span>
                    {/if}
                </div>
                
                <div class="booking-price-box">
                    <div id="price-error-message" class="travel-price-error" style="display: none;"></div>
                    <div class="travel-price-label">{__("novoton_holidays.total")}:</div>
                    <div class="price-total" id="novoton-total-price">{fn_novoton_holidays_format_price($booking_data.total_price|default:0, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</div>
                    <span id="price-unverified-badge" class="travel-price-unverified" style="display: none;">
                        ⚠ {__("novoton_holidays.price_unverified")|default:"neconfirmat"}
                    </span>
                    <a href="#" id="refresh-price-link" class="travel-price-refresh" onclick="refreshPrice(); return false;" style="display: none;">
                        🔄 {__("novoton_holidays.refresh_price")|default:"Actualizează prețul"}
                    </a>
                </div>
            </div>
            
            {* Guest Names Section - Multi-Room Support with Split Fields *}
            <div class="travel-form-section guest-names-section">
                <h3>{__("novoton_holidays.enter_booking_details")}</h3>
                
                {* Track global guest number *}
                {$guest_num = 0}
                {$adult_num = 0}
                {$child_num = 0}
                {$is_first_adult = true}
                
                {* Loop through each room *}
                {foreach from=$booking_data.rooms_data item=room key=room_idx}
                    {$room_num = $room_idx + 1}
                    
                    {* Room wrapper with data attribute for JS targeting *}
                    <div class="room-guest-section" data-room-num="{$room_num}">
                    
                    {* Room header if multiple rooms *}
                    {if count($booking_data.rooms_data) > 1}
                    <div class="travel-room-banner room-section-header room-card" data-room-num="{$room_num}">
                        <span> {__("novoton_holidays.room_number")} {$room_num}</span>
                        <span class="travel-room-banner-meta">
                            {$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0}, {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
                            <span class="room-price">{fn_novoton_holidays_format_price($room.price|default:0, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                        </span>
                    </div>
                    {/if}
                    
                    {* Adults for this room *}
                    {for $i=1 to $room.adults}
                        {$adult_num = $adult_num + 1}
                        {$guest_num = $guest_num + 1}
                        
                        {* Get prefilled names from guests_data if in edit mode *}
                        {$prefilled_first_name = ""}
                        {$prefilled_last_name = ""}
                        {$guest_key = "room`$room_num`_adult_`$i`"}
                        {if $is_edit_mode && $booking_data.guests_data}
                            {if isset($booking_data.guests_data[$guest_key])}
                                {$prefilled_last_name = $booking_data.guests_data[$guest_key].last_name|default:''}
                                {$prefilled_first_name = $booking_data.guests_data[$guest_key].first_name|default:''}
                            {/if}
                        {/if}
                        
                        <div class="guest-entry guest-entry-adult">
                            <div class="travel-guest-label">
                                {$guest_num}. {__("novoton_holidays.adult")}
                                {if $is_first_adult}
                                    <span class="travel-holder-tag">{__("novoton_holidays.adult_holder")|regex_replace:"/^.*- /":""}
                                    </span>
                                {/if}
                                {if count($booking_data.rooms_data) > 1}
                                    <small class="travel-muted-note">({__("novoton_holidays.room_number")} {$room_num})</small>
                                {/if}
                            </div>
                            <div class="travel-guest-sublabel">{__("novoton_holidays.regular_bed")}</div>
                            
                            <div class="travel-guest-grid">
                                <div class="travel-guest-field">
                                    <label>{__("novoton_holidays.last_name")}<span class="required">*</span></label>
                                    <input type="text" 
                                           name="guests[room{$room_num}_adult_{$i}][last_name]" 
                                           required 
                                           value="{$prefilled_last_name}"
                                           placeholder="{__('novoton_holidays.last_name')}" />
                                </div>
                                <div class="travel-guest-field">
                                    <label>{__("novoton_holidays.first_name")}<span class="required">*</span></label>
                                    <input type="text" 
                                           name="guests[room{$room_num}_adult_{$i}][first_name]" 
                                           required 
                                           value="{$prefilled_first_name}"
                                           placeholder="{__('novoton_holidays.first_name')}" />
                                </div>
                                <input type="hidden" name="guests[room{$room_num}_adult_{$i}][type]" value="adult" />
                                <input type="hidden" name="guests[room{$room_num}_adult_{$i}][age]" value="30" />
                                <input type="hidden" name="guests[room{$room_num}_adult_{$i}][room]" value="{$room_num}" />
                                {if $is_first_adult}
                                    <input type="hidden" name="guests[room{$room_num}_adult_{$i}][is_holder]" value="1" />
                                {/if}
                            </div>
                        </div>
                        {$is_first_adult = false}
                    {/for}
                    
                    {* Children for this room *}
                    {if $room.children > 0}
                        {$max_children = min($room.children, 5)}
                        {for $i=1 to $max_children}
                            {$child_num = $child_num + 1}
                            {$guest_num = $guest_num + 1}
                            
                            {* Get pre-filled age from room's childrenAges array *}
                            {$prefilled_age = ""}
                            {if isset($room.childrenAges[$i-1])}
                                {$prefilled_age = $room.childrenAges[$i-1]}
                            {/if}
                            
                            {* Get prefilled names from guests_data if in edit mode *}
                            {$prefilled_child_first_name = ""}
                            {$prefilled_child_last_name = ""}
                            {$prefilled_child_dob = ""}
                            {$child_guest_key = "room`$room_num`_child_`$i`"}
                            {if $is_edit_mode && $booking_data.guests_data}
                                {if isset($booking_data.guests_data[$child_guest_key])}
                                    {$prefilled_child_last_name = $booking_data.guests_data[$child_guest_key].last_name|default:''}
                                    {$prefilled_child_first_name = $booking_data.guests_data[$child_guest_key].first_name|default:''}
                                    {* Try dob first, then convert birthday if available *}
                                    {if $booking_data.guests_data[$child_guest_key].dob}
                                        {$prefilled_child_dob = $booking_data.guests_data[$child_guest_key].dob}
                                    {elseif $booking_data.guests_data[$child_guest_key].birthday}
                                        {* Convert YYYY-MM-DD to DD/MM/YYYY *}
                                        {$prefilled_child_dob = $booking_data.guests_data[$child_guest_key].birthday|date_format:"%d/%m/%Y"}
                                    {/if}
                                {/if}
                            {/if}
                            
                            {* Get child age from search - this is the age at check-in *}
                            {$child_age_at_checkin = $prefilled_age|default:0}
                            
                            <div class="guest-entry guest-entry-child" data-room="{$room_num}" data-child="{$i}" data-original-age="{$child_age_at_checkin}">
                                <div class="travel-guest-label">
                                    {$guest_num}. {__("novoton_holidays.child")} {$i}
                                    <span class="child-age-display" id="child_age_display_r{$room_num}_c{$i}">({$child_age_at_checkin} {if $child_age_at_checkin == 1}{__("novoton_holidays.age_label_singular")|default:"an"}{else}{__("novoton_holidays.age_label")|default:"ani"}{/if})</span>
                                    {if count($booking_data.rooms_data) > 1}
                                        <small class="travel-muted-note">- {__("novoton_holidays.room_number")} {$room_num}</small>
                                    {/if}
                                </div>
                                
                                {* DOB age info/warning message area *}
                                <div id="dob_info_r{$room_num}_c{$i}" class="dob-info-message travel-dob-info" style="display: none;"></div>
                                
                                {* Row 1: Last Name + First Name (side by side on desktop, stacked on mobile) *}
                                <div class="travel-guest-grid">
                                    <div class="travel-guest-field">
                                        <label>{__("novoton_holidays.last_name")}<span class="required">*</span></label>
                                        <input type="text" 
                                               name="guests[room{$room_num}_child_{$i}][last_name]" 
                                               required 
                                               value="{$prefilled_child_last_name}"
                                               placeholder="{__('novoton_holidays.last_name')}" />
                                    </div>
                                    <div class="travel-guest-field">
                                        <label>{__("novoton_holidays.first_name")}<span class="required">*</span></label>
                                        <input type="text" 
                                               name="guests[room{$room_num}_child_{$i}][first_name]" 
                                               required 
                                               value="{$prefilled_child_first_name}"
                                               placeholder="{__('novoton_holidays.first_name')}" />
                                    </div>
                                </div>
                                
                                {* Row 2: Date of Birth (own row for better visibility) *}
                                <div class="travel-guest-grid">
                                    <div class="travel-guest-field travel-guest-field--dob">
                                        <label>{__("novoton_holidays.date_of_birth")} <span class="travel-muted-note">(ex: 27/05/2020)</span><span class="required">*</span></label>
                                        <input type="tel" 
                                               name="guests[room{$room_num}_child_{$i}][dob]" 
                                               id="child_dob_r{$room_num}_c{$i}" 
                                               class="dob-masked-input"
                                               required 
                                               maxlength="10"
                                               inputmode="numeric"
                                               autocomplete="off"
                                               placeholder="ZZ/LL/AAAA"
                                               value="{$prefilled_child_dob}"
                                               onkeydown="TravelBooking.handleDobKeydown(event)"
                                               oninput="TravelBooking.applyDobMask(this)"
                                               onblur="validateAndCheckAge('r{$room_num}_c{$i}', {$child_age_at_checkin})" />
                                    </div>
                                    {* Hidden fields *}
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][age]" id="child_age_r{$room_num}_c{$i}" value="{$child_age_at_checkin}" />
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][type]" id="child_type_r{$room_num}_c{$i}" value="child" />
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][room]" value="{$room_num}" />
                                </div>
                                <div id="dob_error_r{$room_num}_c{$i}" class="dob-validation-error" style="display: none;"></div>
                            </div>
                        {/for}
                    {/if}
                    </div>{* Close room-guest-section *}
                {/foreach}
            </div>
            
            {* Important Info *}
            {if $payment_terms || $cancellation_terms}
            <div class="travel-form-section travel-important-info">
                <h3>{__("novoton_holidays.important_info")}</h3>
                
                {if $payment_terms}
                <div class="travel-info-block">
                    <h4>{__("novoton_holidays.terms_of_payment")}</h4>
                    <p>{$payment_terms|nl2br}</p>
                </div>
                {/if}
                
                {if $cancellation_terms}
                <div class="travel-info-block">
                    <h4>{__("novoton_holidays.cancellation_terms")}</h4>
                    <p>{$cancellation_terms|nl2br}</p>
                </div>
                {/if}
            </div>
            {/if}
            
            {* Form Actions *}
            <div class="travel-form-actions">
                                {* Build rooms_data JSON for URL *}
                {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|json_encode|escape:'url'}
                {elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|escape:'url'}
                {else}
                    {$rooms_data_url = ''}
                {/if}
                <a href="{fn_url("novoton_booking.search?hotel_id=`$booking_data.hotel_id`&product_id=`$product_id`&check_in=`$booking_data.check_in`&check_out=`$booking_data.check_out`&nights=`$booking_data.nights`&adults=`$booking_data.adults`&children=`$booking_data.children`&children_ages=`$booking_data.children_ages`&rooms=`$booking_data.num_rooms|default:1`&rooms_data=`$rooms_data_url`")}" class="travel-btn-back">
                    <- {__("novoton_holidays.back_to_results")}
                </a>
                <button type="submit" class="travel-btn--primary" id="booking-submit-btn">
                    {if $is_edit_mode}{__("novoton_holidays.update_booking")}{else}{__("novoton_holidays.add_to_cart")}{/if}
                </button>
            </div>
        </div>
    </form>
</div>

{* A73: Include DOB validation script with price recalculation *}
{* A74e: Include external booking form validation JS *}
{script src="js/addons/travel_core/booking-form-validation.js"}

{* Client i18n for the booking form — shared travel_core partial (replaces
   the incremental window.NovotonTranslations.* block); currency/coeff passed
   for the price-recalculation display. Emits window.TravelTranslations +
   the NovotonTranslations alias the logic below reads. *}
{include file="addons/travel_core/components/travel_i18n.tpl"
         travel_i18n_currency=$novoton_display_symbol
         travel_i18n_coeff=$novoton_display_coefficient}

<script>

// HTML escape utility to prevent XSS
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Debug logging - enabled via NovotonConfig.debug or ?novoton_debug=1 in URL
var novotonDebug = (window.NovotonConfig && window.NovotonConfig.debug) || (window.location.search.indexOf('novoton_debug') !== -1);
function novotonLog(message, data) {
    if (novotonDebug && console && console.log) {
        if (data !== undefined) {
            console.log('[Novoton] ' + message, data);
        } else {
            console.log('[Novoton] ' + message);
        }
    }
}

// Form submit validation
document.getElementById('novoton-booking-form').addEventListener('submit', function(e) {
    var allFilled = true;
    this.querySelectorAll('input[required], select[required]').forEach(function(el) {
        if (!el.value.trim()) {
            allFilled = false;
            el.style.borderColor = '#dc3545';
        } else {
            el.style.borderColor = '#ccc';
        }
    });

    if (!allFilled) {
        e.preventDefault();
        alert('{__("novoton_holidays.fill_all_fields")|escape:"javascript"}');
        return;
    }

    // Check for DOB validation errors
    var dobErrors = document.querySelectorAll('.dob-validation-error');
    var hasError = false;
    dobErrors.forEach(function(el) {
        if (el.style.display !== 'none' && el.textContent !== '') {
            hasError = true;
        }
    });

    if (hasError) {
        e.preventDefault();
        alert('{__("novoton_holidays.dob_validation_error")|default:"Verificati datele de nastere introduse."|escape:"javascript"}');
    }
});

// Room limits from hotel API
var roomLimits = {$booking_data.current_room_limits|default:[]|json_encode nofilter};

// Booking data for price recalculation (used by external module)
window.bookingData = {ldelim}
    checkIn: '{$booking_data.check_in|default:$smarty.now|date_format:"%Y-%m-%d"}',
    hotelId: '{$booking_data.hotel_id|default:0}',
    productId: '{$product_id|default:0}',
    currentPrice: {$booking_data.total_price|default:0},
    roomId: '{$booking_data.room_id|default:""}',
    boardId: '{$booking_data.board_id|default:""}',
    packageName: '{$booking_data.package_name|default:""|escape:"javascript"}',
    nights: {$booking_data.nights|default:7},
    adults: {$booking_data.adults|default:2},
    numRooms: {$booking_data.num_rooms|default:1},
    maxAdults: roomLimits.max_adults || 4,
    maxChildren: roomLimits.max_children || 2,
    minPax: roomLimits.min_pax || 1,
    totalCapacity: (roomLimits.rb || 2) + (roomLimits.eb || 0),
    roomsData: {$booking_data.rooms_data_json|default:"[]" nofilter},
    calendarPrices: {$calendar_prices_json|default:'{}' nofilter},
    calendarPricesCurrency: '{$calendar_prices_currency|default:$smarty.const.CART_PRIMARY_CURRENCY|escape:"javascript"}',
    showCalendarPrices: {if $show_calendar_prices == 'Y'}true{else}false{/if}
{rdelim};

// DOB masking is wired directly to the shared TravelBooking.* API
// (booking-form-validation.js), matching the sphinx booking form.

// Main validation function for DOB fields
function validateAndCheckAge(id, originalAge) {
    var dobInput = document.getElementById('child_dob_' + id);
    var errorDiv = document.getElementById('dob_error_' + id);
    var infoDiv = document.getElementById('dob_info_' + id);
    var calcAgeInput = document.getElementById('child_age_' + id);
    var ageDisplay = document.getElementById('child_age_display_' + id);

    novotonLog('validateAndCheckAge called', {ldelim} id: id, originalAge: originalAge {rdelim});

    if (!dobInput) {ldelim} novotonLog('DOB input not found: child_dob_' + id); return; {rdelim}

    var dobValue = dobInput.value;
    novotonLog('DOB value', dobValue);

    // Clear previous states
    dobInput.style.borderColor = '';
    dobInput.style.backgroundColor = '';
    if (errorDiv) {ldelim} errorDiv.style.display = 'none'; errorDiv.textContent = ''; {rdelim}
    if (infoDiv) {ldelim} infoDiv.style.display = 'none'; infoDiv.textContent = ''; {rdelim}
    // Clear previous price error when user re-enters DOB
    hidePriceError();

    // Skip if empty or incomplete
    if (!dobValue || dobValue.length < 10) {ldelim} novotonLog('DOB incomplete, skipping'); return; {rdelim}

    // Parse DOB - requires booking-form-validation.js
    if (typeof parseDobMasked !== 'function') {ldelim}
        novotonLog('parseDobMasked not loaded yet');
        return;
    {rdelim}
    var parsed = parseDobMasked(dobValue);
    if (!parsed) {ldelim}
        novotonLog('DOB parse failed');
        showDobError(dobInput, errorDiv, 'Format invalid');
        return;
    {rdelim}
    novotonLog('DOB parsed', parsed);

    // Validate ranges
    if (parsed.day < 1 || parsed.day > 31) {ldelim}
        showDobError(dobInput, errorDiv, 'Ziua invalida (1-31)');
        return;
    {rdelim}
    if (parsed.month < 1 || parsed.month > 12) {ldelim}
        showDobError(dobInput, errorDiv, 'Luna invalida (1-12)');
        return;
    {rdelim}
    var currentYear = new Date().getFullYear();
    if (parsed.year < 1925 || parsed.year > currentYear) {ldelim}
        showDobError(dobInput, errorDiv, 'Anul invalid');
        return;
    {rdelim}

    // Check if DOB is in the future
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var birthDate = new Date(parsed.year, parsed.month - 1, parsed.day);
    if (birthDate > today) {ldelim}
        showDobError(dobInput, errorDiv, 'Data nasterii nu poate fi in viitor');
        return;
    {rdelim}

    // Calculate age at check-in - requires booking-form-validation.js
    if (typeof calculateAgeAtDate !== 'function') {ldelim}
        novotonLog('calculateAgeAtDate not loaded yet');
        return;
    {rdelim}
    var checkInDate = new Date(window.bookingData.checkIn);
    var calculatedAge = calculateAgeAtDate(birthDate, checkInDate);

    novotonLog('Age calculation', {ldelim}
        dob: dobValue,
        checkIn: window.bookingData.checkIn,
        calculatedAge: calculatedAge
    {rdelim});

    // Update hidden field
    if (calcAgeInput) calcAgeInput.value = calculatedAge;

    // Update age display - use translation with singular/plural (Romanian: "1 an", "2 ani")
    if (ageDisplay) {ldelim}
        var ageLabel;
        if (calculatedAge === 1) {ldelim}
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabelSingular ? window.NovotonTranslations.ageLabelSingular : 'an';
        {rdelim} else {ldelim}
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabel ? window.NovotonTranslations.ageLabel : 'ani';
        {rdelim}
        ageDisplay.textContent = '(' + calculatedAge + ' ' + ageLabel + ')';
    {rdelim}

    if (calculatedAge >= 18) {ldelim}
        var t = window.NovotonTranslations || {ldelim}{rdelim};
        var notChildMsg = t.notChild || 'La check-in, copilul va avea';
        var yearsLabel = t.ageLabel || 'ani';
        var mustBeUnder18 = t.mustBeUnder18 || 'Trebuie sa fie sub 18 ani.';
        showDobError(dobInput, errorDiv, notChildMsg + ' ' + calculatedAge + ' ' + yearsLabel + '. ' + mustBeUnder18);
        showPriceError(t.childAgeNotAllowed || 'Vârsta copilului depășește limita');
        return;
    {rdelim}

    // Valid child age — show green, let API determine price
    dobInput.style.borderColor = '#28a745';
    dobInput.style.backgroundColor = '#f0fff0';

    // Extract room number from id (format: rX_cY where X=room, Y=child)
    var roomMatch = id.match(/r(\d+)_c\d+/);
    var roomNum = roomMatch ? parseInt(roomMatch[1], 10) : 1;

    // Trigger price recalculation for this specific room
    novotonLog('Triggering price recalculation for room ' + roomNum);
    collectAndRecalculate(roomNum);
}

function showDobError(input, errorDiv, message) {
    input.style.borderColor = '#dc3545';
    input.style.backgroundColor = '#fff5f5';
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }
}

// Per-room debounce timers for price recalculation
var priceRecalcDebouncers = {};

function collectAndRecalculate(roomNum) {
    roomNum = roomNum || 1;

    // Debounce per room: wait 600ms after last DOB change before recalculating
    // This prevents multiple API calls when user enters DOBs for multiple children
    // Using per-room timers so room 1 and room 2 don't overwrite each other
    if (priceRecalcDebouncers[roomNum]) {
        clearTimeout(priceRecalcDebouncers[roomNum]);
    }

    priceRecalcDebouncers[roomNum] = setTimeout(function() {
        doCollectAndRecalculate(roomNum);
    }, 600);
}

// Collect the per-child ages from the hidden child_age_* inputs.
// For multi-room: only this room's children; for single room: all children.
// The inputs are pre-seeded from the search occupancy and updated on DOB
// entry, so they always reflect the party this offer was priced for.
function collectChildrenAges(roomNum) {
    var childrenAges = [];
    var isMultiRoom = window.bookingData && window.bookingData.numRooms > 1;
    var selector = isMultiRoom
        ? '[id^="child_age_r' + roomNum + '_c"]'
        : '[id^="child_age_"]';

    document.querySelectorAll(selector).forEach(function(input) {
        var age = parseInt(input.value, 10);
        if (!isNaN(age) && age >= 0 && age < 18) {
            childrenAges.push(age);
        }
    });
    novotonLog('Collected children ages' + (isMultiRoom ? ' for room ' + roomNum : ''), childrenAges);
    return childrenAges;
}

function doCollectAndRecalculate(roomNum) {
    var childrenAges = collectChildrenAges(roomNum);

    if (childrenAges.length > 0) {
        triggerPriceRecalculationInline(childrenAges, roomNum);
    }
}

// A74e: Inline price recalculation to avoid external JS loading issues
// A74y: Updated to handle per-room recalculation for multi-room bookings
function triggerPriceRecalculationInline(childrenAges, roomNum, isInitialLoad) {
    roomNum = roomNum || 1;
    isInitialLoad = isInitialLoad || false;
    novotonLog('triggerPriceRecalculationInline called for room ' + roomNum, childrenAges);
    
    if (!window.bookingData) {
        novotonLog('bookingData not defined');
        return;
    }
    
    var isMultiRoom = window.bookingData.numRooms > 1 && window.bookingData.roomsData && window.bookingData.roomsData.length > 0;
    var roomIdx = roomNum - 1;
    
    // Get room-specific data for multi-room, or use single room data
    var roomData = {};
    if (isMultiRoom && window.bookingData.roomsData[roomIdx]) {
        roomData = window.bookingData.roomsData[roomIdx];
        novotonLog('Using room-specific data for room ' + roomNum, roomData);
    } else {
        roomData = {
            room_id: window.bookingData.roomId,
            board_id: window.bookingData.boardId,
            adults: window.bookingData.adults,
            price: window.bookingData.currentPrice
        };
    }
    
    // Show loading state for the specific room
    var priceEl = isMultiRoom ? 
        document.querySelector('.room-card[data-room-num="' + roomNum + '"] .room-price') || document.querySelector('.price-total') :
        document.querySelector('.price-total');
    var loadingIndicator = document.getElementById('price-loading-indicator');
    
    if (loadingIndicator) loadingIndicator.style.display = 'inline-block';
    if (priceEl) priceEl.style.opacity = '0.5';
    
    var requestData = {
        hotel_id: window.bookingData.hotelId,
        room_id: roomData.room_id || window.bookingData.roomId,
        board_id: roomData.board_id || window.bookingData.boardId,
        check_in: window.bookingData.checkIn,
        nights: window.bookingData.nights,
        adults: roomData.adults || window.bookingData.adults,
        children_ages: childrenAges,
        package_name: roomData.package_name || window.bookingData.packageName,
        original_price: roomData.price || window.bookingData.currentPrice,
        room_num: roomNum,
        is_multi_room: isMultiRoom
    };
    
    novotonLog('AJAX request', requestData);

    // Build a clean AJAX URL with only dispatch — all data goes in the JSON body.
    // Do NOT inherit parent page URL params (children_ages[], hotel_id, etc.)
    // as CS-Cart's init processes them through __() causing PHP warnings.
    var ajaxUrl = '{"novoton_booking.ajax_recalculate_price"|fn_url}';
    novotonLog('AJAX URL', ajaxUrl);
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(requestData)
    })
    .then(function(response) { 
        novotonLog('Response status: ' + response.status);
        return response.text(); // Get raw text first
    })
    .then(function(text) {
        novotonLog('Raw response', text.substring(0, 200));
        // Try to parse JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            novotonLog('JSON parse error: ' + e.message);
            throw e;
        }
    })
    .then(function(data) {
        novotonLog('AJAX response', data);

        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';

        if (data.success) {
            // Hide any previous error message
            hidePriceError();

            try {

            if (data.room_changed) {
                // The quote is for a DIFFERENT room — do NOT commit the price
                // yet. acceptRoomChangeInline applies price + room together
                // once the guest accepts; declining keeps the original state.
                window._roomChangeContext = { roomNum: roomNum, isMultiRoom: isMultiRoom, isInitialLoad: isInitialLoad };
                showRoomChangeModal(data);
            } else {
                applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad);
            }

            // Hide any previous notice
            var notice = document.getElementById('price-recalc-notice');
            if (notice) notice.style.display = 'none';

            } catch (uiError) {
                // JS error in UI update must NOT propagate to .catch() which disables submit
                novotonLog('UI update error (non-fatal): ' + uiError.message);
            }

        } else {
            novotonLog('Recalculation failed: ' + (data.message || ''));
            // API returned success:false — show info notice, keep form submittable
            showInfoNotice('{__("novoton_holidays.price_verified_at_checkout")|default:"Prețul va fi verificat la finalizare"}');
            if (priceEl) priceEl.style.opacity = '1';
        }
    })
    .catch(function(error) {
        novotonLog('AJAX error (no API response): ' + error);
        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';
        // Network/JSON parse error — show warning but keep form submittable
        // Server-side will verify the price at checkout anyway
        showInfoNotice('{__("novoton_holidays.price_verified_at_checkout")|default:"Prețul va fi verificat la finalizare"}');
    });
}

// Commit a successful re-quote to the price displays, the hidden total_price
// input and bookingData. Called directly when the room is unchanged; when the
// server proposed a DIFFERENT room it runs only from acceptRoomChangeInline,
// so an undecided (or declined) room change never overwrites the form price.
function applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad) {
    var roomIdx = roomNum - 1;
    var newPrice = parseFloat(data.new_price) || 0;
    var coeff = window.NovotonTranslations.currencyCoeff || 1;
    var currSym = window.NovotonTranslations.currency || 'EUR';
    novotonLog('New price for room ' + roomNum + ': ' + newPrice + ' (coeff=' + coeff + ')');

    if (isMultiRoom && window.bookingData.roomsData && window.bookingData.roomsData[roomIdx]) {
        // Multi-room: Update only this room's price (EUR for form submission)
        window.bookingData.roomsData[roomIdx].price = newPrice;

        // Update the room card price display (converted to display currency)
        var roomPriceEl = document.querySelector('.room-card[data-room-num="' + roomNum + '"] .room-price');
        if (roomPriceEl) {
            roomPriceEl.textContent = Math.round(newPrice * coeff) + ' ' + currSym;
        }

        // Recalculate total from all rooms (in EUR)
        var totalPrice = 0;
        for (var i = 0; i < window.bookingData.roomsData.length; i++) {
            totalPrice += parseFloat(window.bookingData.roomsData[i].price) || 0;
        }

        novotonLog('New total price: ' + totalPrice);

        // Update total price display (converted to display currency)
        var displayTotal = (totalPrice * coeff).toFixed(2);
        document.querySelectorAll('.price-total').forEach(function(el) {
            el.textContent = displayTotal;
        });

        // A76i: Update hidden total_price input for form submission (EUR)
        var hiddenPriceInput = document.querySelector('input[name="total_price"]');
        if (hiddenPriceInput) {
            hiddenPriceInput.value = totalPrice.toFixed(2);
            novotonLog('Updated hidden total_price to: ' + totalPrice.toFixed(2));
        }

        // Update bookingData total
        var priceDiff = totalPrice - window.bookingData.currentPrice;
        window.bookingData.currentPrice = totalPrice;

        // Show price change notification (skip on initial load — wording is child-age specific)
        if (!isInitialLoad && Math.abs(priceDiff) > 0.01) {
            showPriceNotification(priceDiff * coeff);
        }
    } else {
        // Single room: Update total price display (converted to display currency)
        var displayPrice = (newPrice * coeff).toFixed(2);
        document.querySelectorAll('.price-total').forEach(function(el) {
            el.textContent = displayPrice;
        });

        // A76i: Update hidden total_price input for form submission (EUR)
        var hiddenPriceInput = document.querySelector('input[name="total_price"]');
        if (hiddenPriceInput) {
            hiddenPriceInput.value = newPrice.toFixed(2);
            novotonLog('Updated hidden total_price to: ' + newPrice.toFixed(2));
        }

        // Show price change notification (skip on initial load — wording is child-age specific)
        if (!isInitialLoad && data.price_difference && data.price_difference !== 0) {
            showPriceNotification(data.price_difference * coeff);
        }

        // Update bookingData (EUR)
        window.bookingData.currentPrice = newPrice;
    }
}

// Show price error, refresh link, unverified badge, and disable submit
function showPriceError(message) {
    var errorEl = document.getElementById('price-error-message');
    var refreshLink = document.getElementById('refresh-price-link');
    var unverifiedBadge = document.getElementById('price-unverified-badge');
    var submitBtn = document.getElementById('booking-submit-btn');
    var availBadge = document.getElementById('availability-badge');

    if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }
    if (refreshLink) {
        refreshLink.style.display = 'block';
    }
    if (unverifiedBadge) {
        unverifiedBadge.style.display = 'inline-block';
    }
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
        submitBtn.title = '{__("novoton_holidays.price_must_be_verified")|default:"Prețul trebuie verificat înainte de a continua"}';
    }
    if (availBadge) {
        availBadge.style.setProperty('background', '#F59E0B', 'important');
        availBadge.innerHTML = '<strong>{__("novoton_holidays.unavailable_for_child_age")|default:"Indisponibil"}</strong><br><span style="font-size:11px;">{__("novoton_holidays.unavailable_for_child_age_sub")|default:"pentru vârsta copilului"}</span>';
    }
}

// Hide price error, refresh link, unverified badge, and re-enable submit
function hidePriceError() {
    var errorEl = document.getElementById('price-error-message');
    var refreshLink = document.getElementById('refresh-price-link');
    var unverifiedBadge = document.getElementById('price-unverified-badge');
    var submitBtn = document.getElementById('booking-submit-btn');
    var availBadge = document.getElementById('availability-badge');

    if (errorEl) errorEl.style.display = 'none';
    if (refreshLink) refreshLink.style.display = 'none';
    if (unverifiedBadge) unverifiedBadge.style.display = 'none';
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
        submitBtn.title = '';
    }
    if (availBadge) {
        availBadge.style.setProperty('background', '#28a745', 'important');
        availBadge.innerHTML = '✓ {__("novoton_holidays.available")|default:"Disponibil"}';
    }
}

// Refresh price manually
function refreshPrice() {
    novotonLog('Manual price refresh triggered');
    hidePriceError();

    // Collect all children ages from the form
    var childrenAges = [];
    document.querySelectorAll('[id^="child_age_"]').forEach(function(input) {
        var age = parseInt(input.value, 10);
        if (!isNaN(age) && age >= 0 && age < 18) {
            childrenAges.push(age);
        }
    });

    novotonLog('Refreshing with children ages', childrenAges);
    triggerPriceRecalculationInline(childrenAges, 1);
}

function showPriceNotification(difference) {
    // Show single notification above guest details heading
    var notif = document.getElementById('price-change-notification');
    if (!notif) {
        notif = document.createElement('div');
        notif.id = 'price-change-notification';
        notif.style.cssText = 'background:#fff3cd;border-left:4px solid #ffc107;color:#856404;padding:8px 15px;margin:0 0 10px 0;border-radius:4px;font-size:14px;';
        var heading = document.querySelector('.guest-names-section h3');
        if (heading && heading.parentNode) {
            heading.parentNode.insertBefore(notif, heading);
        }
    }
    var changeText = difference > 0 ? '+' + difference.toFixed(2) : difference.toFixed(2);
    var changeColor = difference > 0 ? '#dc3545' : '#28a745';
    notif.innerHTML = '{__("novoton_holidays.price_updated_child_age")|default:"Pre\u021bul a fost actualizat \u00een func\u021bie de v\u00e2rsta copilului"}: <strong style="color:' + changeColor + '">' + changeText + ' ' + (window.NovotonTranslations.currency || 'EUR') + '</strong>';
    // Note: difference is already in display currency (multiplied by coefficient before calling this function)
    notif.style.display = 'block';
}

function showInfoNotice(message) {
    var notif = document.getElementById('price-recalc-notice');
    if (!notif) {
        notif = document.createElement('div');
        notif.id = 'price-recalc-notice';
        notif.style.cssText = 'background:#e7f3ff;border-left:4px solid #0071c2;color:#004085;padding:10px 15px;margin:10px 0;border-radius:4px;font-size:13px;';
        var priceBox = document.querySelector('.booking-price-box');
        if (priceBox && priceBox.parentNode) {
            priceBox.parentNode.insertBefore(notif, priceBox.nextSibling);
        }
    }
    notif.innerHTML = ' ' + message;
    notif.style.display = 'block';
}

function showRoomChangeModal(data) {
    novotonLog('Showing room change modal', data);
    
    var existing = document.getElementById('room-change-warning');
    if (existing) existing.remove();
    
    var coeff = window.NovotonTranslations.currencyCoeff || 1;
    var currSym = window.NovotonTranslations.currency || 'EUR';
    var priceDiff = (parseFloat(data.price_difference) || 0) * coeff;
    var newPrice = (parseFloat(data.new_price) || 0) * coeff;
    var originalPrice = (parseFloat(data.original_price) || 0) * coeff;

    var priceDiffText = '', priceDiffStyle = '';
    if (priceDiff > 0) {
        priceDiffText = '+' + priceDiff.toFixed(2) + ' ' + currSym;
        priceDiffStyle = 'color:#dc3545;font-weight:bold;';
    } else if (priceDiff < 0) {
        priceDiffText = priceDiff.toFixed(2) + ' ' + currSym;
        priceDiffStyle = 'color:#28a745;font-weight:bold;';
    }
    
    var html = '<div id="room-change-warning" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;">' +
        '<div style="background:#fff;border-radius:12px;padding:25px;max-width:450px;margin:20px;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
        '<div style="text-align:center;margin-bottom:20px;">' +
            '<div style="font-size:40px;margin-bottom:10px;"></div>' +
            '<h3 style="margin:0;color:#856404;font-size:18px;">{__("novoton_holidays.room_changed_title")|default:"Camera s-a modificat"}</h3>' +
        '</div>' +
        '<p style="text-align:center;color:#666;margin-bottom:20px;font-size:14px;">{__("novoton_holidays.room_changed_due_to_age")|default:"Camera selectata nu este disponibila pentru varsta copilului introdusa."}</p>' +
        '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:15px;margin-bottom:20px;">' +
            '<div style="display:flex;align-items:center;justify-content:center;gap:15px;flex-wrap:wrap;">' +
                '<div style="text-align:center;">' +
                    '<div style="font-size:11px;color:#666;text-transform:uppercase;">{__("novoton_holidays.original_room")|default:"Camera selectata"}</div>' +
                    '<div style="font-weight:600;color:#856404;text-decoration:line-through;">' + escapeHtml(data.original_room || '') + '</div>' +
                '</div>' +
                '<div style="font-size:24px;color:#856404;">-></div>' +
                '<div style="text-align:center;">' +
                    '<div style="font-size:11px;color:#666;text-transform:uppercase;">{__("novoton_holidays.new_room")|default:"Camera noua"}</div>' +
                    '<div style="font-weight:600;color:#155724;">' + escapeHtml(data.new_room || '') + '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div style="background:#f8f9fa;border-radius:8px;padding:15px;margin-bottom:20px;text-align:center;">' +
            '<div style="font-size:12px;color:#666;margin-bottom:5px;">{__("novoton_holidays.price_change")|default:"Modificare pret"}</div>' +
            '<div style="font-size:20px;">' +
                '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' ' + currSym + '</span> ' +
                '<span style="' + priceDiffStyle + '">(' + priceDiffText + ')</span> ' +
                '<span style="font-weight:bold;color:#003580;">' + newPrice.toFixed(2) + ' ' + currSym + '</span>' +
            '</div>' +
        '</div>' +
        '<div style="display:flex;gap:10px;justify-content:center;">' +
            '<button type="button" onclick="closeRoomModal();window.history.back();" style="padding:12px 20px;border:2px solid #003580;background:#fff;color:#003580;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;"><- {__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"}</button>' +
            '<button type="button" onclick="acceptRoomChangeInline()" style="padding:12px 20px;border:none;background:#003580;color:#fff;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"} -></button>' +
        '</div>' +
        '</div></div>';
    
    window._roomChangeData = data;
    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper.firstChild);
}

function closeRoomModal() {
    var modal = document.getElementById('room-change-warning');
    if (modal) modal.remove();
}

function acceptRoomChangeInline() {
    var data = window._roomChangeData || {};
    var ctx = window._roomChangeContext || {};
    closeRoomModal();

    // Commit the accepted quote: price first, then the room identity below,
    // so the form never holds the new price with the old room or vice versa.
    applyRecalculatedPrice(data, ctx.roomNum || 1, !!ctx.isMultiRoom, !!ctx.isInitialLoad);

    // Format room name for display using translated room type prefix
    var displayRoom = data.new_room || '';
    if (displayRoom && !displayRoom.toLowerCase().includes('camer')) {
        var roomTypeLabel = '{__("novoton_holidays.room_type_double")|default:"Double Room"|escape:"javascript"}';
        displayRoom = roomTypeLabel + ' (' + displayRoom + ')';
    }
    
    // Build display text with board and price if available
    var fullDisplayText = displayRoom;
    if (data.board_name) {
        fullDisplayText += ' - ' + data.board_name;
    }
    if (data.new_price) {
        var displayNewPrice = parseFloat(data.new_price) * (window.NovotonTranslations.currencyCoeff || 1);
        fullDisplayText += ' (' + displayNewPrice.toFixed(0) + ' ' + (window.NovotonTranslations.currency || 'EUR') + ')';
    }
    
    // Check if this is for a specific room in multi-room booking
    // (the recalc response carries no room_num — fall back to the request context)
    var roomNum = data.room_num || data.roomNum || (ctx.isMultiRoom ? ctx.roomNum : null);
    
    if (roomNum) {
        // Multi-room: Update only the specific room's header
        var specificRoomEl = document.querySelector('.room-type-full[data-room-num="' + roomNum + '"]');
        if (specificRoomEl) {
            specificRoomEl.textContent = fullDisplayText;
        }
        
        // Also update room-name elements with matching data-room-num
        document.querySelectorAll('.room-name[data-room-num="' + roomNum + '"], [data-room-name][data-room-num="' + roomNum + '"]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });
    } else {
        // Single room or fallback: Update all room displays
        document.querySelectorAll('.room-name, [data-room-name]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });
        
        // Update room type in header (Tip Camera)
        document.querySelectorAll('.room-type-full').forEach(function(el) {
            // For multi-room displays with board/price info
            if (el.hasAttribute('data-room-num')) {
                el.textContent = fullDisplayText;
            } else {
                el.textContent = displayRoom;
            }
        });
    }
    
    // Update hidden field
    var roomInput = document.querySelector('input[name="room_id"]');
    if (roomInput) roomInput.value = data.new_room || '';
    
    // Update bookingData
    if (window.bookingData) {
        window.bookingData.roomId = data.new_room || '';
        window.bookingData.roomName = displayRoom;
        
        // If multi-room, also update the rooms_data array
        if (roomNum && window.bookingData.roomsData) {
            var idx = parseInt(roomNum) - 1;
            if (window.bookingData.roomsData[idx]) {
                window.bookingData.roomsData[idx].room_id = data.new_room || '';
                window.bookingData.roomsData[idx].room_name = displayRoom;
                if (data.board_id) window.bookingData.roomsData[idx].board_id = data.board_id;
                if (data.board_name) window.bookingData.roomsData[idx].board_name = data.board_name;
                if (data.new_price) window.bookingData.roomsData[idx].price = parseFloat(data.new_price);
            }
        }
    }
    
    // Show confirmation
    var notif = document.createElement('div');
    notif.style.cssText = 'background:#d4edda;border-left:4px solid #28a745;color:#155724;padding:15px;margin:15px 0;border-radius:4px;font-size:14px;';
    var roomLabel = roomNum ? '{__("novoton_holidays.room_number")|default:"Camera"} ' + roomNum + ': ' : '';
    var confirmPrice = ((parseFloat(data.new_price) || 0) * (window.NovotonTranslations.currencyCoeff || 1)).toFixed(2);
    notif.innerHTML = '✓ <strong>{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"}</strong> ' + escapeHtml(roomLabel) + escapeHtml(data.new_room || '') + ' - ' + confirmPrice + ' ' + (window.NovotonTranslations.currency || 'EUR');
    
    var section = document.querySelector('.guest-names-section h3');
    if (section && section.parentNode) {
        section.parentNode.insertBefore(notif, section.nextSibling);
    }
    
    setTimeout(function() { if (notif.parentNode) notif.remove(); }, 10000);
}

// Verify price on initial booking-form load using the actual room/board IDs.
// The search page uses blank room_id/board_id (one API call for all rooms); the Novoton
// API can return a different price for that query than for a room-specific query.
// Calling ajax_recalculate_price here populates the cache with the binding price and
// updates the hidden total_price field so add_to_cart sees no change.
// MUST send the real searched children ages (pre-seeded in the hidden
// child_age_* inputs): an empty list re-quotes for adults-only occupancy, so
// the operator omits the child-capacity room and the endpoint substitutes an
// arbitrary 2-adult room — popping a bogus "room changed" modal before any
// DOB is typed (QUAD 3+1 SEA -> DBL 2+0 regression).
document.addEventListener('DOMContentLoaded', function() {
    if (typeof triggerPriceRecalculationInline !== 'function') return;
    if (!window.bookingData || !window.bookingData.hotelId) return;

    var isMultiRoom = window.bookingData.numRooms > 1 &&
                      window.bookingData.roomsData &&
                      window.bookingData.roomsData.length > 1;

    if (isMultiRoom) {
        for (var r = 1; r <= window.bookingData.numRooms; r++) {
            (function(roomNum) {
                setTimeout(function() {
                    triggerPriceRecalculationInline(collectChildrenAges(roomNum), roomNum, true);
                }, (roomNum - 1) * 400);
            })(r);
        }
    } else {
        triggerPriceRecalculationInline(collectChildrenAges(1), 1, true);
    }
});
</script>
