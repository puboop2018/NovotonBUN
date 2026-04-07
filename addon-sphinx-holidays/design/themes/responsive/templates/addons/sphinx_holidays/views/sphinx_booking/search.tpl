{*
 * Sphinx Hotel Search Results
 *
 * Displays offer cards returned from Sphinx API search.
 * Unlike Novoton's room×board grid, Sphinx returns pre-built offers
 * with a single price per offer.
 *
 * @package SphinxHolidays
 * @since 1.0.0
 *}

{capture name="mainbox"}

<div class="travel-search-results-page sphinx-search-results">

    {* ===== BOOKING FORM - React Component (inlined to avoid Smarty {include} OOM) ===== *}
    {* Smarty 5's {include} creates child scopes that inherit ALL parent variables.
       When $sphinx_search_results (50+ objects) is in scope, the scope chain
       traversal in Data::getVariable() exhausts 256MB. The Novoton search
       template uses the same inlined approach for this reason. *}
    <div class="travel-search-form-wrapper">
        {$_tc = $addons.travel_core|default:[]}
        <div id="travel-booking-root"
             data-travel-booking
             data-search-dispatch="sphinx_booking.search"
             data-colors='{ldelim}"primary":"{$_tc.color_primary|default:""}","accent":"{$_tc.color_accent|default:""}","text":"{$_tc.color_text|default:""}","textLight":"{$_tc.color_text_light|default:""}","bg":"{$_tc.color_bg|default:""}","border":"{$_tc.color_border|default:""}","btnBg":"{$_tc.color_search_btn_bg|default:""}","btnHover":"{$_tc.color_search_btn_hover|default:""}","btnText":"{$_tc.color_search_btn_text|default:""}","calCheapest":"{$_tc.color_cal_cheapest|default:""}","calPrice":"{$_tc.color_cal_price|default:""}","danger":"{$_tc.color_danger|default:""}"{rdelim}'
             data-provider="sphinx"
             data-hotel-id="{$sphinx_search_params.hotel_id|default:''}"
             data-product-id="{$sphinx_search_params.product_id|default:''}"
             data-debug="false"
             data-mode="search"
             data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}"
             data-check-in="{$sphinx_search_params.check_in|default:''}"
             data-check-out="{$sphinx_search_params.check_out|default:''}"
             data-adults="{$sphinx_search_params.adults|default:2}"
             data-children="{$sphinx_search_params.children|default:0}"
             data-children-ages="{$sphinx_search_params.children_ages|default:''}"
             data-rooms="{$sphinx_search_params.rooms|default:1}"
             data-rooms-data='{$sphinx_search_params.rooms_data_json|default:"[]"|escape:"html"}'
             data-translations='{ldelim}"availability":"{__("travel_core.availability")}","checkInDate":"{__("travel_core.check_in_date")}","checkOutDate":"{__("travel_core.check_out_date")}","checkIn":"{__("travel_core.check_in")}","checkOut":"{__("travel_core.check_out")}","selectDatesMessage":"{__("travel_core.select_dates_message")}","search":"{__("travel_core.search")}","changeSearch":"{__("travel_core.change_search")}","applyChanges":"{__("travel_core.apply_changes")}","adult":"{__("travel_core.adult")}","adults":"{__("travel_core.adults")}","child":"{__("travel_core.child")}","children":"{__("travel_core.children")}","rooms":"{__("travel_core.rooms")}","room":"{__("travel_core.room")}","done":"{__("travel_core.done")}","addRoom":"{__("travel_core.add_room")}","adultsLabel":"{__("travel_core.adults_label")}","childrenLabel":"{__("travel_core.children_label")}","nightsStay":"{__("travel_core.nights_stay")}","nightStay":"{__("travel_core.night_stay")}","night":"{__("travel_core.night")}","nights":"{__("travel_core.nights")}","childrenAges":"{__("travel_core.childrens_ages")}","childAge":"{__("travel_core.child_age")}","childNAge":"{__("travel_core.child_n_age")}","selectAge":"{__("travel_core.select_age")}","yearsOld":"{__("travel_core.years_old")}","yearOld":"{__("travel_core.year_old")}","selected":"{__("travel_core.selected")}","selectedSingular":"{__("travel_core.selected_singular")}","selectCheckOut":"{__("travel_core.select_check_out")}","january":"{__("travel_core.january")}","february":"{__("travel_core.february")}","march":"{__("travel_core.march")}","april":"{__("travel_core.april")}","may":"{__("travel_core.may")}","june":"{__("travel_core.june")}","july":"{__("travel_core.july")}","august":"{__("travel_core.august")}","september":"{__("travel_core.september")}","october":"{__("travel_core.october")}","november":"{__("travel_core.november")}","december":"{__("travel_core.december")}","mon":"{__("travel_core.mon")}","tue":"{__("travel_core.tue")}","wed":"{__("travel_core.wed")}","thu":"{__("travel_core.thu")}","fri":"{__("travel_core.fri")}","sat":"{__("travel_core.sat")}","sun":"{__("travel_core.sun")}","remove":"{__("travel_core.remove")}","pleaseEnterDates":"{__("travel_core.please_enter_dates")}","selectCheckIn":"{__("travel_core.select_check_in")}","selectMissingAges":"{__("travel_core.select_missing_ages")}","selectAgeForOneChild":"{__("travel_core.select_age_for_one_child")}","selectAgeForChildren":"{__("travel_core.select_age_for_children")}","calendarPriceFooter":"{__("travel_core.calendar_price_footer")|default:"Approximate prices in %s for a 1-night stay"|escape:"javascript"}"{rdelim}'>
            <div class="travel-loading-state">
                <div class="nvt-skeleton-row">
                    <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
                    <div class="nvt-skeleton-field"></div>
                    <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
                </div>
            </div>
        </div>
        {$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:'1'}
        <script src="{$config.current_location}/js/addons/travel_core/react-vendor.js?v={$cache_ver}" defer></script>
        <script src="{$config.current_location}/js/addons/travel_core/react19-bundle.js?v={$cache_ver}" defer></script>
    </div>

    {if $sphinx_search_results}
        <div class="sphinx-results-container">
            <h2 class="sphinx-results-title">
                {__("sphinx_holidays.search_results", ["[count]" => $sphinx_search_results|count])|default:"`$sphinx_search_results|count` results found"}
            </h2>

            {foreach from=$sphinx_search_results item=result name=results}
                <div class="sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                    {* Hotel info *}
                    <div class="sphinx-offer-hotel">
                        {if $result.hotel_image}
                            <img src="{$result.hotel_image}" alt="{$result.hotel_name|escape:html}" class="sphinx-offer-image" loading="lazy">
                        {/if}
                        <div class="sphinx-offer-hotel-info">
                            <h3 class="sphinx-offer-hotel-name">{$result.hotel_name|escape:html}</h3>
                            {if $result.star_rating}
                                <span class="sphinx-stars">{"★"|str_repeat:$result.star_rating}</span>
                            {/if}
                            {if $result.destination}
                                <span class="sphinx-offer-location">{$result.destination|escape:html}</span>
                            {/if}
                        </div>
                    </div>

                    {* Offer details *}
                    <div class="sphinx-offer-details">
                        <div class="sphinx-offer-room">
                            <strong>{$result.room_name|default:$result.room_type|escape:html}</strong>
                        </div>
                        <div class="sphinx-offer-board">
                            {$result.board_name|default:$result.board_type|escape:html}
                        </div>
                        <div class="sphinx-offer-dates">
                            {$sphinx_search_params.check_in|date_format:"%d.%m.%Y"} - {$sphinx_search_params.check_out|date_format:"%d.%m.%Y"}
                            ({$sphinx_search_params.nights} {__("travel_core.nights")|default:"nights"})
                        </div>
                    </div>

                    {* Price and action *}
                    <div class="sphinx-offer-price-action">
                        <div class="sphinx-offer-price">
                            <span class="sphinx-price-amount">{$result.price|number_format:2:",":"."}</span>
                            <span class="sphinx-price-currency">{$sphinx_search_params.currency|default:'EUR'}</span>
                        </div>
                        <a href="{"sphinx_booking.booking_form?offer_id=`$result.offer_id`&hotel_id=`$result.hotel_id`&product_id=`$result.product_id`&check_in=`$sphinx_search_params.check_in`&check_out=`$sphinx_search_params.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`"|fn_url}"
                           class="sphinx-offer-book-btn">
                            {__("sphinx_holidays.book_now")|default:"Book now"}
                        </a>
                    </div>

                </div>
            {/foreach}
        </div>

    {else}
        <div class="sphinx-no-results">
            <p>{__("sphinx_holidays.no_results")|default:"No hotels found for your search criteria. Please try different dates or destination."}</p>

            {if $sphinx_alternative_dates}
                <div class="sphinx-alternative-dates" style="margin-top: 20px; padding: 15px; background: #f5f9fc; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px; color: #003580;">{__("sphinx_holidays.alternative_dates_title")|default:"Availability found on nearby dates:"}</h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        {foreach $sphinx_alternative_dates as $alt}
                            <a href="{"sphinx_booking.search?hotel_id=`$sphinx_search_params.hotel_id`&destination_id=`$sphinx_search_params.destination_id`&check_in=`$alt.check_in`&check_out=`$alt.check_out`&adults=`$sphinx_search_params.adults`&children=`$sphinx_search_params.children`&children_ages=`$sphinx_search_params.children_ages`&rooms=`$sphinx_search_params.rooms`"|fn_url}"
                               class="sphinx-alt-date-link" style="display: inline-block; padding: 8px 16px; background: #fff; border: 1px solid #c5d5ea; border-radius: 6px; text-decoration: none; color: #003580;">
                                <strong>{$alt.check_in|date_format:"%d.%m.%Y"}</strong> &ndash; {$alt.check_out|date_format:"%d.%m.%Y"}
                                <span style="font-size: 12px; color: #666;">({$alt.count} {__("sphinx_holidays.results_found")|default:"results"})</span>
                            </a>
                        {/foreach}
                    </div>
                </div>
            {/if}
        </div>
    {/if}

    {* Debug info *}
    {if $sphinx_debug}
        <div class="sphinx-debug" style="margin-top: 20px; padding: 10px; background: #f0f0f0; font-size: 12px;">
            <strong>Debug:</strong>
            search_id={$sphinx_debug.search_id},
            polls={$sphinx_debug.poll_count},
            results={$sphinx_debug.result_count}
        </div>
    {/if}

</div>

{/capture}

{include file="common/mainbox.tpl" title=__("sphinx_holidays.search_results_title", ["[default]" => "Hotel Search Results"]) content=$smarty.capture.mainbox}
