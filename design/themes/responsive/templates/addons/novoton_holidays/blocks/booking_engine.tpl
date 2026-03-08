{* Novoton Booking Engine - React 19 Version v3.6
 * Booking.com style with minimalist design
 *}
{style src="css/addons/novoton_holidays/styles.css"}

{$novoton_debug = false}

{* Get product_id from context - try multiple sources *}
{if $product.product_id}
    {$current_product_id = $product.product_id}
{elseif $product_id}
    {$current_product_id = $product_id}
{elseif $smarty.request.product_id}
    {$current_product_id = $smarty.request.product_id}
{else}
    {$current_product_id = ''}
{/if}

{* Get hotel_id - try direct variable first, then from product data *}
{if $hotel_id}
    {$current_hotel_id = $hotel_id}
{elseif $product.hotel_id}
    {$current_hotel_id = $product.hotel_id}
{else}
    {$current_hotel_id = ''}
{/if}

{* Container for React component *}
<div id="novoton-booking-root" 
     data-novoton-booking
     data-hotel-id="{$current_hotel_id|default:''}" 
     data-product-id="{$current_product_id|default:''}"
     data-debug="{if $novoton_debug}true{else}false{/if}"
     data-mode="product"
     data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}"
     {if $show_calendar_prices == 'Y' && $calendar_prices_json != '{}'}data-calendar-prices='{$calendar_prices_json nofilter}'
     data-calendar-prices-currency="{$calendar_prices_currency|escape:'html'}"
     {/if}
     data-translations='{ldelim}"availability":"{__("novoton_holidays.availability")}","checkInDate":"{__("novoton_holidays.check_in_date")}","checkOutDate":"{__("novoton_holidays.check_out_date")}","checkIn":"{__("novoton_holidays.check_in")}","checkOut":"{__("novoton_holidays.check_out")}","selectDatesMessage":"{__("novoton_holidays.select_dates_message")}","search":"{__("novoton_holidays.search")}","changeSearch":"{__("novoton_holidays.change_search")}","applyChanges":"{__("novoton_holidays.apply_changes")}","adult":"{__("novoton_holidays.adult")}","adults":"{__("novoton_holidays.adults")}","child":"{__("novoton_holidays.child")}","children":"{__("novoton_holidays.children")}","rooms":"{__("novoton_holidays.rooms")}","room":"{__("novoton_holidays.room")}","done":"{__("novoton_holidays.done")}","addRoom":"{__("novoton_holidays.add_room")}","adultsLabel":"{__("novoton_holidays.adults_label")}","childrenLabel":"{__("novoton_holidays.children_label")}","nightsStay":"{__("novoton_holidays.nights_stay")}","nightStay":"{__("novoton_holidays.night_stay")}","night":"{__("novoton_holidays.night")}","nights":"{__("novoton_holidays.nights")}","childrenAges":"{__("novoton_holidays.childrens_ages")}","childAge":"{__("novoton_holidays.child_age")}","selectAge":"{__("novoton_holidays.select_age")}","yearsOld":"{__("novoton_holidays.years_old")}","yearOld":"{__("novoton_holidays.year_old")}","selected":"{__("novoton_holidays.selected")}","selectedSingular":"{__("novoton_holidays.selected_singular")}","selectCheckOut":"{__("novoton_holidays.select_check_out")}","january":"{__("novoton_holidays.january")}","february":"{__("novoton_holidays.february")}","march":"{__("novoton_holidays.march")}","april":"{__("novoton_holidays.april")}","may":"{__("novoton_holidays.may")}","june":"{__("novoton_holidays.june")}","july":"{__("novoton_holidays.july")}","august":"{__("novoton_holidays.august")}","september":"{__("novoton_holidays.september")}","october":"{__("novoton_holidays.october")}","november":"{__("novoton_holidays.november")}","december":"{__("novoton_holidays.december")}","mon":"{__("novoton_holidays.mon")}","tue":"{__("novoton_holidays.tue")}","wed":"{__("novoton_holidays.wed")}","thu":"{__("novoton_holidays.thu")}","fri":"{__("novoton_holidays.fri")}","sat":"{__("novoton_holidays.sat")}","sun":"{__("novoton_holidays.sun")}","remove":"{__("novoton_holidays.remove")}","changeSearch":"{__("novoton_holidays.change_search")}","applyChanges":"{__("novoton_holidays.apply_changes")}","pleaseEnterDates":"{__("novoton_holidays.please_enter_dates")}","selectCheckIn":"{__("novoton_holidays.select_check_in")}","selectMissingAges":"{__("novoton_holidays.select_missing_ages")}","selectAgeForOneChild":"{__("novoton_holidays.select_age_for_one_child")}","selectAgeForChildren":"{__("novoton_holidays.select_age_for_children")}","calendarPriceFooter":"{__("novoton_holidays.calendar_price_footer")|default:"Approximate prices in %s for a 1-night stay"|escape:"javascript"}"{rdelim}'>
    <div id="novoton-loading" class="novoton-loading-state">
        <span>Loading booking form...</span>
    </div>
</div>

{* Load React 19 vendor (cached separately) then app bundle *}
<script src="{$config.current_location}/js/addons/novoton_holidays/react-vendor.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
<script src="{$config.current_location}/js/addons/novoton_holidays/react19-bundle.js?v={$smarty.const.NOVOTON_CACHE_VER}" defer></script>
