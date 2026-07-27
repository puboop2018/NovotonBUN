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
           header): shared travel_core hotel-identity component (name links to
           the product page in a NEW tab so an in-progress form is never lost)
           plus the inline availability pill. The location line sits after the
           pill in the DOM but renders below it (flex order in
           booking-pages.css). Data: $travel_hotel_header (HotelHeaderViewModel,
           assigned by the booking_form controller — its location line carries
           the city/region/country fallback). *}
        <div class="travel-reservation-header">
            {include file="addons/travel_core/components/hotel_header.tpl" hh_new_tab=true}
            <span class="travel-hero-badge" id="availability-badge">
                {if $booking_data.is_on_request}
                    {__("novoton_holidays.on_request")}
                {else}
                    ✓ {__("novoton_holidays.available")}
                {/if}
            </span>
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
                
                {* Booking-wide sequential guest numbering ("3. Adult") — the
                   shared room body offsets its labels by the guests rendered
                   in earlier rooms. *}
                {$guest_seq = 0}
                {capture assign="nvt_adult_sublabel"}{__("novoton_holidays.regular_bed")}{/capture}

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
                    
                    {* Shared guest cards (travel_core room body) — novoton
                       passes its provider machinery: fixed adult age for the
                       pricing API, the DOB age-recheck that re-prices,
                       booking-wide sequential labels and the bed-type
                       sublabel. Name/DOB prefill comes from the guest_prefill
                       map built by edit_booking.php. *}
                    {include file="addons/travel_core/components/booking_guest_room_body.tpl"
                        gb_room=$room
                        gb_room_num=$room_num
                        gb_room_idx=$room_idx
                        gb_label_prefix="travel_core"
                        gb_prefill=$guest_prefill|default:[]
                        gb_show_adult_dob=false
                        gb_child_dob_required=true
                        gb_adult_age_value="30"
                        gb_child_dob_onblur="validateAndCheckAge"
                        gb_seq_offset=$guest_seq
                        gb_adult_sublabel=$nvt_adult_sublabel}
                    {$_room_guests = $room.adults|default:1}
                    {$_room_guests = $_room_guests + $room.children|default:0}
                    {$guest_seq = $guest_seq + $_room_guests}
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
// Smarty-fed config for the booking-form module (js/addons/novoton_holidays/
// booking-form.js) — data + translated labels only; behavior lives in the
// module, which vitest imports directly.
// Room limits from hotel API
var roomLimits = {$booking_data.current_room_limits|default:[]|json_encode nofilter};

// Booking data for price recalculation (used by the module + React engine)
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
    showCalendarPrices: {if $show_calendar_prices == 'Y'}true{else}false{/if},
    ajaxRecalculateUrl: '{"novoton_booking.ajax_recalculate_price"|fn_url}'
{rdelim};

// Translated labels for the module's t() lookup (raw "_key" values are
// treated as missing there, so the baked-in fallbacks still guard unseeded
// stores).
window.NovotonBookingI18n = {ldelim}
    fillAllFields: '{__("novoton_holidays.fill_all_fields")|escape:"javascript"}',
    dobValidationError: '{__("novoton_holidays.dob_validation_error")|default:"Verificati datele de nastere introduse."|escape:"javascript"}',
    priceVerifiedAtCheckout: '{__("novoton_holidays.price_verified_at_checkout")|default:"Prețul va fi verificat la finalizare"|escape:"javascript"}',
    priceMustBeVerified: '{__("novoton_holidays.price_must_be_verified")|default:"Prețul trebuie verificat înainte de a continua"|escape:"javascript"}',
    unavailableForChildAge: '{__("novoton_holidays.unavailable_for_child_age")|default:"Indisponibil"|escape:"javascript"}',
    unavailableForChildAgeSub: '{__("novoton_holidays.unavailable_for_child_age_sub")|default:"pentru vârsta copilului"|escape:"javascript"}',
    available: '{__("novoton_holidays.available")|default:"Disponibil"|escape:"javascript"}',
    priceUpdatedChildAge: '{__("novoton_holidays.price_updated_child_age")|default:"Prețul a fost actualizat în funcție de vârsta copilului"|escape:"javascript"}',
    roomChangedTitle: '{__("novoton_holidays.room_changed_title")|default:"Camera s-a modificat"|escape:"javascript"}',
    roomChangedDueToAge: '{__("novoton_holidays.room_changed_due_to_age")|default:"Camera selectata nu este disponibila pentru varsta copilului introdusa."|escape:"javascript"}',
    originalRoom: '{__("novoton_holidays.original_room")|default:"Camera selectata"|escape:"javascript"}',
    newRoom: '{__("novoton_holidays.new_room")|default:"Camera noua"|escape:"javascript"}',
    priceChange: '{__("novoton_holidays.price_change")|default:"Modificare pret"|escape:"javascript"}',
    goBackToSearch: '{__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"|escape:"javascript"}',
    continueWithNewRoom: '{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"|escape:"javascript"}',
    roomTypeDouble: '{__("novoton_holidays.room_type_double")|default:"Double Room"|escape:"javascript"}',
    roomNumber: '{__("novoton_holidays.room_number")|default:"Camera"|escape:"javascript"}',
    roomUpdated: '{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"|escape:"javascript"}'
{rdelim};
</script>
{script src="js/addons/novoton_holidays/booking-form.js"}
