{*
 * Novoton Booking Form - Guest Entry
 * v2.7.0-A72 - Added DOB future date validation (client + server-side)
 *}

{style src="css/addons/novoton_holidays/styles.css"}

{* Inline critical CSS for booking form - prevents theme override issues *}
<style type="text/css">
/* Novoton Reservation Form - Critical Styles v2.7.0-A30 */
.novoton-reservation-form { max-width: 900px; margin: 0 auto; padding: 0 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.novoton-reservation-header { background: linear-gradient(135deg, #003580, #0057b8) !important; color: #fff !important; padding: 25px !important; border-radius: 8px 8px 0 0 !important; position: relative; }
.novoton-reservation-header h1 { margin: 0 !important; font-size: 24px !important; font-weight: 600 !important; color: #ffffff !important; padding-right: 150px; }
.novoton-reservation-header .hotel-location { font-size: 14px !important; color: rgba(255,255,255,0.9) !important; margin-top: 5px !important; }
.novoton-reservation-header .availability-badge { position: absolute; top: 25px; right: 25px; background: #28a745 !important; padding: 8px 16px !important; border-radius: 20px !important; font-size: 14px !important; color: #fff !important; }
.novoton-reservation-body { background: #fff !important; border: 1px solid #ddd !important; border-top: none !important; border-radius: 0 0 8px 8px !important; padding: 0 !important; }
.booking-details-section { display: flex !important; flex-wrap: wrap !important; border-bottom: 1px solid #e0e0e0 !important; padding: 0 !important; }
.booking-image { flex: 0 0 200px !important; padding: 20px !important; }
.booking-image img { width: 100% !important; border-radius: 4px !important; }
.booking-info { flex: 1 !important; padding: 20px !important; display: grid !important; grid-template-columns: auto 1fr !important; gap: 8px 20px !important; align-items: start !important; }
.booking-info .info-label { font-weight: 600 !important; color: #003580 !important; font-size: 14px !important; }
.booking-info .info-value { color: #333 !important; font-size: 14px !important; }
.booking-info .info-value.highlight { color: #d32f2f !important; font-weight: 600 !important; }
.booking-price-box { flex: 0 0 180px !important; padding: 20px !important; text-align: right !important; border-left: 1px solid #e0e0e0 !important; display: flex; flex-direction: column; justify-content: center; }
.booking-price-box .price-total { font-size: 32px !important; font-weight: 700 !important; color: #003580 !important; line-height: 1.2; }
.booking-price-box .price-label { font-size: 14px !important; color: #666 !important; margin-bottom: 5px; }
.booking-price-box .price-currency { font-size: 16px !important; color: #666 !important; }
.guest-names-section { padding: 20px !important; border-bottom: 1px solid #e0e0e0 !important; }
.guest-names-section h3 { margin: 0 0 20px 0 !important; font-size: 18px !important; color: #333 !important; text-align: center !important; font-weight: 600 !important; }
.guest-entry { background: #f8f9fa !important; border-radius: 8px !important; padding: 15px 20px !important; margin-bottom: 15px !important; }
.guest-entry-adult { border-left: 4px solid #003580 !important; }
.guest-entry-child { border-left: 4px solid #ff9800 !important; }
.guest-entry-header { font-weight: 600 !important; color: #003580 !important; margin-bottom: 5px !important; font-size: 16px !important; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.guest-entry-header .holder-tag { background: #003580 !important; color: #fff !important; font-size: 11px !important; padding: 2px 8px !important; border-radius: 10px !important; font-weight: normal !important; }
.guest-entry-subheader { font-size: 13px !important; color: #666 !important; margin-bottom: 12px !important; }
/* Field row - Last Name and First Name on same line */
.field-row { display: flex !important; gap: 15px !important; align-items: flex-end !important; flex-wrap: wrap !important; }
.field-group { flex: 1 1 200px !important; min-width: 150px !important; }
.field-group label { display: block !important; font-size: 13px !important; color: #666 !important; margin-bottom: 4px !important; font-weight: 500 !important; }
.field-group label .required { color: #dc3545 !important; margin-left: 2px !important; }
.field-group input, .field-group select { width: 100% !important; padding: 10px 12px !important; border: 1px solid #ddd !important; border-radius: 4px !important; font-size: 14px !important; box-sizing: border-box !important; }
.field-group input:focus, .field-group select:focus { border-color: #003580 !important; outline: none !important; box-shadow: 0 0 0 2px rgba(0,53,128,0.1) !important; }
/* Form actions - better UX positioning */
.form-actions { padding: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important; background: #f8f9fa !important; border-radius: 0 0 8px 8px !important; gap: 15px !important; flex-wrap: wrap !important; }
.btn-back { color: #003580 !important; text-decoration: none !important; font-size: 14px !important; display: inline-flex !important; align-items: center !important; gap: 5px !important; padding: 10px 0 !important; order: 1; }
.btn-back:hover { text-decoration: underline !important; }
.btn-submit { background: #28a745 !important; color: #fff !important; border: none !important; padding: 14px 36px !important; font-size: 16px !important; font-weight: 600 !important; border-radius: 4px !important; cursor: pointer !important; order: 2; }
.btn-submit:hover { background: #218838 !important; }
@media (max-width: 768px) {
    .novoton-reservation-header h1 { padding-right: 0 !important; }
    .novoton-reservation-header .availability-badge { position: relative !important; top: auto !important; right: auto !important; display: inline-block !important; margin-top: 10px !important; }
    .booking-details-section { flex-direction: column !important; }
    .booking-image { flex: none !important; }
    .booking-price-box { flex: none !important; border-left: none !important; border-top: 1px solid #e0e0e0 !important; text-align: center !important; }
    .field-row { flex-direction: column !important; }
    .field-group { flex: 1 1 100% !important; min-width: 100% !important; }
    .form-actions { flex-direction: column-reverse !important; text-align: center !important; }
    .btn-back { order: 2; }
    .btn-submit { order: 1; width: 100% !important; }
}
</style>

{* Helper function to format room type with full name *}
{function name="format_room_type" room_id=""}
    {* Fix URL encoding and add + before trailing child count (e.g., "DBL 2 1" -> "DBL 2 +1") *}
    {$room_clean = $room_id|replace:'%2b':'+'|replace:'%2B':'+'}
    {* Handle patterns like "2 1" -> "2 +1" for extra bed/child *}
    {$room_clean = $room_clean|replace:' 2 1':' 2 +1'|replace:' 2 2':' 2 +2'|replace:' 3 1':' 3 +1'|replace:' 3 2':' 3 +2'|replace:' 4 1':' 4 +1'|replace:' 4 2':' 4 +2'}
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

<div class="novoton-reservation-form">
    <form action="{fn_url("novoton_booking.add_to_cart")}" method="post" id="novoton-booking-form">
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
        
        {* Header *}
        <div class="novoton-reservation-header">
            <span class="availability-badge">
                {if $booking_data.is_on_request}
                    {__("novoton_holidays.on_request")}
                {else}
                    ✓ {__("novoton_holidays.available")}
                {/if}
            </span>
            <h1>{$hotel_name|default:'Hotel'} {if $hotel_stars}{$hotel_stars}{/if}</h1>
            <div class="hotel-location"> {$hotel_city|default:'GOLDEN SANDS'}{if $hotel_region}, {$hotel_region}{/if}, {$hotel_country|default:'BULGARIA'}</div>
        </div>
        
        {* Body *}
        <div class="novoton-reservation-body">
            
            {* Booking Details *}
            <div class="booking-details-section">
                {if $hotel_image}
                <div class="booking-image">
                    <img src="{$hotel_image}" alt="{$hotel_name}">
                </div>
                {/if}
                
                <div class="booking-info">
                    <span class="info-label">{__("novoton_holidays.package")}:</span>
                    <span class="info-value">
                        {if $package_name && $package_name != $hotel_name}
                            {$package_name|replace:'%2b':'+'|replace:'%2B':'+'}
                        {elseif $booking_data.package_name}
                            {$booking_data.package_name|replace:'%2b':'+'|replace:'%2B':'+'}
                        {else}
                            {$hotel_name}
                        {/if}
                    </span>
                    
                    <span class="info-label">{__("novoton_holidays.check_in")}:</span>
                    <span class="info-value highlight">{$booking_data.check_in|date_format:$settings.Appearance.date_format}, {$booking_data.check_in|date_format:"%A"}</span>
                    
                    <span class="info-label">{__("novoton_holidays.check_out")}:</span>
                    <span class="info-value highlight">{$booking_data.check_out|date_format:$settings.Appearance.date_format}, {$booking_data.check_out|date_format:"%A"}</span>
                    
                    <span class="info-label">{__("novoton_holidays.stay")|default:"Cazare"}:</span>
                    <span class="info-value">{$booking_data.nights} {if $booking_data.nights == 1}{__("novoton_holidays.night")}{else}{__("novoton_holidays.nights")}{/if}</span>
                    
                    {* Multi-room type display *}
                    {if $booking_data.num_rooms > 1 && $booking_data.rooms_data}
                        <span class="info-label">{__("novoton_holidays.rooms")}:</span>
                        <span class="info-value">{$booking_data.num_rooms} {__("novoton_holidays.rooms")}</span>
                        
                        {foreach from=$booking_data.rooms_data item=room_info key=room_idx}
                            {$room_num = $room_idx + 1}
                            <span class="info-label" style="padding-left: 15px;">-> {__("novoton_holidays.room_number")} {$room_num}:</span>
                            <span class="info-value room-type-full" data-room-num="{$room_num}">
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
                                    ({$room_info.price|number_format:0} EUR)
                                {/if}
                            </span>
                        {/foreach}
                    {else}
                        <span class="info-label">{__("novoton_holidays.room_type")}:</span>
                        <span class="info-value room-type-full">
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
                        
                        <span class="info-label">{__("novoton_holidays.board")}:</span>
                        <span class="info-value">
                            {if $booking_data.board_id == 'AI' || $booking_data.board_id == 'ALL INCL' || $booking_data.board_id == 'ALLINC'}
                                {__("novoton_holidays.all_inclusive")}
                            {elseif $booking_data.board_id == 'UAI' || $booking_data.board_id == 'ULTRA ALL INCL' || $booking_data.board_id == 'ULTRA ALL INCLUSIVE'}
                                {__("novoton_holidays.ultra_all_inclusive")}
                            {elseif $booking_data.board_id == 'FB'}
                                {__("novoton_holidays.full_board")}
                            {elseif $booking_data.board_id == 'HB'}
                                {__("novoton_holidays.half_board")}
                            {elseif $booking_data.board_id == 'BB'}
                                {__("novoton_holidays.bed_breakfast")}
                            {elseif $booking_data.board_id == 'RO'}
                                {__("novoton_holidays.room_only")}
                            {else}
                                {$booking_data.board_id}
                            {/if}
                        </span>
                    {/if}
                </div>
                
                <div class="booking-price-box">
                    <div class="price-label">{__("novoton_holidays.total")}:</div>
                    <div class="price-total" id="novoton-total-price">{$booking_data.total_price|number_format:2}</div>
                    <div class="price-currency">EUR</div>
                </div>
            </div>
            
            {* Guest Names Section - Multi-Room Support with Split Fields *}
            <div class="guest-names-section">
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
                    <div class="room-section-header room-card" data-room-num="{$room_num}" style="background: #003580; color: #fff; padding: 12px 15px; margin: 20px -15px 15px; font-weight: 600;">
                        <span style="font-size: 16px;"> {__("novoton_holidays.room_number")} {$room_num}</span>
                        <span style="float: right; font-weight: normal; font-size: 14px;">
                            {$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0}, {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
                            <span class="room-price" style="margin-left: 10px; font-weight: 600;">{$room.price|default:0|number_format:0} EUR</span>
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
                            <div class="guest-entry-header">
                                {$guest_num}. {__("novoton_holidays.adult")}
                                {if $is_first_adult}
                                    <span class="holder-tag">{__("novoton_holidays.adult_holder")|regex_replace:"/^.*- /":""}
                                    </span>
                                {/if}
                                {if count($booking_data.rooms_data) > 1}
                                    <small style="color: #666; font-weight: normal;">({__("novoton_holidays.room_number")} {$room_num})</small>
                                {/if}
                            </div>
                            <div class="guest-entry-subheader">{__("novoton_holidays.regular_bed")}</div>
                            
                            <div class="field-row">
                                <div class="field-group">
                                    <label>{__("novoton_holidays.last_name")}<span class="required">*</span></label>
                                    <input type="text" 
                                           name="guests[room{$room_num}_adult_{$i}][last_name]" 
                                           required 
                                           value="{$prefilled_last_name}"
                                           placeholder="{__('novoton_holidays.last_name')}" />
                                </div>
                                <div class="field-group">
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
                                <div class="guest-entry-header">
                                    {$guest_num}. {__("novoton_holidays.child")} {$i}
                                    <span class="child-age-display" id="child_age_display_r{$room_num}_c{$i}">({$child_age_at_checkin} {if $child_age_at_checkin == 1}{__("novoton_holidays.age_label_singular")|default:"an"}{else}{__("novoton_holidays.age_label")|default:"ani"}{/if})</span>
                                    {if count($booking_data.rooms_data) > 1}
                                        <small style="color: #666; font-weight: normal;">- {__("novoton_holidays.room_number")} {$room_num}</small>
                                    {/if}
                                </div>
                                
                                {* DOB age info/warning message area *}
                                <div id="dob_info_r{$room_num}_c{$i}" class="dob-info-message" style="display: none; padding: 8px 12px; margin-bottom: 10px; border-radius: 4px; font-size: 13px;"></div>
                                
                                {* Row 1: Last Name + First Name (side by side on desktop, stacked on mobile) *}
                                <div class="field-row field-row-names">
                                    <div class="field-group">
                                        <label>{__("novoton_holidays.last_name")}<span class="required">*</span></label>
                                        <input type="text" 
                                               name="guests[room{$room_num}_child_{$i}][last_name]" 
                                               required 
                                               value="{$prefilled_child_last_name}"
                                               placeholder="{__('novoton_holidays.last_name')}" />
                                    </div>
                                    <div class="field-group">
                                        <label>{__("novoton_holidays.first_name")}<span class="required">*</span></label>
                                        <input type="text" 
                                               name="guests[room{$room_num}_child_{$i}][first_name]" 
                                               required 
                                               value="{$prefilled_child_first_name}"
                                               placeholder="{__('novoton_holidays.first_name')}" />
                                    </div>
                                </div>
                                
                                {* Row 2: Date of Birth (own row for better visibility) *}
                                <div class="field-row field-row-dob">
                                    <div class="field-group field-group-dob">
                                        <label>{__("novoton_holidays.date_of_birth")} <span style="font-weight: normal; color: #666;">(ex: 27/05/2020)</span><span class="required">*</span></label>
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
                                               onkeydown="handleDobKeydown(event)"
                                               oninput="applyDobMask(this)"
                                               onblur="validateAndCheckAge('r{$room_num}_c{$i}', {$child_age_at_checkin})" />
                                    </div>
                                    {* Hidden fields *}
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][original_age]" value="{$child_age_at_checkin}" />
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][calculated_age]" id="child_calc_age_r{$room_num}_c{$i}" value="" />
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][type]" id="child_type_r{$room_num}_c{$i}" value="child" />
                                    <input type="hidden" name="guests[room{$room_num}_child_{$i}][room]" value="{$room_num}" />
                                </div>
                                <div id="dob_error_r{$room_num}_c{$i}" class="dob-validation-error" style="display: none; color: #dc3545; font-size: 12px; margin-top: 5px;"></div>
                            </div>
                        {/for}
                    {/if}
                    </div>{* Close room-guest-section *}
                {/foreach}
            </div>
            
            {* Important Info *}
            {if $payment_terms || $cancellation_terms}
            <div class="important-info-section">
                <h3>{__("novoton_holidays.important_info")}</h3>
                
                {if $payment_terms}
                <div class="info-block">
                    <h4>{__("novoton_holidays.terms_of_payment")}</h4>
                    <p>{$payment_terms|nl2br}</p>
                </div>
                {/if}
                
                {if $cancellation_terms}
                <div class="info-block">
                    <h4>{__("novoton_holidays.cancellation_terms")}</h4>
                    <p>{$cancellation_terms|nl2br}</p>
                </div>
                {/if}
            </div>
            {/if}
            
            {* Form Actions *}
            <div class="form-actions">
                                {* Build rooms_data JSON for URL *}
                {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|json_encode|escape:'url'}
                {elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}
                    {$rooms_data_url = $booking_data.rooms_data|escape:'url'}
                {else}
                    {$rooms_data_url = ''}
                {/if}
                <a href="{fn_url("novoton_booking.search?hotel_id=`$booking_data.hotel_id`&product_id=`$product_id`&check_in=`$booking_data.check_in`&check_out=`$booking_data.check_out`&nights=`$booking_data.nights`&adults=`$booking_data.adults`&children=`$booking_data.children`&children_ages=`$booking_data.children_ages`&rooms=`$booking_data.num_rooms|default:1`&rooms_data=`$rooms_data_url`")}" class="btn-back">
                    <- {__("novoton_holidays.back_to_results")}
                </a>
                <button type="submit" class="btn-submit">
                    {__("novoton_holidays.add_to_cart")}
                </button>
            </div>
        </div>
    </form>
</div>

{* A73: Include DOB validation script with price recalculation *}
{* A74e: Include external booking form validation JS *}
{script src="js/addons/novoton_holidays/booking-form-validation.js"}

<script>
// A74e: Translation strings for JavaScript (used by external module)
window.NovotonTranslations = window.NovotonTranslations || {};
window.NovotonTranslations.priceIncreased = '{__("novoton_holidays.price_increased")|default:"Price increased"|escape:"javascript"}';
window.NovotonTranslations.priceDecreased = '{__("novoton_holidays.price_decreased")|default:"Price decreased"|escape:"javascript"}';
window.NovotonTranslations.priceRecalculating = '{__("novoton_holidays.price_recalculating")|default:"Recalculating price..."|escape:"javascript"}';
window.NovotonTranslations.priceUpdated = '{__("novoton_holidays.price_updated")|default:"Pretul a fost actualizat:"|escape:"javascript"}';
window.NovotonTranslations.ageLabel = '{__("novoton_holidays.age_label")|default:"ani"|escape:"javascript"}';
window.NovotonTranslations.ageLabelSingular = '{__("novoton_holidays.age_label_singular")|default:"an"|escape:"javascript"}';
window.NovotonTranslations.childMustBeUnder18 = '{__("novoton_holidays.child_must_be_under_18")|default:"Child must be under 18"|escape:"javascript"}';
window.NovotonTranslations.dobCannotBeFuture = '{__("novoton_holidays.dob_cannot_be_future")|default:"Date of birth cannot be in the future"|escape:"javascript"}';
window.NovotonTranslations.roomChangedTitle = '{__("novoton_holidays.room_changed_title")|default:"Camera s-a modificat"|escape:"javascript"}';
window.NovotonTranslations.roomChangedDueToAge = '{__("novoton_holidays.room_changed_due_to_age")|default:"Camera selectata nu este disponibila pentru varsta copilului introdusa."|escape:"javascript"}';
window.NovotonTranslations.originalRoom = '{__("novoton_holidays.original_room")|default:"Camera selectata"|escape:"javascript"}';
window.NovotonTranslations.newRoom = '{__("novoton_holidays.new_room")|default:"Camera noua"|escape:"javascript"}';
window.NovotonTranslations.priceChange = '{__("novoton_holidays.price_change")|default:"Modificare pret"|escape:"javascript"}';
window.NovotonTranslations.goBackToSearch = '{__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"|escape:"javascript"}';
window.NovotonTranslations.continueWithNewRoom = '{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"|escape:"javascript"}';
window.NovotonTranslations.roomUpdated = '{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"|escape:"javascript"}';
window.NovotonTranslations.invalidDobFormat = '{__("novoton_holidays.invalid_dob_format")|default:"Format invalid. Folositi ZZ/LL/AAAA"|escape:"javascript"}';
window.NovotonTranslations.invalidDay = '{__("novoton_holidays.invalid_day")|default:"Ziua trebuie sa fie intre 1 si 31"|escape:"javascript"}';
window.NovotonTranslations.invalidMonth = '{__("novoton_holidays.invalid_month")|default:"Luna trebuie sa fie intre 1 si 12"|escape:"javascript"}';
window.NovotonTranslations.invalidYear = '{__("novoton_holidays.invalid_year")|default:"An invalid"|escape:"javascript"}';
window.NovotonTranslations.futureDate = '{__("novoton_holidays.future_date")|default:"Data nu poate fi in viitor"|escape:"javascript"}';
window.NovotonTranslations.notChild = '{__("novoton_holidays.not_child")|default:"La check-in, copilul va avea"|escape:"javascript"}';
window.NovotonTranslations.yearsOld = '{__("novoton_holidays.years_old")|default:"ani"|escape:"javascript"}';
window.NovotonTranslations.mustBeUnder18 = '{__("novoton_holidays.must_be_under_18")|default:"Trebuie sa fie sub 18 ani."|escape:"javascript"}';

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
    
    // Check if occupancy is exceeded
    var occupancyBlock = document.getElementById('occupancy-block-message');
    if (occupancyBlock && occupancyBlock.style.display !== 'none') {
        e.preventDefault();
        alert('{__("novoton_holidays.occupancy_exceeded_alert")|default:"Camera selectata nu permite acest numar de adulti. Va rugam modificati selectia."|escape:"javascript"}');
        return;
    }
    
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
    roomsData: {if $booking_data.rooms_data && is_array($booking_data.rooms_data)}{$booking_data.rooms_data|json_encode nofilter}{elseif $booking_data.rooms_data && is_string($booking_data.rooms_data)}{$booking_data.rooms_data nofilter}{else}[]{/if}
{rdelim};

// A74e: These functions are defined in booking-form-validation.js
// Wrapper just ensures they exist before calling
function handleDobKeydownLocal(e) {
    if (typeof window.handleDobKeydown === 'function') {
        window.handleDobKeydown(e);
    }
}

function applyDobMaskLocal(input) {
    if (typeof window.applyDobMask === 'function') {
        window.applyDobMask(input);
    }
}

// Main validation function for DOB fields
function validateAndCheckAge(id, originalAge) {
    var dobInput = document.getElementById('child_dob_' + id);
    var errorDiv = document.getElementById('dob_error_' + id);
    var infoDiv = document.getElementById('dob_info_' + id);
    var calcAgeInput = document.getElementById('child_calc_age_' + id);
    var ageDisplay = document.getElementById('child_age_display_' + id);
    
    if (!dobInput) return;
    
    var dobValue = dobInput.value;
    
    // Clear previous states
    dobInput.style.borderColor = '';
    dobInput.style.backgroundColor = '';
    if (errorDiv) { errorDiv.style.display = 'none'; errorDiv.textContent = ''; }
    if (infoDiv) { infoDiv.style.display = 'none'; infoDiv.textContent = ''; }
    
    // Skip if empty or incomplete
    if (!dobValue || dobValue.length < 10) return;
    
    // Parse DOB
    var parsed = parseDobMasked(dobValue);
    if (!parsed) {
        showDobError(dobInput, errorDiv, 'Format invalid');
        return;
    }
    
    // Validate ranges
    if (parsed.day < 1 || parsed.day > 31) {
        showDobError(dobInput, errorDiv, 'Ziua invalida (1-31)');
        return;
    }
    if (parsed.month < 1 || parsed.month > 12) {
        showDobError(dobInput, errorDiv, 'Luna invalida (1-12)');
        return;
    }
    var currentYear = new Date().getFullYear();
    if (parsed.year < 1925 || parsed.year > currentYear) {
        showDobError(dobInput, errorDiv, 'Anul invalid');
        return;
    }
    
    // Check if DOB is in the future
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var birthDate = new Date(parsed.year, parsed.month - 1, parsed.day);
    if (birthDate > today) {
        showDobError(dobInput, errorDiv, 'Data nasterii nu poate fi in viitor');
        return;
    }
    
    // Calculate age at check-in
    var checkInDate = new Date(window.bookingData.checkIn);
    var calculatedAge = calculateAgeAtDate(birthDate, checkInDate);
    
    // Update hidden field
    if (calcAgeInput) calcAgeInput.value = calculatedAge;
    
    // Update age display - use translation with singular/plural (Romanian: "1 an", "2 ani")
    if (ageDisplay) {
        var ageLabel;
        if (calculatedAge === 1) {
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabelSingular ? window.NovotonTranslations.ageLabelSingular : 'an';
        } else {
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabel ? window.NovotonTranslations.ageLabel : 'ani';
        }
        ageDisplay.textContent = '(' + calculatedAge + ' ' + ageLabel + ')';
    }
    
    // Check if child (under 18)
    if (calculatedAge >= 18) {
        var notChildMsg = window.NovotonTranslations.notChild || 'La check-in, copilul va avea';
        var yearsLabel = window.NovotonTranslations.ageLabel || 'ani';
        var mustBeUnder18 = window.NovotonTranslations.mustBeUnder18 || 'Trebuie sa fie sub 18 ani.';
        showDobError(dobInput, errorDiv, notChildMsg + ' ' + calculatedAge + ' ' + yearsLabel + '. ' + mustBeUnder18);
        return;
    }
    
    // Valid - show green
    dobInput.style.borderColor = '#28a745';
    dobInput.style.backgroundColor = '#f0fff0';
    
    // Extract room number from id (format: rX_cY where X=room, Y=child)
    var roomMatch = id.match(/r(\d+)_c\d+/);
    var roomNum = roomMatch ? parseInt(roomMatch[1], 10) : 1;
    
    // Trigger price recalculation for this specific room
    collectAndRecalculate(roomNum);
}

function showDobError(input, errorDiv, message) {
    input.style.borderColor = '#dc3545';
    input.style.backgroundColor = '#fff5f5';
    if (errorDiv) {
        errorDiv.innerHTML = ' ' + message;
        errorDiv.style.display = 'block';
    }
}

function collectAndRecalculate(roomNum) {
    roomNum = roomNum || 1;
    
    // For multi-room: collect only this room's children ages
    // For single room: collect all children ages
    var childrenAges = [];
    var isMultiRoom = window.bookingData.numRooms > 1;
    
    if (isMultiRoom) {
        // Collect ages only for the specific room (format: child_calc_age_rX_cY)
        document.querySelectorAll('[id^="child_calc_age_r' + roomNum + '_c"]').forEach(function(input) {
            var age = parseInt(input.value, 10);
            if (!isNaN(age) && age >= 0 && age < 18) {
                childrenAges.push(age);
            }
        });
        console.log('[Novoton] Collected children ages for room ' + roomNum + ':', childrenAges);
    } else {
        // Single room: collect all
        document.querySelectorAll('[id^="child_calc_age_"]').forEach(function(input) {
            var age = parseInt(input.value, 10);
            if (!isNaN(age) && age >= 0 && age < 18) {
                childrenAges.push(age);
            }
        });
        console.log('[Novoton] Collected children ages:', childrenAges);
    }
    
    if (childrenAges.length > 0) {
        triggerPriceRecalculationInline(childrenAges, roomNum);
    }
}

// A74e: Inline price recalculation to avoid external JS loading issues
// A74y: Updated to handle per-room recalculation for multi-room bookings
function triggerPriceRecalculationInline(childrenAges, roomNum) {
    roomNum = roomNum || 1;
    console.log('[Novoton] triggerPriceRecalculationInline called for room ' + roomNum + ' with ages:', childrenAges);
    
    if (!window.bookingData) {
        console.error('[Novoton] bookingData not defined');
        return;
    }
    
    var isMultiRoom = window.bookingData.numRooms > 1 && window.bookingData.roomsData && window.bookingData.roomsData.length > 0;
    var roomIdx = roomNum - 1;
    
    // Get room-specific data for multi-room, or use single room data
    var roomData = {};
    if (isMultiRoom && window.bookingData.roomsData[roomIdx]) {
        roomData = window.bookingData.roomsData[roomIdx];
        console.log('[Novoton] Using room-specific data for room ' + roomNum + ':', roomData);
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
    
    console.log('[Novoton] AJAX request:', requestData);
    
    // A74k: Use root-level AJAX handler (app/ is blocked by CS-Cart)
    var ajaxUrl = 'novoton_ajax_price.php';
    console.log('[Novoton] AJAX URL:', ajaxUrl);
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(requestData)
    })
    .then(function(response) { 
        console.log('[Novoton] Response status:', response.status);
        console.log('[Novoton] Response headers:', response.headers.get('content-type'));
        return response.text(); // Get raw text first
    })
    .then(function(text) {
        console.log('[Novoton] Raw response:', text.substring(0, 500));
        // Try to parse JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('[Novoton] JSON parse error:', e.message);
            console.error('[Novoton] Response was:', text);
            throw e;
        }
    })
    .then(function(data) {
        console.log('[Novoton] AJAX response:', data);
        
        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';
        
        if (data.success) {
            var newPrice = parseFloat(data.new_price) || 0;
            console.log('[Novoton] New price for room ' + roomNum + ':', newPrice);
            
            if (isMultiRoom && window.bookingData.roomsData && window.bookingData.roomsData[roomIdx]) {
                // Multi-room: Update only this room's price
                var oldRoomPrice = parseFloat(window.bookingData.roomsData[roomIdx].price) || 0;
                window.bookingData.roomsData[roomIdx].price = newPrice;
                
                // Update the room card price display
                var roomPriceEl = document.querySelector('.room-card[data-room-num="' + roomNum + '"] .room-price');
                if (roomPriceEl) {
                    roomPriceEl.textContent = newPrice.toFixed(0) + ' EUR';
                }
                
                // Recalculate total from all rooms
                var totalPrice = 0;
                for (var i = 0; i < window.bookingData.roomsData.length; i++) {
                    totalPrice += parseFloat(window.bookingData.roomsData[i].price) || 0;
                }
                
                console.log('[Novoton] New total price:', totalPrice);
                
                // Update total price display
                document.querySelectorAll('.price-total').forEach(function(el) {
                    el.textContent = totalPrice.toFixed(2);
                });
                
                // A76i: Update hidden total_price input for form submission
                var hiddenPriceInput = document.querySelector('input[name="total_price"]');
                if (hiddenPriceInput) {
                    hiddenPriceInput.value = totalPrice.toFixed(2);
                    console.log('[Novoton] Updated hidden total_price input to:', totalPrice.toFixed(2));
                }
                
                // Update bookingData total
                var priceDiff = totalPrice - window.bookingData.currentPrice;
                window.bookingData.currentPrice = totalPrice;
                
                // Show price change notification
                if (Math.abs(priceDiff) > 0.01) {
                    showPriceNotification(priceDiff);
                }
            } else {
                // Single room: Update total price
                document.querySelectorAll('.price-total').forEach(function(el) {
                    el.textContent = newPrice.toFixed(2);
                });
                
                // A76i: Update hidden total_price input for form submission
                var hiddenPriceInput = document.querySelector('input[name="total_price"]');
                if (hiddenPriceInput) {
                    hiddenPriceInput.value = newPrice.toFixed(2);
                    console.log('[Novoton] Updated hidden total_price input to:', newPrice.toFixed(2));
                }
                
                // Show price change notification
                if (data.price_difference && data.price_difference !== 0) {
                    showPriceNotification(data.price_difference);
                }
                
                // Update bookingData
                window.bookingData.currentPrice = newPrice;
            }
            
            // Show room change warning if needed
            if (data.room_changed) {
                showRoomChangeModal(data);
            }
            
            // Hide any previous notice
            var notice = document.getElementById('price-recalc-notice');
            if (notice) notice.style.display = 'none';
            
        } else {
            console.log('[Novoton] Recalculation failed:', data.message);
            showInfoNotice(data.message || '{__("novoton_holidays.price_verify_on_submit")|default:"Pretul va fi verificat la finalizare."}');
        }
    })
    .catch(function(error) {
        console.error('[Novoton] AJAX error:', error);
        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';
        showInfoNotice('{__("novoton_holidays.price_verify_on_submit")|default:"Pretul va fi verificat la finalizare."}');
    });
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
    notif.innerHTML = '{__("novoton_holidays.price_updated_child_age")|default:"Pre\u021bul a fost actualizat \u00een func\u021bie de v\u00e2rsta copilului"}: <strong style="color:' + changeColor + '">' + changeText + ' EUR</strong>';
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
    console.log('[Novoton] Showing room change modal:', data);
    
    var existing = document.getElementById('room-change-warning');
    if (existing) existing.remove();
    
    var priceDiff = parseFloat(data.price_difference) || 0;
    var newPrice = parseFloat(data.new_price) || 0;
    var originalPrice = parseFloat(data.original_price) || 0;
    
    var priceDiffText = '', priceDiffStyle = '';
    if (priceDiff > 0) {
        priceDiffText = '+' + priceDiff.toFixed(2) + ' EUR';
        priceDiffStyle = 'color:#dc3545;font-weight:bold;';
    } else if (priceDiff < 0) {
        priceDiffText = priceDiff.toFixed(2) + ' EUR';
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
                    '<div style="font-weight:600;color:#856404;text-decoration:line-through;">' + (data.original_room || '') + '</div>' +
                '</div>' +
                '<div style="font-size:24px;color:#856404;">-></div>' +
                '<div style="text-align:center;">' +
                    '<div style="font-size:11px;color:#666;text-transform:uppercase;">{__("novoton_holidays.new_room")|default:"Camera noua"}</div>' +
                    '<div style="font-weight:600;color:#155724;">' + (data.new_room || '') + '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div style="background:#f8f9fa;border-radius:8px;padding:15px;margin-bottom:20px;text-align:center;">' +
            '<div style="font-size:12px;color:#666;margin-bottom:5px;">{__("novoton_holidays.price_change")|default:"Modificare pret"}</div>' +
            '<div style="font-size:20px;">' +
                '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' EUR</span> ' +
                '<span style="' + priceDiffStyle + '">(' + priceDiffText + ')</span> ' +
                '<span style="font-weight:bold;color:#003580;">' + newPrice.toFixed(2) + ' EUR</span>' +
            '</div>' +
        '</div>' +
        '<div style="display:flex;gap:10px;justify-content:center;">' +
            '<button type="button" onclick="closeRoomModal();window.history.back();" style="padding:12px 20px;border:2px solid #003580;background:#fff;color:#003580;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;"><- {__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"}</button>' +
            '<button type="button" onclick="acceptRoomChangeInline()" style="padding:12px 20px;border:none;background:#003580;color:#fff;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"} -></button>' +
        '</div>' +
        '</div></div>';
    
    window._roomChangeData = data;
    document.body.insertAdjacentHTML('beforeend', html);
}

function closeRoomModal() {
    var modal = document.getElementById('room-change-warning');
    if (modal) modal.remove();
}

function acceptRoomChangeInline() {
    var data = window._roomChangeData || {};
    closeRoomModal();
    
    // Format room name for display (add "Camera Dubla" prefix if needed)
    var displayRoom = data.new_room || '';
    if (displayRoom && !displayRoom.toLowerCase().includes('camer')) {
        displayRoom = 'Camera Dubla (' + displayRoom + ')';
    }
    
    // Build display text with board and price if available
    var fullDisplayText = displayRoom;
    if (data.board_name) {
        fullDisplayText += ' - ' + data.board_name;
    }
    if (data.new_price) {
        fullDisplayText += ' (' + parseFloat(data.new_price).toFixed(0) + ' EUR)';
    }
    
    // Check if this is for a specific room in multi-room booking
    var roomNum = data.room_num || data.roomNum || null;
    
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
    notif.innerHTML = '✓ <strong>{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"}</strong> ' + roomLabel + (data.new_room || '') + ' - ' + (parseFloat(data.new_price) || 0).toFixed(2) + ' EUR';
    
    var section = document.querySelector('.guest-names-section h3');
    if (section && section.parentNode) {
        section.parentNode.insertBefore(notif, section.nextSibling);
    }
    
    setTimeout(function() { if (notif.parentNode) notif.remove(); }, 10000);
}
</script>
