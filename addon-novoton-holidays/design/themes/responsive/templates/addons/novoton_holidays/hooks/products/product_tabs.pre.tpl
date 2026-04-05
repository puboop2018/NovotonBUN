{*
 * Hook: products:product_tabs
 * Injects Novoton booking form before product tabs on hotel product pages.
 *
 * SMARTY 5 COMPATIBILITY: This template inlines the booking engine HTML
 * instead of using {include}. Nested {include} on CS-Cart product detail
 * pages creates a Smarty scope chain deep enough to exhaust 256 MB memory
 * in Data::getVariable() (Data.php:265). Inlining avoids scope depth.
 *}

{if $product.product_code|substr:0:3 == 'NVT'}

{* Extract hotel ID from product code *}
{$_nvt_hid = $product.product_code|substr:3}

{* ── Novoton Booking Engine (inlined to avoid Smarty scope chain crash) ── *}

{* Container for React component *}
<div id="travel-booking-root"
     data-travel-booking
     data-search-dispatch="novoton_booking.search"
     data-provider="novoton"
     data-hotel-id="{$_nvt_hid}"
     data-product-id="{$product.product_id}"
     data-debug="false"
     data-mode="product"
     data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}"
     data-translations='{ldelim}"availability":"{__("travel_core.availability")}","checkInDate":"{__("travel_core.check_in_date")}","checkOutDate":"{__("travel_core.check_out_date")}","checkIn":"{__("travel_core.check_in")}","checkOut":"{__("travel_core.check_out")}","selectDatesMessage":"{__("travel_core.select_dates_message")}","search":"{__("travel_core.search")}","changeSearch":"{__("travel_core.change_search")}","applyChanges":"{__("travel_core.apply_changes")}","adult":"{__("travel_core.adult")}","adults":"{__("travel_core.adults")}","child":"{__("travel_core.child")}","children":"{__("travel_core.children")}","rooms":"{__("travel_core.rooms")}","room":"{__("travel_core.room")}","done":"{__("travel_core.done")}","addRoom":"{__("travel_core.add_room")}","adultsLabel":"{__("travel_core.adults_label")}","childrenLabel":"{__("travel_core.children_label")}","nightsStay":"{__("travel_core.nights_stay")}","nightStay":"{__("travel_core.night_stay")}","night":"{__("travel_core.night")}","nights":"{__("travel_core.nights")}","childrenAges":"{__("travel_core.childrens_ages")}","childAge":"{__("travel_core.child_age")}","selectAge":"{__("travel_core.select_age")}","yearsOld":"{__("travel_core.years_old")}","yearOld":"{__("travel_core.year_old")}","selected":"{__("travel_core.selected")}","selectedSingular":"{__("travel_core.selected_singular")}","selectCheckOut":"{__("travel_core.select_check_out")}","january":"{__("travel_core.january")}","february":"{__("travel_core.february")}","march":"{__("travel_core.march")}","april":"{__("travel_core.april")}","may":"{__("travel_core.may")}","june":"{__("travel_core.june")}","july":"{__("travel_core.july")}","august":"{__("travel_core.august")}","september":"{__("travel_core.september")}","october":"{__("travel_core.october")}","november":"{__("travel_core.november")}","december":"{__("travel_core.december")}","mon":"{__("travel_core.mon")}","tue":"{__("travel_core.tue")}","wed":"{__("travel_core.wed")}","thu":"{__("travel_core.thu")}","fri":"{__("travel_core.fri")}","sat":"{__("travel_core.sat")}","sun":"{__("travel_core.sun")}","remove":"{__("travel_core.remove")}","pleaseEnterDates":"{__("travel_core.please_enter_dates")}","selectCheckIn":"{__("travel_core.select_check_in")}","selectMissingAges":"{__("travel_core.select_missing_ages")}","selectAgeForOneChild":"{__("travel_core.select_age_for_one_child")}","selectAgeForChildren":"{__("travel_core.select_age_for_children")}","calendarPriceFooter":"{__("travel_core.calendar_price_footer")|default:"Approximate prices in %s for a 1-night stay"|escape:"javascript"}"{rdelim}'>
    <div class="travel-loading-state">
        <div class="nvt-skeleton-row">
            <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
            <div class="nvt-skeleton-field"></div>
            <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
        </div>
    </div>
</div>

{* Load React booking engine *}
{$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:'1'}
<script src="{$config.current_location}/js/addons/addon-travel-core/react-vendor.js?v={$cache_ver}" defer></script>
<script src="{$config.current_location}/js/addons/addon-travel-core/react19-bundle.js?v={$cache_ver}" defer></script>

{/if}
