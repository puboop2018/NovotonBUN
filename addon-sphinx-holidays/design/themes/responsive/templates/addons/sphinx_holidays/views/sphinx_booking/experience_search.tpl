{*
 * Sphinx Experience Search Results
 *
 * Displays experience rate cards from the Sphinx API rates endpoint.
 * Experiences show: title, departure dates, duration, pickup points, price.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}



<div class="travel-search-results-page sphinx-experience-results">

    {if $sphinx_experience_results}
        <div class="sphinx-results-container">
            <h2 class="sphinx-results-title">
                {__("sphinx_holidays.experiences_found", ["[count]" => $sphinx_experience_results|count])|default:"`$sphinx_experience_results|count` experiences found"}
            </h2>

            {foreach from=$sphinx_experience_results item=experience name=experiences}
                <div class="sphinx-offer-card sphinx-experience-card" data-experience-id="{$experience.experience_id}">

                    {* Experience image *}
                    <div class="sphinx-offer-hotel">
                        {if $experience.image}
                            <img src="{$experience.image}" alt="{$experience.title|escape:html}" class="sphinx-offer-image" loading="lazy">
                        {/if}
                        <div class="sphinx-offer-hotel-info">
                            <h3 class="sphinx-offer-hotel-name">{$experience.title|escape:html}</h3>
                            {if $experience.destinations}
                                <span class="sphinx-offer-location">
                                    {foreach $experience.destinations as $dest name=dests}
                                        {$dest.name|escape:html}{if !$smarty.foreach.dests.last}, {/if}
                                    {/foreach}
                                </span>
                            {/if}
                        </div>
                    </div>

                    {* Experience details *}
                    <div class="sphinx-offer-details">
                        {if $experience.duration}
                            <div class="sphinx-offer-room">
                                <strong>
                                    {if $experience.duration.description}
                                        {$experience.duration.description|escape:html}
                                    {elseif $experience.duration.days > 0}
                                        {$experience.duration.days} {__("travel_core.days")|default:"days"}
                                    {elseif $experience.duration.minutes > 0}
                                        {($experience.duration.minutes / 60)|number_format:1} {__("sphinx_holidays.hours")|default:"hours"}
                                    {/if}
                                </strong>
                            </div>
                        {/if}
                        {if $experience.summary}
                            <div style="font-size: 13px; color: #555; margin-top: 5px;">
                                {$experience.summary|truncate:150:"..."|escape:html}
                            </div>
                        {/if}
                        {if $experience.departure_dates}
                            <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                {__("sphinx_holidays.next_dates")|default:"Next dates"}:
                                {foreach $experience.departure_dates as $dd name=dates}
                                    {if $smarty.foreach.dates.index < 3}
                                        {$dd|date_format:"%d.%m"}{if !$smarty.foreach.dates.last && $smarty.foreach.dates.index < 2}, {/if}
                                    {/if}
                                {/foreach}
                                {if count($experience.departure_dates) > 3}...{/if}
                            </div>
                        {/if}
                    </div>

                    {* Price and action *}
                    <div class="sphinx-offer-price-action">
                        <div class="sphinx-offer-price">
                            <span style="font-size: 12px; color: #666;">{__("sphinx_holidays.from")|default:"from"}</span>
                            <span class="sphinx-price-amount">{$experience.pricing.selling_price|number_format:2:",":"."}</span>
                            <span class="sphinx-price-currency">{$experience.pricing.currency|default:$sphinx_experience_params.currency|default:'EUR'}</span>
                        </div>
                        <a href="{"sphinx_booking.experience_booking_form?experience_id=`$experience.experience_id`&departure_date=`$experience.departure_date`"|fn_url}"
                           class="sphinx-offer-book-btn">
                            {__("sphinx_holidays.get_quote")|default:"Get Quote"}
                        </a>
                    </div>

                </div>
            {/foreach}

            {* Pagination *}
            {if $sphinx_experience_meta.last_page > 1}
                <div class="sphinx-pagination" style="margin-top: 20px; text-align: center;">
                    {for $p=1 to $sphinx_experience_meta.last_page}
                        {if $p == $sphinx_experience_params.page}
                            <strong style="padding: 5px 10px;">{$p}</strong>
                        {else}
                            <a href="{"sphinx_booking.experience_search?destination_id=`$sphinx_experience_params.destination_id`&month=`$sphinx_experience_params.month`&page=`$p`"|fn_url}"
                               style="padding: 5px 10px; text-decoration: none; color: #003580;">{$p}</a>
                        {/if}
                    {/for}
                </div>
            {/if}
        </div>

    {else}
        <div class="sphinx-no-results">
            <p>{__("sphinx_holidays.no_experiences_found")|default:"No experiences found. Please try different filters."}</p>
        </div>
    {/if}

</div>


