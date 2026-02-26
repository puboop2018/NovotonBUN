{*
 * Novoton Booking Search Results - v2.7.0-A74
 * Fixes:
 * - A67: Fixed desktop/mobile both showing on desktop
 * - A67: Added DOB validation (cannot be in future)
 * - A73n: Added inline grid styles for desktop table layout
 * - A73o: Multiple CSS loading methods for reliability
 * - A74: Replaced hardcoded modal text ("Note:", "Additional Information:",
 *         "Important:") with translation keys for i18n support
 * - A74: Replaced hardcoded "Early Booking" badge text with translation key
 * - A74: Added inline comments for modal content sections
 * Hybrid styling: inline for critical layout, CSS classes for enhancement
 *}

{* Method 1: CS-Cart standard style tag *}
{style src="css/addons/novoton_holidays/styles.css"}

{* Method 2: Direct link tag as fallback (uses current theme's CSS directory) *}
<link rel="stylesheet" type="text/css" href="{$config.current_location}/design/themes/{$runtime.layout.theme_name|default:'responsive'}/css/addons/novoton_holidays/styles.css?v={$smarty.const.PRODUCT_VERSION}" />

{* Method 3: Critical inline styles that ALWAYS work *}
<style>
/* A73: Critical Desktop/Mobile Switching - Cannot be overridden */
/* Desktop screens (769px and up) - Show TABLE layout */
@media screen and (min-width: 769px) {
    .novoton-mobile-only,
    .novoton-mobile-only.novoton-room-card,
    div.novoton-mobile-only,
    div.novoton-mobile-only.novoton-room-card {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        position: absolute !important;
        left: -9999px !important;
    }
    .novoton-desktop-only,
    .novoton-table-header.novoton-desktop-only,
    .result-row.novoton-desktop-only,
    div.novoton-desktop-only,
    div.novoton-table-header,
    div.result-row.novoton-desktop-only {
        display: grid !important;
        visibility: visible !important;
        opacity: 1 !important;
        position: relative !important;
        left: auto !important;
    }
    .novoton-table-header,
    .novoton-table-header.novoton-desktop-only {
        grid-template-columns: 2fr 2fr 1fr 200px !important;
        background: #f8f9fa !important;
        color: #003580 !important;
        border-bottom: 2px solid #003580 !important;
    }
    .result-row.novoton-desktop-only {
        grid-template-columns: 2fr 2fr 1fr 200px !important;
        border-bottom: 1px solid #e0e0e0 !important;
        background: #fff !important;
    }
    .result-row.novoton-desktop-only.on-request {
        background: #fff8e1 !important;
    }
}

/* Mobile screens (768px and down) - Show CARD layout */
@media screen and (max-width: 768px) {
    .novoton-desktop-only,
    .novoton-table-header.novoton-desktop-only,
    .result-row.novoton-desktop-only,
    div.novoton-desktop-only,
    div.novoton-table-header,
    div.result-row.novoton-desktop-only {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        position: absolute !important;
        left: -9999px !important;
    }
    .novoton-mobile-only,
    .novoton-mobile-only.novoton-room-card,
    div.novoton-mobile-only,
    div.novoton-mobile-only.novoton-room-card {
        display: block !important;
        visibility: visible !important;
        height: auto !important;
        overflow: visible !important;
        opacity: 1 !important;
        position: relative !important;
        left: auto !important;
    }
}
</style>

<div class="novoton-search-results-page" style="padding: 0 10px;">
    
    {* Debug Output *}
    {if $novoton_debug}
    <div class="novoton-debug-panel" style="background: #f8f8f8; border: 2px solid #e74c3c; padding: 15px; margin-bottom: 20px; font-family: monospace; font-size: 12px; white-space: pre-wrap; max-height: 500px; overflow-y: auto; border-radius: 8px;">
        <h4 style="color: #e74c3c; margin-top: 0;">[T] DEBUG MODE</h4>
        {foreach from=$novoton_debug item=line}
{$line|default:''}
{/foreach}
    </div>
    {/if}
    
    {* ===== BOOKING FORM - React Component ===== *}
    <div class="novoton-search-form-wrapper" style="margin-bottom: 20px;">
        <div id="novoton-search-form-root" 
             data-novoton-booking
             data-hotel-id="{$novoton_params.hotel_id|default:''}" 
             data-product-id="{$novoton_params.product_id|default:''}"
             data-check-in="{$novoton_params.check_in|default:''}"
             data-check-out="{$novoton_params.check_out|default:''}"
             data-adults="{$novoton_params.adults|default:2}"
             data-children="{$novoton_params.children_count|default:0}"
             data-children-ages="{$novoton_params.children_ages|default:''}"
             data-rooms="{$novoton_params.num_rooms|default:1}"
             data-rooms-data='{$novoton_params.rooms_data_json|default:"[]"|escape:"html"}'
             data-mode="search"
             data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}"
             >
        </div>
    </div>
    
    {if $novoton_results && $novoton_results|@count > 0}
        
        {* ===== HOTEL HEADER - A76g: White background ===== *}
        <div class="novoton-hotel-header" style="background: #fff; color: #003580; padding: 20px; border-radius: 8px 8px 0 0; border: 1px solid #e0e0e0; border-bottom: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2 style="margin: 0; font-size: 22px; font-weight: 600; color: #003580;">
                        {$hotel_name|default:'Hotel'} {$hotel_stars|default:'****'}
                    </h2>
                    <p style="margin: 5px 0 0; font-size: 14px; color: #666;">
                         {$hotel_city|default:''}{if $hotel_region}, {$hotel_region}{/if}{if $hotel_country}, {$hotel_country}{/if}
                    </p>
                    {if $hotel_season_from && $hotel_season_to}
                    <p style="margin: 4px 0 0; font-size: 13px; color: #0071c2;">
                        {__("novoton_holidays.accommodation_period")|default:"This hotel offers accommodation from"} {$hotel_season_from|date_format:"%d %b"} {__("novoton_holidays.to")|default:"to"} {$hotel_season_to|date_format:"%d %b %Y"}
                    </p>
                    {/if}
                </div>
                <div>
                    {if $novoton_results|@count > 0}
                    {* Calculate total quota from all results *}
                    {$total_quota = 0}
                    {foreach from=$novoton_results item=r}
                        {if $r.rooms_available && $r.rooms_available > 0}
                            {$total_quota = $total_quota + $r.rooms_available}
                        {/if}
                    {/foreach}
                    {$badge_rooms_count = ($total_quota > 0) ? $total_quota : $novoton_results|@count}
                    {$badge_offers_count = $novoton_results|@count}
                    <span id="novoton-availability-badge" data-rooms-count="{$badge_rooms_count}" data-offers-count="{$badge_offers_count}" style="background: #28a745; color: #fff; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600;">
                        ✓ {__("novoton_holidays.available")}: {$badge_rooms_count} {if $badge_rooms_count == 1}{__("novoton_holidays.room")|default:"room"}{else}{__("novoton_holidays.rooms")|default:"rooms"}{/if}, {$badge_offers_count} {if $badge_offers_count == 1}{__("novoton_holidays.offer")|default:"offer"}{else}{__("novoton_holidays.offers")|default:"offers"}{/if}
                    </span>
                    {/if}
                </div>
            </div>
        </div>
        
        {* ===== EARLY BOOKING BANNER - A74q: Cleaner style ===== *}
        {if $active_early_booking || ($early_booking_range && $early_booking_range.max > 0)}
        <div style="background: #ff6b35; color: #fff; padding: 10px 20px; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 18px;"></span>
            <div style="flex: 1;">
                <span style="font-weight: 700; font-size: 15px;">
                    {if $early_booking_range && $early_booking_range.max > 0}
                        {__("novoton_holidays.early_booking")}
                        {if $early_booking_range.min == $early_booking_range.max}
                            -{$early_booking_range.max|string_format:"%.0f"}%
                        {else}
                            -{$early_booking_range.min|string_format:"%.0f"}% to -{$early_booking_range.max|string_format:"%.0f"}%
                        {/if}
                    {elseif $active_early_booking}
                        -{$active_early_booking.reduction|floatval}% {__("novoton_holidays.early_booking")}
                    {/if}
                </span>
            </div>
        </div>
        {/if}
        
        {* Store dates for URLs *}
        {$check_in_date = $novoton_params.check_in}
        {$check_out_date = $novoton_params.check_out}
        
        {* ===== MULTI-ROOM SELECTION MODE ===== *}
        {if $novoton_params.num_rooms > 1 && $is_multi_room_search}
        
            {* A73p: First check if ALL rooms have available options *}
            {$all_rooms_have_options = true}
            {$rooms_without_options = []}
            {foreach from=$novoton_params.rooms_data item=room key=room_idx}
                {$room_num = $room_idx + 1}
                {assign var="room_key" value=$room_num}
                {if isset($all_room_results.$room_key) && $all_room_results.$room_key|@count > 0}
                    {* Room has options - OK *}
                {else}
                    {$all_rooms_have_options = false}
                    {$rooms_without_options[] = ["num" => $room_num, "adults" => $room.adults, "children" => $room.children|default:0]}
                {/if}
            {/foreach}
            
            {* If any room has no options, show error instead of selection grid *}
            {if !$all_rooms_have_options}
                <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; overflow: hidden; padding: 30px;">
                    <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                        <div style="font-size: 48px; margin-bottom: 15px;"></div>
                        <h3 style="color: #dc3545; margin: 0 0 15px; font-size: 20px;">
                            {__("novoton_holidays.configuration_not_available")|default:"This room configuration is not available"}
                        </h3>
                        <p style="color: #666; margin: 0 0 20px; font-size: 15px;">
                            {__("novoton_holidays.no_rooms_for_configuration")|default:"We couldn't find rooms that match all your requirements:"}
                        </p>
                        
                        <div style="background: #fff5f5; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin-bottom: 20px; text-align: left;">
                            {foreach from=$rooms_without_options item=problem_room}
                                <div style="padding: 8px 12px; margin-bottom: 8px; background: #fff; border-radius: 4px; border-left: 4px solid #dc3545;">
                                    <strong style="color: #dc3545;">Room #{$problem_room.num}:</strong>
                                    <span style="color: #333;">
                                        {$problem_room.adults} {if $problem_room.adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")}{/if}{if $problem_room.children > 0} + {$problem_room.children} {if $problem_room.children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")}{/if}{/if}
                                    </span>
                                    <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                        {__("novoton_holidays.no_room_types_support")|default:"No room types available for this occupancy"}
                                    </div>
                                </div>
                            {/foreach}
                        </div>
                        
                        {* Show hotel's maximum room capacity (calculated in PHP) *}
                        {if $max_room_capacity}
                        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px; text-align: center;">
                            <strong style="color: #856404; display: block; margin-bottom: 8px; font-size: 15px;">
                                 {__("novoton_holidays.hotel_max_capacity")|default:"Maximum room capacity at this hotel"}:
                            </strong>
                            <div style="font-size: 24px; font-weight: 700; color: #333;">
                                {$max_room_capacity.adults} {if $max_room_capacity.adults == 1}{__("novoton_holidays.adult")|default:"adult"}{else}{__("novoton_holidays.adults")}{/if} + {$max_room_capacity.children} {if $max_room_capacity.children == 1}{__("novoton_holidays.child")|default:"child"}{else}{__("novoton_holidays.children")}{/if}
                            </div>
                            <div style="font-size: 13px; color: #666; margin-top: 5px;">
                                ({$max_room_capacity.total} {__("novoton_holidays.persons_per_room")|default:"persons per room"})
                            </div>
                        </div>
                        {/if}
                        
                        <div style="background: #e7f3ff; border: 1px solid #b6d4fe; border-radius: 8px; padding: 15px; text-align: left;">
                            <strong style="color: #0056b3; display: block; margin-bottom: 8px;">
                                 {__("novoton_holidays.suggestions")|default:"Suggestions"}:
                            </strong>
                            <ul style="margin: 0; padding-left: 20px; color: #333; font-size: 14px;">
                                <li style="margin-bottom: 6px;">{__("novoton_holidays.suggestion_split_rooms")|default:"Split your group into more rooms with fewer guests each"}</li>
                                <li style="margin-bottom: 6px;">{__("novoton_holidays.suggestion_different_dates")|default:"Try different dates when more room types may be available"}</li>
                                <li>{__("novoton_holidays.suggestion_contact_us")|default:"Contact us for assistance with special arrangements"}</li>
                            </ul>
                        </div>
                        
                        <div style="margin-top: 25px;">
                            <button onclick="window.history.back()" style="background: #6c757d; color: #fff; border: none; padding: 12px 30px; border-radius: 6px; font-size: 15px; cursor: pointer; margin-right: 10px;">
                                <- {__("novoton_holidays.go_back")|default:"Go Back"}
                            </button>
                            <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&product_id=`$novoton_params.product_id`")}" style="display: inline-block; background: #0071c2; color: #fff; border: none; padding: 12px 30px; border-radius: 6px; font-size: 15px; text-decoration: none;">
                                {__("novoton_holidays.change_search")|default:"Change Search"} ->
                            </a>
                        </div>
                    </div>
                </div>
            
            {else}
            {* All rooms have options - show selection grid *}
            <div class="multi-room-selection" id="multi-room-selection" 
                 data-num-rooms="{$novoton_params.num_rooms}" 
                 data-rooms-data='{$novoton_params.rooms_data_json|default:"[]"|escape:"html"}'
                 style="background: #fff; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; overflow: hidden;">
                
                <div style="background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e0e0e0;">
                    <h3 style="margin: 0 0 5px; color: #003580; font-size: 18px;">
                         {__("novoton_holidays.select_room_types")}
                    </h3>
                    <p style="margin: 0; color: #666; font-size: 14px;">
                        {__("novoton_holidays.select_room_type_for_each")}
                    </p>
                </div>
                
                {* HORIZONTAL ROOM SELECTION GRID *}
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; padding: 20px;">
                
                {foreach from=$novoton_params.rooms_data item=room key=room_idx}
                    {$room_num = $room_idx + 1}
                    {assign var="room_key" value=$room_num}
                    {if isset($all_room_results.$room_key)}
                        {$room_specific_results = $all_room_results.$room_key}
                    {else}
                        {$room_specific_results = []}
                    {/if}
                    
                    <div class="room-type-selection" data-room="{$room_num}" style="background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                        <div style="background: #f8f9fa; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #003580;">
                            <div>
                                <strong style="font-size: 16px; color: #003580;">#{$room_num}</strong>
                                <span style="color: #333; margin-left: 8px; font-size: 14px;">
                                    {$room.adults} {if $room.adults == 1}{__("novoton_holidays.adult")}{else}{__("novoton_holidays.adults")}{/if}{if $room.children > 0} + {$room.children} {if $room.children == 1}{__("novoton_holidays.child")}{else}{__("novoton_holidays.children")}{/if}{/if}
                                </span>
                            </div>
                            <div id="room-{$room_num}-price" style="font-size: 18px; font-weight: 700; color: #003580;">-- {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                        </div>
                        
                        <div style="padding: 10px; max-height: 400px; overflow-y: auto;">
                            {if $room_specific_results && $room_specific_results|@count > 0}
                                {foreach from=$room_specific_results item=result}
                                    {if $result.room_type_display}
                                        {$room_display = $result.room_type_display}
                                    {else}
                                        {$room_display = $result.room_name|default:$result.room_id}
                                    {/if}
                                    
                                    {$board_display = $result.board_id|novoton_format_board}
                                    
                                    <label class="room-option" style="display: flex; align-items: flex-start; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; background: #fff; gap: 12px;">
                                        <input type="radio" 
                                               name="room_{$room_num}_selection" 
                                               value="{$result.room_id}|{$result.board_id}|{$result.total_price}"
                                               data-room-num="{$room_num}"
                                               data-room-id="{$result.room_id}"
                                               data-board-id="{$result.board_id}"
                                               data-price="{$result.total_price}"
                                               data-room-display="{$room_display}"
                                               data-board-name="{$board_display}"
                                               data-package-name="{$result.package_name|escape:'htmlall'}"
                                               style="width: 20px; height: 20px; margin-top: 2px; flex-shrink: 0;" />
                                        
                                        <div style="flex: 1; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; min-width: 0;">
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="font-weight: 600; color: #333;">{$room_display}</div>
                                                <div style="font-size: 13px; color: #666;">
                                                     {$board_display}
                                                    {if $result.package_name}
                                                        <span style="color: #003580; font-size: 11px; margin-left: 5px;">- {$result.package_name}</span>
                                                    {/if}
                                                </div>
                                                {* Room availability - Quota display *}
                                                {if $result.is_on_request || $result.rooms_available === 0 || $result.rooms_available === '0'}
                                                    <div style="color: #dc3545; font-size: 11px; margin-top: 2px;">
                                                        <strong>{__("novoton_holidays.reservation_on_request")}</strong> <span style="font-weight: normal;">- {__("novoton_holidays.confirmation_48h")|default:"confirmation within max 48 hours"}</span>
                                                    </div>
                                                    {if $result.nearby_availability && $result.nearby_availability|@count > 0}
                                                        <div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; padding: 4px 8px; margin-top: 4px; font-size: 11px;">
                                                            <strong style="color: #f57f17;">{__("novoton_holidays.nearby_dates_available")|default:"Available on nearby dates"}:</strong>
                                                            {foreach from=$result.nearby_availability item=nearby name=nearby_loop}
                                                                <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$nearby.check_in`&check_out=`$nearby.check_out`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}"
                                                                   style="color: #e65100; text-decoration: underline; white-space: nowrap;">
                                                                    {$nearby.check_in|date_format:"%b %d"} - {$nearby.check_out|date_format:"%b %d"} ({$nearby.quota} {__("novoton_holidays.rooms_short")|default:"rooms"})
                                                                </a>{if !$smarty.foreach.nearby_loop.last}, {/if}
                                                            {/foreach}
                                                        </div>
                                                    {/if}
                                                {elseif $result.rooms_available !== null && $result.rooms_available !== ''}
                                                    {if $result.rooms_available > 5}
                                                        <div style="color: #28a745; font-size: 11px; font-weight: 600; margin-top: 2px;">{$result.rooms_available} {__("novoton_holidays.available_rooms")}</div>
                                                    {elseif $result.rooms_available >= 1}
                                                        <div style="color: #dc3545; font-size: 11px; font-weight: 600; margin-top: 2px;">{__("novoton_holidays.we_have_left", ["[count]" => $result.rooms_available])}</div>
                                                    {/if}
                                                {/if}
                                            </div>
                                            
                                            <div style="text-align: right; flex-shrink: 0;">
                                                {if $result.early_booking_discount > 0}
                                                    {math equation="price / (1 - discount / 100)" price=$result.total_price discount=$result.early_booking_discount assign="original_price"}
                                                    <div style="font-size: 12px; color: #999; text-decoration: line-through;">{math equation="round(x * y)" x=$original_price y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                                                {/if}
                                                <div style="font-size: 18px; font-weight: 700; color: #003580;">{math equation="round(x * y)" x=$result.total_price|default:0 y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                                            </div>
                                        </div>
                                    </label>
                                {/foreach}
                            {else}
                                <div style="padding: 20px; text-align: center; color: #dc3545; background: #fff5f5; border-radius: 6px;">
                                    <strong>{__("novoton_holidays.no_rooms_available")|default:"No rooms available for this configuration"}</strong>
                                </div>
                            {/if}
                        </div>
                    </div>
                {/foreach}
                
                </div>
                
                {* Total Price & Book Button *}
                <div style="background: #f8f9fa; border-top: 3px solid #003580; color: #333; padding: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                        <div>
                            <div style="font-size: 14px; color: #666;">{__("novoton_holidays.total_for_all_rooms")}</div>
                            <div style="font-size: 32px; font-weight: 700; color: #003580;" id="total-combined-price">-- {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                        </div>
                        <div>
                            <button type="button" 
                                    id="book-multi-room-btn" 
                                    disabled
                                    style="background: #28a745; color: #fff; border: none; padding: 15px 40px; font-size: 16px; font-weight: 600; border-radius: 8px; cursor: pointer; opacity: 0.5;">
                                {__("novoton_holidays.book_now")}
                            </button>
                        </div>
                    </div>
                </div>
                
                <form id="multi-room-booking-form" method="get" action="{$config.current_location|fn_url}" style="display: none;">
                    <input type="hidden" name="dispatch" value="novoton_booking.booking_form" />
                    <input type="hidden" name="hotel_id" value="{$novoton_params.hotel_id}" />
                    <input type="hidden" name="check_in" value="{$check_in_date}" />
                    <input type="hidden" name="check_out" value="{$check_out_date}" />
                    <input type="hidden" name="nights" value="{$novoton_params.nights}" />
                    <input type="hidden" name="num_rooms" value="{$novoton_params.num_rooms}" />
                    <input type="hidden" name="multi_room" value="1" />
                    <input type="hidden" name="rooms_data" id="hidden_rooms_data" value="" />
                    <input type="hidden" name="total_price" id="hidden_total_price" value="" />
                    {* Terms are now fetched directly from API at checkout *}
                </form>
            </div>
            
            {* Multi-room JavaScript *}
            <script>
            (function() {
                'use strict';

                var novotonCurrency = '{$novoton_display_symbol|default:$smarty.const.CART_PRIMARY_CURRENCY|escape:"javascript"}';
                var novotonCoeff = {$novoton_display_coefficient|default:1};
                var numRooms = {$novoton_params.num_rooms|default:1};
                var selectedRooms = {ldelim}{rdelim};
                var roomOccupancy = JSON.parse('{$novoton_params.rooms_data_json|default:"[]"|escape:"javascript"}');
                
                function updateRoomSelection(radio) {
                    var roomNum = parseInt(radio.getAttribute('data-room-num'));
                    var price = parseFloat(radio.getAttribute('data-price')) || 0;
                    var roomId = radio.getAttribute('data-room-id');
                    var boardId = radio.getAttribute('data-board-id');
                    var roomDisplay = radio.getAttribute('data-room-display');
                    var boardName = radio.getAttribute('data-board-name');
                    var packageName = radio.getAttribute('data-package-name') || '';
                    
                    var occupancy = roomOccupancy[roomNum - 1] || {ldelim}adults: 2, children: 0, childrenAges: []{rdelim};
                    
                    selectedRooms[roomNum] = {ldelim}
                        room_id: roomId,
                        board_id: boardId,
                        price: price,
                        room_display: roomDisplay,
                        board_name: boardName,
                        package_name: packageName,
                        adults: occupancy.adults || 2,
                        children: occupancy.children || 0,
                        childrenAges: occupancy.childrenAges || []
                    {rdelim};
                    
                    var priceEl = document.getElementById('room-' + roomNum + '-price');
                    if (priceEl) {
                        priceEl.textContent = Math.round(price * novotonCoeff) + ' ' + novotonCurrency;
                        priceEl.style.color = '#ffc107';
                    }
                    
                    // Highlight selected
                    var container = radio.closest('[data-room]');
                    if (container) {
                        container.querySelectorAll('.room-option').forEach(function(opt) {
                            opt.style.borderColor = '#e0e0e0';
                            opt.style.background = '#fff';
                        });
                        radio.closest('.room-option').style.borderColor = '#003580';
                        radio.closest('.room-option').style.background = '#e8f4fd';
                    }
                    
                    updateTotalPrice();
                }
                
                function updateTotalPrice() {
                    var totalPrice = 0;
                    var selectedCount = 0;
                    
                    for (var i = 1; i <= numRooms; i++) {
                        if (selectedRooms[i] && selectedRooms[i].price) {
                            totalPrice += selectedRooms[i].price;
                            selectedCount++;
                        }
                    }
                    
                    var totalEl = document.getElementById('total-combined-price');
                    var bookBtn = document.getElementById('book-multi-room-btn');
                    
                    if (totalEl) {
                        totalEl.textContent = totalPrice > 0 ? Math.round(totalPrice * novotonCoeff) + ' ' + novotonCurrency : '-- ' + novotonCurrency;
                    }
                    
                    if (bookBtn) {
                        if (selectedCount === numRooms) {
                            bookBtn.disabled = false;
                            bookBtn.style.opacity = '1';
                        } else {
                            bookBtn.disabled = true;
                            bookBtn.style.opacity = '0.5';
                        }
                    }
                }
                
                document.addEventListener('change', function(e) {
                    var target = e.target;
                    if (target.type === 'radio' && target.name && /^room_\d+_selection$/.test(target.name)) {
                        updateRoomSelection(target);
                    }
                });
                
                document.addEventListener('click', function(e) {
                    if (e.target.id === 'book-multi-room-btn' && !e.target.disabled) {
                        var roomsData = [];
                        var total = 0;
                        
                        for (var i = 1; i <= numRooms; i++) {
                            if (selectedRooms[i]) {
                                roomsData.push({ldelim}
                                    room_num: i,
                                    room_id: selectedRooms[i].room_id,
                                    board_id: selectedRooms[i].board_id,
                                    price: selectedRooms[i].price,
                                    room_display: selectedRooms[i].room_display,
                                    board_name: selectedRooms[i].board_name,
                                    package_name: selectedRooms[i].package_name,
                                    adults: selectedRooms[i].adults,
                                    children: selectedRooms[i].children,
                                    childrenAges: selectedRooms[i].childrenAges
                                {rdelim});
                                total += selectedRooms[i].price;
                            }
                        }
                        
                        document.getElementById('hidden_rooms_data').value = JSON.stringify(roomsData);
                        document.getElementById('hidden_total_price').value = total;
                        document.getElementById('multi-room-booking-form').submit();
                    }
                });
            })();
            </script>
            
            {/if}{* End of all_rooms_have_options else block *}
        
        {else}
        
        {* ===== SINGLE ROOM MODE - TABLE/CARD LAYOUT ===== *}
        <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; overflow: hidden;">
            
            {* Desktop Table Header *}
            {* G2: Table header - Desktop only with inline grid - A76g: Light background *}
            <div class="novoton-table-header novoton-desktop-only" style="display: grid; grid-template-columns: 2fr 2fr 1fr 200px; background: #f8f9fa; color: #003580; font-weight: 600; font-size: 14px; border-bottom: 2px solid #003580;">
                <div style="padding: 15px 20px; color: #003580;">{__("novoton_holidays.room_type")}</div>
                <div style="padding: 15px 20px; color: #003580;">{__("novoton_holidays.your_choices")}</div>
                <div style="padding: 15px 20px; color: #003580;">{__("novoton_holidays.price_for_stay", ["[nights]" => $novoton_params.nights|default:7])}</div>
                <div style="padding: 15px 20px; color: #003580;"></div>
            </div>
            
            {foreach from=$novoton_results item=result name=results}
                {if $result.room_type_display}
                    {$room_display = $result.room_type_display}
                {else}
                    {$room_display = $result.room_name|default:$result.room_id}
                {/if}
                
                {if $result.board_id == 'AI' || $result.board_id == 'ALL INCL'}
                    {$board_display = "{__('novoton_holidays.all_inclusive')|default:'All Inclusive'}"}
                {elseif $result.board_id == 'UAI' || $result.board_id|strpos:'ULTRA' !== false}
                    {$board_display = "{__('novoton_holidays.ultra_all_inclusive')|default:'Ultra All Inclusive'}"}
                {elseif $result.board_id == 'FB' || $result.board_id == 'FB+'}
                    {$board_display = "{__('novoton_holidays.full_board')|default:'Full Board'}"}
                {elseif $result.board_id == 'HB' || $result.board_id == 'HB+'}
                    {$board_display = "{__('novoton_holidays.half_board')|default:'Half Board'}"}
                {elseif $result.board_id == 'BB' || $result.board_id == 'B&B'}
                    {$board_display = "{__('novoton_holidays.bed_breakfast')|default:'Bed & Breakfast'}"}
                {elseif $result.board_id == 'RO' || $result.board_id == 'ROOM ONLY'}
                    {$board_display = "{__('novoton_holidays.room_only')|default:'Room Only'}"}
                {elseif $result.board_id == 'SC'}
                    {$board_display = "{__('novoton_holidays.self_catering')|default:'Self Catering'}"}
                {else}
                    {$board_display = $result.board_name|default:$result.board_id}
                {/if}
                
                {$result_package_name = $result.package_name|default:$hotel_package_name}
                {$row_id = $smarty.foreach.results.index}
                
                {* ===== MOBILE CARD VIEW ===== *}
                <div class="novoton-mobile-only novoton-room-card{if $result.is_on_request} on-request{/if}">
                    
                    {* Card Header - Room Name + Price *}
                    <div style="padding: 15px; border-bottom: 1px solid #f0f0f0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 700; color: #0071c2; font-size: 15px; line-height: 1.3;">{$room_display}</div>
                                {if $result_package_name}
                                    <div style="font-size: 12px; color: #666; margin-top: 3px;">{$result_package_name}</div>
                                {/if}
                                {* Room facilities *}
                                {if $novoton_room_facilities && $novoton_room_facilities|count > 0}
                                    <div style="display: flex; flex-wrap: wrap; gap: 2px 12px; margin-top: 5px;">
                                        {foreach from=$novoton_room_facilities item=rfac}
                                            {if $rfac.facility_name}
                                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #333; font-size: 12px; line-height: 20px;"><i class="icon-ok" style="color: #28a745; font-size: 10px;"></i>{$rfac.facility_name}</span>
                                            {/if}
                                        {/foreach}
                                    </div>
                                {/if}
                                {* MoreInfo from API *}
                                {if $result.more_info}
                                    <div style="font-size: 12px; color: #008009; margin-top: 4px;">
                                        ✓ {$result.more_info|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                                    </div>
                                {/if}
                                {* Important from API *}
                                {if $result.important}
                                    <div style="font-size: 11px; color: #856404; background: #fff3cd; padding: 4px 8px; border-radius: 4px; margin-top: 4px;">
                                        ⚠️ {$result.important|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                                    </div>
                                {/if}
                            </div>
                            <div style="text-align: right; flex-shrink: 0;">
                                {if $result.early_booking_discount > 0}
                                    {math equation="price / (1 - discount / 100)" price=$result.total_price discount=$result.early_booking_discount assign="original_price"}
                                    <div style="font-size: 13px; color: #999; text-decoration: line-through;">{math equation="round(x * y)" x=$original_price y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                                {/if}
                                <div style="font-size: 22px; font-weight: 700; color: #1a1a1a;">{math equation="round(x * y)" x=$result.total_price|default:0 y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                            </div>
                        </div>

                        {* Availability badge *}
                        <div style="margin-top: 8px;">
                            {if $result.is_on_request || $result.rooms_available === 0 || $result.rooms_available === '0'}
                                <span style="display: inline-block; background: #fff3cd; color: #856404; font-size: 11px; padding: 3px 8px; border-radius: 4px; font-weight: 600;">
                                     {__("novoton_holidays.on_request")|default:"La cerere"}
                                </span>
                                {if $result.nearby_availability && $result.nearby_availability|@count > 0}
                                    <div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; padding: 6px 8px; margin-top: 6px; font-size: 11px;">
                                        <strong style="color: #f57f17;">{__("novoton_holidays.nearby_dates_available")|default:"Available on nearby dates"}:</strong>
                                        {foreach from=$result.nearby_availability item=nearby name=nearby_loop}
                                            <div style="margin-top: 3px;">
                                                <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$nearby.check_in`&check_out=`$nearby.check_out`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}"
                                                   style="color: #e65100; text-decoration: underline;">
                                                    {$nearby.check_in|date_format:"%b %d"} - {$nearby.check_out|date_format:"%b %d"} ({$nearby.quota} {__("novoton_holidays.rooms_short")|default:"rooms"})
                                                </a>
                                            </div>
                                        {/foreach}
                                    </div>
                                {/if}
                            {elseif $result.rooms_available !== null && $result.rooms_available !== '' && $result.rooms_available <= 5}
                                <span style="display: inline-block; background: #f8d7da; color: #721c24; font-size: 11px; padding: 3px 8px; border-radius: 4px; font-weight: 600;">
                                     {$result.rooms_available} {__("novoton_holidays.left")|default:"disponibile"}
                                </span>
                            {elseif $result.rooms_available > 5}
                                <span style="display: inline-block; background: #d4edda; color: #155724; font-size: 11px; padding: 3px 8px; border-radius: 4px; font-weight: 600;">
                                    ✓ {$result.rooms_available} {__("novoton_holidays.available")|default:"disponibile"}
                                </span>
                            {/if}
                            
                            {if $result.early_booking_discount > 0}
                                <span style="display: inline-block; background: #ff6b35; color: #fff; font-size: 11px; padding: 3px 8px; border-radius: 4px; font-weight: 600; margin-left: 5px;">
                                    -{$result.early_booking_discount|string_format:"%.0f"}% {__("novoton_holidays.early_booking")}
                                </span>
                            {/if}
                        </div>
                    </div>
                    
                    {* Card Body - Options *}
                    <div style="padding: 12px 15px; background: #fafafa;">
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 12px;">
                            <span style="display: inline-flex; align-items: center; gap: 4px; color: #008009; font-weight: 600;">
                                 {$board_display}
                            </span>
                            
                            {if $result.free_cancellation_date}
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #008009;">
                                     {__("novoton_holidays.free_cancel")|default:"Anulare gratuita"} {$result.free_cancellation_date|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"}
                                </span>
                            {/if}
                            
                            {if $result.terms_of_payment || $terms_of_payment}
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #666;">
                                     {__("novoton_holidays.payment_terms_short")|default:"Conditii plata"}
                                </span>
                            {/if}
                            
                            {if $result.remark || $result.more_info || $result.important || $result.terms_of_payment || $result.terms_of_cancellation}
                                <a href="#" onclick="openInfoModal({$row_id}); return false;" style="display: inline-flex; align-items: center; gap: 4px; color: #0071c2; text-decoration: none;">
                                     {__("novoton_holidays.details")|default:"Detalii"}
                                </a>
                                <div id="modal-content-{$row_id}-mobile" style="display: none;">
                                    {* Payment Terms - displayed first *}
                                    {if $result.terms_of_payment}
                                        {$payment_terms_mobile = fn_novoton_holidays_format_payment_terms_with_amounts($result.terms_of_payment, $result.total_price, $novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:'')}
                                        {if $payment_terms_mobile}
                                            <div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>{$payment_terms_mobile|nl2br nofilter}</div>
                                        {/if}
                                    {/if}
                                    {* Cancellation Terms - displayed second *}
                                    {if $result.terms_of_cancellation}
                                        {$cancel_terms_mobile = fn_novoton_holidays_format_cancellation_terms($result.terms_of_cancellation, $check_in_date)}
                                        {if $cancel_terms_mobile}
                                            <div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>{$cancel_terms_mobile|nl2br nofilter}</div>
                                        {/if}
                                    {/if}
                                    {* Remark/Note field - uses translation key, collapses blank lines *}
                                    {if $result.remark}<div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.note")|default:"Note"}:</strong><br>{$result.remark|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|regex_replace:'/(\s*[\r\n]){2,}/':"\n"|trim|nl2br nofilter}</div>{/if}
                                    {* Additional information field *}
                                    {if $result.more_info}<div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.additional_information")|default:"Additional Information"}:</strong><br>{$result.more_info|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                    {* Important notice - highlighted *}
                                    {if $result.important}<div style="color: #c00; background: #fff5f5; padding: 10px; border-radius: 4px;"><strong>{__("novoton_holidays.important")|default:"Important"}:</strong><br>{$result.important|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                </div>
                            {/if}
                        </div>

                        <div style="font-size: 11px; color: #888; margin-top: 6px;">{__("novoton_holidays.includes_taxes")}</div>
                    </div>
                    
                    {* Card Footer - Book Button *}
                    <div style="padding: 12px 15px; background: #fff;">
                        {$single_room_data = [["adults" => $novoton_params.adults, "children" => $novoton_params.children_count, "childrenAges" => $novoton_params.children_ages_array|default:[]]]}
                        <a href="{fn_url("novoton_booking.booking_form?hotel_id=`$novoton_params.hotel_id`&room_id=`$result.room_id|escape:'url'`&board_id=`$result.board_id`&check_in=`$check_in_date`&check_out=`$check_out_date`&nights=`$novoton_params.nights`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&children_ages=`$novoton_params.children_ages|default:''`&price=`$result.total_price`&package_name=`$result_package_name|escape:'url'`&room_name=`$room_display|escape:'url'`&board_name=`$board_display|escape:'url'`&rooms_data=`$single_room_data|json_encode|escape:'url'`")}" 
                           style="display: block; background: #28a745; color: #fff; padding: 14px; font-size: 15px; font-weight: 600; border-radius: 6px; text-decoration: none; text-align: center;">
                            {__("novoton_holidays.select_room")|default:"Selecteaza"}
                        </a>
                    </div>
                </div>
                
                {* ===== DESKTOP TABLE ROW ===== *}
                <div class="result-row novoton-desktop-only{if $result.is_on_request} on-request{/if}" style="display: grid; grid-template-columns: 2fr 2fr 1fr 200px; border-bottom: 1px solid #e0e0e0; background: {if $result.is_on_request}#fff8e1{else}#fff{/if};">
                    
                    {* Room Type Column *}
                    <div style="padding: 20px; border-right: 1px solid #e0e0e0;">
                        <div style="font-weight: 700; color: #0071c2; font-size: 16px; margin-bottom: 5px;">{$room_display}</div>
                        {if $result_package_name}
                            <div style="font-size: 13px; color: #333; margin-bottom: 6px;">{$result_package_name}</div>
                        {/if}

                        {* Room facilities *}
                        {if $novoton_room_facilities && $novoton_room_facilities|count > 0}
                            <div style="display: flex; flex-wrap: wrap; gap: 2px 12px; margin-bottom: 8px;">
                                {foreach from=$novoton_room_facilities item=rfac}
                                    {if $rfac.facility_name}
                                        <span style="display: inline-flex; align-items: center; gap: 4px; color: #333; font-size: 12px; line-height: 20px;"><i class="icon-ok" style="color: #28a745; font-size: 10px;"></i>{$rfac.facility_name}</span>
                                    {/if}
                                {/foreach}
                            </div>
                        {/if}

                        {* MoreInfo from API - display additional room details *}
                        {if $result.more_info}
                            <div style="font-size: 13px; color: #008009; margin-bottom: 8px;">
                                ✓ {$result.more_info|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                            </div>
                        {/if}

                        {* Important from API - display important notices *}
                        {if $result.important}
                            <div style="font-size: 12px; color: #856404; background: #fff3cd; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px;">
                                ⚠️ {$result.important|replace:'lt;':'<'|replace:'gt;':'>'|replace:'amp;':'&'|strip_tags}
                            </div>
                        {/if}

                        {* Room availability - Quota display *}
                        {if $result.is_on_request || $result.rooms_available === 0 || $result.rooms_available === '0'}
                            <div style="color: #dc3545; font-size: 13px; margin-top: 8px;">
                                <strong>{__("novoton_holidays.reservation_on_request")}</strong> <span style="font-weight: normal;">- {__("novoton_holidays.confirmation_48h")|default:"confirmation within max 48 hours"}</span>
                            </div>
                            {if $result.nearby_availability && $result.nearby_availability|@count > 0}
                                <div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; padding: 6px 10px; margin-top: 6px; font-size: 12px;">
                                    <strong style="color: #f57f17;">{__("novoton_holidays.nearby_dates_available")|default:"Available on nearby dates"}:</strong>
                                    {foreach from=$result.nearby_availability item=nearby name=nearby_loop}
                                        <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$nearby.check_in`&check_out=`$nearby.check_out`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}"
                                           style="color: #e65100; text-decoration: underline; white-space: nowrap;">
                                            {$nearby.check_in|date_format:"%b %d"} - {$nearby.check_out|date_format:"%b %d"} ({$nearby.quota} {__("novoton_holidays.rooms_short")|default:"rooms"})
                                        </a>{if !$smarty.foreach.nearby_loop.last}, {/if}
                                    {/foreach}
                                </div>
                            {/if}
                        {elseif $result.rooms_available !== null && $result.rooms_available !== ''}
                            {if $result.rooms_available > 5}
                                <div style="color: #28a745; font-size: 13px; font-weight: 600; margin-top: 8px;">{$result.rooms_available} {__("novoton_holidays.available_rooms")}</div>
                            {elseif $result.rooms_available >= 1}
                                <div style="color: #dc3545; font-size: 13px; font-weight: 600; margin-top: 8px;">{__("novoton_holidays.we_have_left", ["[count]" => $result.rooms_available])}</div>
                            {/if}
                        {/if}
                    </div>
                    
                    {* Choices Column *}
                    <div style="padding: 20px; border-right: 1px solid #e0e0e0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="font-weight: 600; color: #008009;">{$result.board_name|default:$board_display}</span>
                        </div>
                        
                        {* Free Cancellation Date *}
                        {if $result.free_cancellation_date}
                            <div style="font-size: 13px; color: #008009; margin-bottom: 5px;">
                                <span style="color: #008009;">✓</span> {__("novoton_holidays.free_cancellation_until")|default:"Anulare gratuită până la"} <strong>{$result.free_cancellation_date|date_format:$settings.Appearance.date_format|default:"%d.%m.%Y"}</strong>
                            </div>
                        {/if}
                        
                        {$has_payment_terms = $result.terms_of_payment || $terms_of_payment}
                        {$has_cancel_terms = $result.terms_of_cancellation || $terms_of_cancellation}

                        {if $has_payment_terms && $has_cancel_terms}
                            <div style="font-size: 13px; color: #008009; margin-bottom: 5px;">✓ {__("novoton_holidays.payment_and_cancellation_terms_apply")}</div>
                        {elseif $has_payment_terms}
                            <div style="font-size: 13px; color: #008009; margin-bottom: 5px;">✓ {__("novoton_holidays.payment_terms_apply")}</div>
                        {elseif $has_cancel_terms}
                            <div style="font-size: 13px; color: #008009; margin-bottom: 5px;">✓ {__("novoton_holidays.cancellation_terms_apply")}</div>
                        {/if}
                        
                        {if $result.remark || $result.more_info || $result.important || $result.terms_of_payment || $result.terms_of_cancellation}
                            <div style="margin-top: 8px;">
                                <a href="#" onclick="openInfoModal({$row_id}); return false;" style="font-size: 12px; color: #0071c2; text-decoration: none; border-bottom: 1px dashed #0071c2;">ℹ️ {__("novoton_holidays.more_info")|default:"Mai multe informații"}</a>
                            </div>
                            <div id="modal-content-{$row_id}" style="display: none;">
                                {* Payment Terms - displayed first *}
                                {if $result.terms_of_payment}
                                    {$payment_terms_desktop = fn_novoton_holidays_format_payment_terms_with_amounts($result.terms_of_payment, $result.total_price, $novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY, $novoton_display_coefficient|default:1, $novoton_display_symbol|default:'')}
                                    {if $payment_terms_desktop}
                                        <div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.terms_of_payment")|default:"Termeni de plată"}:</strong><br>{$payment_terms_desktop|nl2br nofilter}</div>
                                    {/if}
                                {/if}
                                {* Cancellation Terms - displayed second *}
                                {if $result.terms_of_cancellation}
                                    {$cancel_terms_desktop = fn_novoton_holidays_format_cancellation_terms($result.terms_of_cancellation, $check_in_date)}
                                    {if $cancel_terms_desktop}
                                        <div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}:</strong><br>{$cancel_terms_desktop|nl2br nofilter}</div>
                                    {/if}
                                {/if}
                                {* Remark/Note field - uses translation key, collapses blank lines *}
                                {if $result.remark}<div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.note")|default:"Note"}:</strong><br>{$result.remark|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|regex_replace:'/(\s*[\r\n]){2,}/':"\n"|trim|nl2br nofilter}</div>{/if}
                                {* Additional information field *}
                                {if $result.more_info}<div style="margin-bottom: 12px;"><strong style="color: #333;">{__("novoton_holidays.additional_information")|default:"Additional Information"}:</strong><br>{$result.more_info|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                                {* Important notice - highlighted *}
                                {if $result.important}<div style="color: #c00; background: #fff5f5; padding: 10px; border-radius: 4px;"><strong>{__("novoton_holidays.important")|default:"Important"}:</strong><br>{$result.important|escape:'html'|replace:'lt;pgt;':'<p>'|replace:'lt;/pgt;':'</p>'|replace:'lt;br /gt;':'<br>'|replace:'lt;br/gt;':'<br>'|replace:'amp;':'&'|nl2br nofilter}</div>{/if}
                            </div>
                        {/if}
                    </div>
                    
                    {* Price Column *}
                    <div style="padding: 20px; border-right: 1px solid #e0e0e0; text-align: right;">
                        {if $result.early_booking_discount > 0}
                            {math equation="price / (1 - discount / 100)" price=$result.total_price discount=$result.early_booking_discount assign="original_price"}
                            <div style="font-size: 14px; color: #999; text-decoration: line-through;">{math equation="round(x * y)" x=$original_price y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                        {/if}
                        <div style="font-size: 22px; font-weight: 700; color: #1a1a1a;">{math equation="round(x * y)" x=$result.total_price|default:0 y=$novoton_display_coefficient|default:1} {$novoton_display_symbol|default:$novoton_display_currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
                        <div style="font-size: 12px; color: #666;">{__("novoton_holidays.includes_taxes")}</div>
                        {if $result.early_booking_discount > 0}
                            <div style="display: inline-block; background: #ff6b35; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-top: 5px;">
                                -{$result.early_booking_discount|string_format:"%.0f"}% {__("novoton_holidays.early_booking")}
                            </div>
                        {/if}
                    </div>
                    
                    {* Action Column *}
                    <div style="padding: 15px 10px; display: flex; align-items: center; justify-content: center;">
                        {* Build rooms_data JSON for single room *}
                        {$single_room_data = [["adults" => $novoton_params.adults, "children" => $novoton_params.children_count, "childrenAges" => $novoton_params.children_ages_array|default:[]]]}
                        <a href="{fn_url("novoton_booking.booking_form?hotel_id=`$novoton_params.hotel_id`&room_id=`$result.room_id|escape:'url'`&board_id=`$result.board_id`&check_in=`$check_in_date`&check_out=`$check_out_date`&nights=`$novoton_params.nights`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&children_ages=`$novoton_params.children_ages|default:''`&price=`$result.total_price`&package_name=`$result_package_name|escape:'url'`&room_name=`$room_display|escape:'url'`&board_name=`$board_display|escape:'url'`&rooms_data=`$single_room_data|json_encode|escape:'url'`")}" 
                           style="display: inline-block; background: #0071c2; color: #fff; padding: 14px 28px; font-size: 16px; font-weight: 600; border-radius: 6px; text-decoration: none; text-align: center; transition: all 0.2s; white-space: nowrap; min-width: 140px;">
                            {__("novoton_holidays.book")}
                        </a>
                    </div>
                </div>
            {/foreach}
        </div>
        
        {* ===== TERMS & CONDITIONS ===== *}
        {if $terms_of_payment || $terms_of_cancellation || $parsed_payment_terms || $parsed_cancellation_terms || $terms_of_payment_raw || $terms_of_cancellation_raw}
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
            
            {if $parsed_payment_terms|@count > 0 || $terms_of_payment || $terms_of_payment_raw}
            <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                <h4 style="margin: 0 0 15px; font-size: 16px; color: #333;">
                    💳 {__("novoton_holidays.payment_terms")|default:"Condiții de plată"}
                </h4>
                {if $parsed_payment_terms && $parsed_payment_terms|@count > 0}
                    <ul style="margin: 0; padding-left: 0; list-style: none;">
                    {foreach from=$parsed_payment_terms item=term}
                        <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #555;">
                            {if $term.is_on_booking}
                                {$term.percent|string_format:"%d"}% {__("novoton_holidays.on_booking")|default:"la rezervare"}
                            {elseif $term.date_formatted}
                                {$term.percent|string_format:"%d"}% {__("novoton_holidays.until")|default:"până la"} {$term.date_formatted}
                            {elseif $term.date}
                                {$term.percent|string_format:"%d"}% {__("novoton_holidays.until")|default:"până la"} {$term.date}
                            {else}
                                {$term.percent|string_format:"%d"}%
                            {/if}
                        </li>
                    {/foreach}
                    </ul>
                {elseif $terms_of_payment}
                    <div style="font-size: 13px; color: #555; line-height: 1.6;">{$terms_of_payment|nl2br nofilter}</div>
                {else}
                    <div style="font-size: 13px; color: #888;">Condiții de plată disponibile</div>
                {/if}
            </div>
            {/if}
            
            {if $terms_of_cancellation || $terms_of_cancellation_raw}
            <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                <h4 style="margin: 0 0 15px; font-size: 16px; color: #333;">
                    📋 {__("novoton_holidays.cancellation_terms")|default:"Condiții de anulare"}
                </h4>
                {if $terms_of_cancellation}
                    <div style="font-size: 14px; color: #555; line-height: 1.8;">{$terms_of_cancellation|nl2br nofilter}</div>
                {else}
                    <div style="font-size: 13px; color: #888;">{__("novoton_holidays.cancellation_terms_available")|default:"Condiții de anulare disponibile"}</div>
                {/if}
            </div>
            {/if}
        </div>
        {/if}
        
        {/if}
        
    {elseif $no_availability_message && $alternative_results && $alternative_results|@count > 0}
        {* Show alternative dates results *}
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 15px;">
                <span style="font-size: 28px;"></span>
                <div>
                    <h3 style="margin: 0; color: #856404; font-size: 18px;">
                        {if $flex_days > 0}
                            {__("novoton_holidays.flexible_dates_found")|default:"We found availability on nearby dates!"}
                        {else}
                            {__("novoton_holidays.alternative_dates_found")|default:"No availability on selected dates, but found on:"}
                        {/if}
                    </h3>
                    <p style="margin: 5px 0 0; color: #856404;">
                        {$alternative_check_in|date_format:"%a, %b %d"} - {$alternative_check_out|date_format:"%a, %b %d, %Y"}
                    </p>
                </div>
            </div>
            <a href="{fn_url("novoton_booking.search?hotel_id=`$novoton_params.hotel_id`&check_in=`$alternative_check_in`&nights=`$novoton_params.nights`&adults=`$novoton_params.adults`&children=`$novoton_params.children_count`&rooms=`$novoton_params.num_rooms`")}" 
               style="display: inline-block; background: #ffc107; color: #333; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                {__("novoton_holidays.view_availability")|default:"View availability for these dates"} ->
            </a>
        </div>
        
        <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 40px; text-align: center;">
            <span style="font-size: 48px;"></span>
            <h3 style="margin: 20px 0 10px; color: #333;">{__("novoton_holidays.no_availability_selected_dates")|default:"No availability for your selected dates"}</h3>
            <p style="color: #666; margin-bottom: 20px;">
                {$novoton_params.check_in|date_format:"%a, %b %d"} - {$novoton_params.check_out|date_format:"%a, %b %d, %Y"}
            </p>
            {if $hotel_season_from && $hotel_season_to}
            <p style="color: #0071c2; font-size: 13px; margin-bottom: 0;">
                {__("novoton_holidays.accommodation_period")|default:"This hotel offers accommodation from"} {$hotel_season_from|date_format:"%d %b"} {__("novoton_holidays.to")|default:"to"} {$hotel_season_to|date_format:"%d %b %Y"}
            </p>
            {/if}
        </div>
    {elseif $no_availability_message}
        <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 40px; text-align: center;">
            <span style="font-size: 48px;"></span>
            <h3 style="margin: 20px 0 10px; color: #333;">{__("novoton_holidays.no_availability")}</h3>
            <p style="color: #666; margin-bottom: 20px;">{__("novoton_holidays.try_different_dates")}</p>
            {if $hotel_season_from && $hotel_season_to}
            <p style="color: #0071c2; font-size: 13px; margin-bottom: 20px;">
                {__("novoton_holidays.accommodation_period")|default:"This hotel offers accommodation from"} {$hotel_season_from|date_format:"%d %b"} {__("novoton_holidays.to")|default:"to"} {$hotel_season_to|date_format:"%d %b %Y"}
            </p>
            {/if}
            
            {* Request Alternatives Form *}
            <div style="background: #f0f7ff; border: 1px solid #0071c2; border-radius: 8px; padding: 25px; margin-top: 20px; text-align: left; max-width: 500px; margin-left: auto; margin-right: auto;">
                <h4 style="margin: 0 0 15px; color: #003580; font-size: 16px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;"></span>
                    {__("novoton_holidays.request_alternatives_title")|default:"Can't find what you're looking for?"}
                </h4>
                <p style="color: #555; font-size: 14px; margin-bottom: 15px;">
                    {__("novoton_holidays.request_alternatives_desc")|default:"Leave your contact details and we'll notify you when alternatives become available for your dates."}
                </p>
                
                <form id="request-alternatives-form" method="post" action="{fn_url('novoton_booking.request_alternatives')}">
                    <input type="hidden" name="hotel_id" value="{$novoton_params.hotel_id}">
                    <input type="hidden" name="hotel_name" value="{$hotel_name|escape:'html'}">
                    <input type="hidden" name="check_in" value="{$novoton_params.check_in}">
                    <input type="hidden" name="check_out" value="{$novoton_params.check_out}">
                    <input type="hidden" name="nights" value="{$novoton_params.nights}">
                    <input type="hidden" name="adults" value="{$novoton_params.adults}">
                    <input type="hidden" name="children" value="{$novoton_params.children_count}">
                    <input type="hidden" name="num_rooms" value="{$novoton_params.num_rooms}">
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.email")}<span style="color: #e74c3c;">*</span></label>
                            <input type="email" name="contact_email" required placeholder="your@email.com" 
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                        </div>
                        <div style="flex: 1; min-width: 150px;">
                            <label style="display: block; font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.phone")}</label>
                            <input type="tel" name="contact_phone" placeholder="+40..." 
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 12px; color: #666; margin-bottom: 4px;">{__("novoton_holidays.notes")|default:"Notes"}</label>
                        <textarea name="notes" rows="2" placeholder="{__('novoton_holidays.alternatives_notes_placeholder')|default:'Any specific requirements or preferences...'}"
                                  style="width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; resize: vertical;"></textarea>
                    </div>
                    
                    <button type="submit" style="width: 100%; background: #0071c2; color: #fff; border: none; padding: 12px 20px; border-radius: 4px; font-size: 15px; font-weight: 600; cursor: pointer;">
                        {__("novoton_holidays.request_alternatives_btn")|default:"Notify me when available"} ->
                    </button>
                    
                    <p style="font-size: 11px; color: #888; margin: 10px 0 0; text-align: center;">
                        {__("novoton_holidays.request_alternatives_privacy")|default:"We'll only use your contact info to notify you about availability."}
                    </p>
                </form>
            </div>
        </div>
    {/if}
    
</div>

{* Modal for More Info *}
<div id="info-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; max-width: 550px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative;">
        <div style="position: sticky; top: 0; background: #fff; padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; color: #333;">{__("novoton_holidays.room_details")}</h3>
            <button onclick="closeInfoModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #666; padding: 0; line-height: 1; width: 36px; height: 36px;">&times;</button>
        </div>
        <div id="info-modal-content" style="padding: 20px; font-size: 14px; line-height: 1.6; color: #333;"></div>
    </div>
</div>

<script>
function openInfoModal(rowId) {
    var content = document.getElementById('modal-content-' + rowId);
    if (content) {
        document.getElementById('info-modal-content').innerHTML = content.innerHTML;
        document.getElementById('info-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
function closeInfoModal() {
    document.getElementById('info-modal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('info-modal').addEventListener('click', function(e) { if (e.target === this) closeInfoModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeInfoModal(); });

/**
 * Dynamically update the availability badge text without page reload.
 * Call updateAvailabilityBadge(roomsCount, offersCount) from anywhere.
 */
window.updateAvailabilityBadge = function(roomsCount, offersCount) {
    var badge = document.getElementById('novoton-availability-badge');
    if (!badge) return;
    var tr = window.NovotonTranslations || {};
    var roomLabel = (roomsCount === 1) ? (tr.room || 'room') : (tr.rooms || 'rooms');
    var offerLabel = (offersCount === 1) ? (tr.offer || 'offer') : (tr.offers || 'offers');
    var availableLabel = tr.available || 'Available';
    badge.textContent = '\u2713 ' + availableLabel + ': ' + roomsCount + ' ' + roomLabel + ', ' + offersCount + ' ' + offerLabel;
    badge.setAttribute('data-rooms-count', roomsCount);
    badge.setAttribute('data-offers-count', offersCount);
};
</script>

{* Load React for search form *}
<script>
window.NovotonTranslations = {
    availability: "{__('novoton_holidays.availability')|default:'Availability'}",
    bookYourStay: "{__('novoton_holidays.book_your_stay')|default:'Book Your Stay'}",
    checkIn: "{__('novoton_holidays.check_in')|default:'Check-in'}",
    checkOut: "{__('novoton_holidays.check_out')|default:'Check-out'}",
    selectDates: "{__('novoton_holidays.select_dates')|default:'Select dates'}",
    guests: "{__('novoton_holidays.guests')|default:'Guests'}",
    adult: "{__('novoton_holidays.adult')|default:'adult'}",
    adults: "{__('novoton_holidays.adults')|default:'adults'}",
    children: "{__('novoton_holidays.children')|default:'children'}",
    rooms: "{__('novoton_holidays.rooms')|default:'rooms'}",
    done: "{__('novoton_holidays.done')|default:'Done'}",
    room: "{__('novoton_holidays.room')|default:'Room'}",
    search: "{__('novoton_holidays.search')|default:'Search'}",
    addRoom: "{__('novoton_holidays.add_room')|default:'Add Room'}",
    childrenAges: "{__('novoton_holidays.childrens_ages')|default:"Children's ages"}",
    selectAge: "{__('novoton_holidays.select_age')|default:'Select age'}",
    yearsOld: "{__('novoton_holidays.years_old')|default:'years old'}",
    yearOld: "{__('novoton_holidays.year_old')|default:'year old'}",
    night: "{__('novoton_holidays.night')|default:'night'}",
    nights: "{__('novoton_holidays.nights')|default:'nights'}",
    applyChanges: "{__('novoton_holidays.apply_changes')|default:'Apply changes'}",
    selected: "{__('novoton_holidays.selected')|default:'selected'}",
    january: "{__('novoton_holidays.january')|default:'January'}",
    february: "{__('novoton_holidays.february')|default:'February'}",
    march: "{__('novoton_holidays.march')|default:'March'}",
    april: "{__('novoton_holidays.april')|default:'April'}",
    may: "{__('novoton_holidays.may')|default:'May'}",
    june: "{__('novoton_holidays.june')|default:'June'}",
    july: "{__('novoton_holidays.july')|default:'July'}",
    august: "{__('novoton_holidays.august')|default:'August'}",
    september: "{__('novoton_holidays.september')|default:'September'}",
    october: "{__('novoton_holidays.october')|default:'October'}",
    november: "{__('novoton_holidays.november')|default:'November'}",
    december: "{__('novoton_holidays.december')|default:'December'}",
    mon: "{__('novoton_holidays.mon')|default:'Mo'}",
    tue: "{__('novoton_holidays.tue')|default:'Tu'}",
    wed: "{__('novoton_holidays.wed')|default:'We'}",
    thu: "{__('novoton_holidays.thu')|default:'Th'}",
    fri: "{__('novoton_holidays.fri')|default:'Fr'}",
    sat: "{__('novoton_holidays.sat')|default:'Sa'}",
    sun: "{__('novoton_holidays.sun')|default:'Su'}",
    selectCheckOut: "{__('novoton_holidays.select_check_out')|default:'Select check-out date'}",
    selectedSingular: "{__('novoton_holidays.selected_singular')|default:'selected'}",
    childAge: "{__('novoton_holidays.child_age')|default:"Child's age at check-in"}",
    dobCannotBeFuture: "{__('novoton_holidays.dob_cannot_be_future')|default:'Data nasterii nu poate fi in viitor'}",
    child: "{__('novoton_holidays.child')|default:'child'}",
    childLabel: "{__('novoton_holidays.child_label')|default:'Child'}",
    ageOfChild: "{__('novoton_holidays.age_of_child')|default:'Age of child'}",
    checkInPast: "{__('novoton_holidays.check_in_past')|default:'Check-in date cannot be in the past'}",
    includesOnRequest: "{__('novoton_holidays.includes_on_request')|default:'(includes on-request)'}",
    of: "{__('novoton_holidays.of')|default:'of'}",
    pleaseSelectAllRooms: "{__('novoton_holidays.please_select_all_rooms')|default:'Please select a room type for each room'}",
    remove: "{__('novoton_holidays.remove')|default:'Remove'}",
    changeSearch: "{__('novoton_holidays.change_search')|default:'Change search'}",
    searching: "{__('novoton_holidays.searching')|default:'Searching...'}",
    available: "{__('novoton_holidays.available')|default:'Available'}",
    offer: "{__('novoton_holidays.offer')|default:'offer'}",
    offers: "{__('novoton_holidays.offers')|default:'offers'}",
    pleaseEnterDates: "{__('novoton_holidays.please_enter_dates')|default:'Please select check-in and check-out dates'}",
    selectDatesMessage: "{__('novoton_holidays.select_dates_message')|default:"Select dates to see this property's availability and prices"}",
    selectCheckIn: "{__('novoton_holidays.select_check_in')|default:'Select check-in date'}",
    selectMissingAges: "{__('novoton_holidays.select_missing_ages')|default:'Select age ([count] missing)'}"
};
</script>
<script src="{$config.current_location}/js/addons/novoton_holidays/react-vendor.js?v={$smarty.const.NOVOTON_VERSION}" defer></script>
<script src="{$config.current_location}/js/addons/novoton_holidays/react19-bundle.js?v={$smarty.const.NOVOTON_VERSION}" defer></script>
<script src="{$config.current_location}/js/addons/novoton_holidays/dob-validation.js?v={$smarty.const.NOVOTON_VERSION}" defer></script>

{* A73: JavaScript fallback to fix desktop/mobile display if CSS fails *}
<script>
(function() {
    'use strict';
    
    // Run on DOM ready and after a short delay (for CSS to load)
    function fixDisplayStyles() {
        var isDesktop = window.innerWidth >= 769;
        
        // Desktop mode - show table, hide cards
        if (isDesktop) {
            // Hide mobile cards
            document.querySelectorAll('.novoton-mobile-only, .novoton-room-card.novoton-mobile-only').forEach(function(el) {
                el.style.cssText = 'display: none !important; visibility: hidden !important;';
            });
            // Show desktop table
            document.querySelectorAll('.novoton-desktop-only').forEach(function(el) {
                el.style.cssText = 'display: grid !important; visibility: visible !important;';
            });
            // Apply grid columns
            document.querySelectorAll('.novoton-table-header').forEach(function(el) {
                el.style.cssText = 'display: grid !important; grid-template-columns: 2fr 2fr 1fr 200px !important; background: #f8f9fa !important; color: #003580 !important; border-bottom: 2px solid #003580 !important;';
            });
            document.querySelectorAll('.result-row.novoton-desktop-only').forEach(function(el) {
                var bg = el.classList.contains('on-request') ? '#fff8e1' : '#fff';
                el.style.cssText = 'display: grid !important; grid-template-columns: 2fr 2fr 1fr 200px !important; border-bottom: 1px solid #e0e0e0 !important; background: ' + bg + ' !important;';
            });
        } 
        // Mobile mode - show cards, hide table
        else {
            // Show mobile cards
            document.querySelectorAll('.novoton-mobile-only, .novoton-room-card.novoton-mobile-only').forEach(function(el) {
                el.style.cssText = 'display: block !important; visibility: visible !important;';
            });
            // Hide desktop table
            document.querySelectorAll('.novoton-desktop-only, .novoton-table-header, .result-row.novoton-desktop-only').forEach(function(el) {
                el.style.cssText = 'display: none !important; visibility: hidden !important;';
            });
        }
    }
    
    // Run immediately
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fixDisplayStyles);
    } else {
        fixDisplayStyles();
    }
    
    // Run again after styles have loaded
    setTimeout(fixDisplayStyles, 100);
    setTimeout(fixDisplayStyles, 500);
    
    // Handle window resize
    var resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(fixDisplayStyles, 150);
    });
})();
</script>
