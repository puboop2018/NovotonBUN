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

    {* ===== BOOKING FORM — Pre-rendered in controller to prevent OOM ===== *}
    {* The booking engine HTML is rendered to a string in the search controller
       BEFORE $sphinx_search_results is assigned to the view. This prevents
       Smarty 5's scope chain traversal from carrying 50+ result objects into
       the booking engine template (which causes 256MB OOM at Data.php:265).
       Smarty has NO scope="local" — {include} always inherits parent scope. *}
    <div class="travel-search-form-wrapper">
        {$booking_engine_html nofilter}
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
