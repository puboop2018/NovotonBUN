{*
 * Travel Core - Shared Booking Engine React Mount Point
 *
 * Usage from provider addon:
 *   {$travel_provider = 'sphinx'}
 *   {$travel_search_dispatch = 'sphinx_booking.search'}
 *   {include file="addons/travel_core/blocks/booking_engine.tpl"}
 *
 * For search results page (pre-fills form with previous search params):
 *   {$travel_mode = 'search'}
 *   {$travel_search_params = $novoton_params}  {* or $sphinx_search_params *}
 *   {include file="addons/travel_core/blocks/booking_engine.tpl"}
 *
 * Variables set by provider wrapper:
 *   $travel_provider         - 'novoton' or 'sphinx'
 *   $travel_search_dispatch  - e.g. 'novoton_booking.search' or 'sphinx_booking.search'
 *   $travel_mode             - 'product' (default) or 'search'
 *   $travel_search_params    - Search params for pre-filling (search mode only)
 *   $current_hotel_id        - Hotel ID
 *   $current_product_id      - CS-Cart product ID
 *   $calendar_prices_json    - Optional JSON with per-day prices
 *   $calendar_prices_currency - Currency code for calendar prices
 *
 * @package TravelCore
 * @since 1.0.0
 *}

{* Provider must be set by the including template — no provider-specific defaults *}
{if !$travel_provider}{$travel_provider = ''}{/if}
{if !$travel_search_dispatch}{$travel_search_dispatch = ''}{/if}
{if !$travel_mode}{$travel_mode = 'product'}{/if}

{* Get product_id from context - try multiple sources *}
{if !$current_product_id}
    {if $travel_search_params.product_id}
        {$current_product_id = $travel_search_params.product_id}
    {elseif $product.product_id}
        {$current_product_id = $product.product_id}
    {elseif $product_id}
        {$current_product_id = $product_id}
    {elseif $smarty.request.product_id}
        {$current_product_id = $smarty.request.product_id}
    {else}
        {$current_product_id = ''}
    {/if}
{/if}

{* Get hotel_id - try direct variable first, then from product data *}
{if !$current_hotel_id}
    {if $travel_search_params.hotel_id}
        {$current_hotel_id = $travel_search_params.hotel_id}
    {elseif $hotel_id}
        {$current_hotel_id = $hotel_id}
    {elseif $product.hotel_id}
        {$current_hotel_id = $product.hotel_id}
    {else}
        {$current_hotel_id = ''}
    {/if}
{/if}

{* Container for React component *}
<div id="travel-booking-root"
     data-travel-booking
     data-search-dispatch="{$travel_search_dispatch}"
     data-provider="{$travel_provider}"
     data-hotel-id="{$current_hotel_id|default:''}"
     data-product-id="{$current_product_id|default:''}"
     data-debug="false"
     data-mode="{$travel_mode}"
     data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}"
     {if $travel_mode == 'search' && $travel_search_params}
     data-check-in="{$travel_search_params.check_in|default:''}"
     data-check-out="{$travel_search_params.check_out|default:''}"
     data-adults="{$travel_search_params.adults|default:2}"
     data-children="{$travel_search_params.children_count|default:$travel_search_params.children|default:0}"
     data-children-ages="{$travel_search_params.children_ages|default:''}"
     data-rooms="{$travel_search_params.num_rooms|default:$travel_search_params.rooms|default:1}"
     data-rooms-data='{$travel_search_params.rooms_data_json|default:"[]"|escape:"html"}'
     {/if}
     {if $show_calendar_prices == 'Y' && $calendar_prices_json != '{}'}data-calendar-prices='{$calendar_prices_json nofilter}'
     data-calendar-prices-currency="{$calendar_prices_currency|escape:'html'}"
     {/if}
     data-translations='{ldelim}"availability":"{__("travel_core.availability")}","checkInDate":"{__("travel_core.check_in_date")}","checkOutDate":"{__("travel_core.check_out_date")}","checkIn":"{__("travel_core.check_in")}","checkOut":"{__("travel_core.check_out")}","selectDatesMessage":"{__("travel_core.select_dates_message")}","search":"{__("travel_core.search")}","changeSearch":"{__("travel_core.change_search")}","applyChanges":"{__("travel_core.apply_changes")}","adult":"{__("travel_core.adult")}","adults":"{__("travel_core.adults")}","child":"{__("travel_core.child")}","children":"{__("travel_core.children")}","rooms":"{__("travel_core.rooms")}","room":"{__("travel_core.room")}","done":"{__("travel_core.done")}","addRoom":"{__("travel_core.add_room")}","adultsLabel":"{__("travel_core.adults_label")}","childrenLabel":"{__("travel_core.children_label")}","nightsStay":"{__("travel_core.nights_stay")}","nightStay":"{__("travel_core.night_stay")}","night":"{__("travel_core.night")}","nights":"{__("travel_core.nights")}","childrenAges":"{__("travel_core.childrens_ages")}","childAge":"{__("travel_core.child_age")}","selectAge":"{__("travel_core.select_age")}","yearsOld":"{__("travel_core.years_old")}","yearOld":"{__("travel_core.year_old")}","selected":"{__("travel_core.selected")}","selectedSingular":"{__("travel_core.selected_singular")}","selectCheckOut":"{__("travel_core.select_check_out")}","january":"{__("travel_core.january")}","february":"{__("travel_core.february")}","march":"{__("travel_core.march")}","april":"{__("travel_core.april")}","may":"{__("travel_core.may")}","june":"{__("travel_core.june")}","july":"{__("travel_core.july")}","august":"{__("travel_core.august")}","september":"{__("travel_core.september")}","october":"{__("travel_core.october")}","november":"{__("travel_core.november")}","december":"{__("travel_core.december")}","mon":"{__("travel_core.mon")}","tue":"{__("travel_core.tue")}","wed":"{__("travel_core.wed")}","thu":"{__("travel_core.thu")}","fri":"{__("travel_core.fri")}","sat":"{__("travel_core.sat")}","sun":"{__("travel_core.sun")}","remove":"{__("travel_core.remove")}","pleaseEnterDates":"{__("travel_core.please_enter_dates")}","selectCheckIn":"{__("travel_core.select_check_in")}","selectMissingAges":"{__("travel_core.select_missing_ages")}","selectAgeForOneChild":"{__("travel_core.select_age_for_one_child")}","selectAgeForChildren":"{__("travel_core.select_age_for_children")}","calendarPriceFooter":"{__("travel_core.calendar_price_footer")|default:"Approximate prices in %s for a 1-night stay"|escape:"javascript"}"{rdelim}'>
    <div class="travel-loading-state">
        <span>{__("travel_core.loading_booking_form")|default:"Loading booking form..."}</span>
    </div>
</div>

{* Load React 19 vendor (cached separately) then app bundle *}
{$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:'1'}
<script src="{$config.current_location}/js/addons/addon-travel-core/react-vendor.js?v={$cache_ver}" defer></script>
<script src="{$config.current_location}/js/addons/addon-travel-core/react19-bundle.js?v={$cache_ver}" defer></script>
