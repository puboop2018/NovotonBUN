{*
 * Sphinx Package Search Results
 *
 * Displays package offer cards with hotel, flight/bus, and pricing info.
 *
 * @package SphinxHolidays
 * @since 1.2.0
 *}



<div class="travel-search-results-page sphinx-package-results">

    {if $sphinx_package_results}
        <div class="sphinx-results-container">
            <h2 class="sphinx-results-title">
                {__("sphinx_holidays.packages_found", ["[count]" => $sphinx_package_results|count])|default:"`$sphinx_package_results|count` packages found"}
            </h2>

            {foreach from=$sphinx_package_results item=result name=results}
                {assign var="hotel" value=$result.hotel}
                {assign var="pricing" value=$result.pricing}
                <div class="sphinx-offer-card" data-offer-id="{$result.offer_id|default:''}">

                    {* Hotel info *}
                    <div class="sphinx-offer-hotel">
                        <div class="sphinx-offer-hotel-info">
                            <h3 class="sphinx-offer-hotel-name">{$hotel.name|escape:html}</h3>
                            {if $result.destination_name}
                                <span class="sphinx-offer-location">{$result.destination_name|escape:html}</span>
                            {/if}
                        </div>
                    </div>

                    {* Package details *}
                    <div class="sphinx-offer-details">
                        {* Hotel dates *}
                        <div class="sphinx-offer-dates">
                            {$hotel.check_in|date_format:"%d.%m.%Y"} - {$hotel.check_out|date_format:"%d.%m.%Y"}
                            ({$sphinx_package_params.nights} {__("travel_core.nights")|default:"nights"})
                        </div>

                        {* Room & meal *}
                        {if $hotel.rooms}
                            <div class="sphinx-offer-room">
                                {foreach $hotel.rooms as $room}
                                    <span>{$room.name|escape:html}</span>{if !$room@last}, {/if}
                                {/foreach}
                            </div>
                        {/if}
                        {if $hotel.meal_type_name}
                            <div class="sphinx-offer-board">{$hotel.meal_type_name|escape:html}</div>
                        {/if}

                        {* Transport *}
                        {if $result.flight && $result.flight.outbound}
                            <div class="sphinx-offer-transport" style="margin-top: 5px; font-size: 13px; color: #555;">
                                <i class="icon-plane"></i>
                                {foreach $result.flight.outbound as $segment}
                                    {$segment.departure.code} &rarr; {$segment.arrival.code}
                                    {if $segment.airline.name}({$segment.airline.name|escape:html}){/if}
                                {/foreach}
                            </div>
                        {elseif $result.bus}
                            <div class="sphinx-offer-transport" style="margin-top: 5px; font-size: 13px; color: #555;">
                                <i class="icon-truck"></i> {__("sphinx_holidays.bus_transport")|default:"Bus transport"}
                            </div>
                        {/if}

                        {* Labels *}
                        {if $result.labels}
                            <div style="margin-top: 5px;">
                                {foreach $result.labels as $label}
                                    <span style="display: inline-block; padding: 2px 8px; background: #e8f5e9; color: #2e7d32; border-radius: 4px; font-size: 12px;">{$label.name|escape:html}</span>
                                {/foreach}
                            </div>
                        {/if}
                    </div>

                    {* Price and action *}
                    <div class="sphinx-offer-price-action">
                        <div class="sphinx-offer-price">
                            {if $pricing.discount > 0}
                                <span style="text-decoration: line-through; color: #999; font-size: 14px;">{$pricing.marketing_price|number_format:2:",":"."}</span>
                            {/if}
                            <span class="sphinx-price-amount">{$pricing.selling_price|number_format:2:",":"."}</span>
                            <span class="sphinx-price-currency">{$pricing.currency|default:'EUR'}</span>
                            {if $sphinx_package_params.nights > 0}
                                <span class="sphinx-price-per-night">
                                    ({($pricing.selling_price / $sphinx_package_params.nights)|number_format:2:",":"."} / {__("sphinx_holidays.per_night")|default:"night"})
                                </span>
                            {/if}
                        </div>
                        <a href="{"sphinx_booking.package_booking_form?offer_id=`$result.offer_id`&adults=`$sphinx_package_params.adults`&children=`$sphinx_package_params.children`&children_ages=`$sphinx_package_params.children_ages`&rooms=`$sphinx_package_params.rooms`"|fn_url}"
                           class="sphinx-offer-book-btn">
                            {if $result.must_verify}
                                {__("sphinx_holidays.check_availability")|default:"Check Availability"}
                            {else}
                                {__("sphinx_holidays.book_now")|default:"Book now"}
                            {/if}
                        </a>
                    </div>

                </div>
            {/foreach}
        </div>

    {else}
        <div class="sphinx-no-results">
            <p>{__("sphinx_holidays.no_packages_found")|default:"No packages found for your search criteria. Please try different dates or destination."}</p>
        </div>
    {/if}

</div>


