{*
 * Sphinx Circuit Search Results
 *
 * Displays circuit rate cards from the Sphinx API rates endpoint.
 * Circuits show: title, departure date, transport type, duration, price.
 *
 * @package SphinxHolidays
 * @since 1.1.0
 *}



<div class="travel-search-results-page sphinx-circuit-results">

    {if $sphinx_circuit_results}
        <div class="sphinx-results-container">
            <h2 class="sphinx-results-title">
                {__("sphinx_holidays.circuits_found", ["[count]" => $sphinx_circuit_results|count])|default:"`$sphinx_circuit_results|count` circuits found"}
            </h2>

            {foreach from=$sphinx_circuit_results item=circuit name=circuits}
                <div class="sphinx-offer-card sphinx-circuit-card" data-circuit-id="{$circuit.circuit_id}">

                    {* Circuit image *}
                    <div class="sphinx-offer-hotel">
                        {if $circuit.image}
                            <img src="{$circuit.image}" alt="{$circuit.title|escape:html}" class="sphinx-offer-image" loading="lazy">
                        {/if}
                        <div class="sphinx-offer-hotel-info">
                            <h3 class="sphinx-offer-hotel-name">{$circuit.title|escape:html}</h3>
                            {if $circuit.transport_type}
                                <span class="sphinx-circuit-transport">{$circuit.transport_type|capitalize|escape:html}</span>
                            {/if}
                            {if $circuit.destinations}
                                <span class="sphinx-offer-location">
                                    {foreach $circuit.destinations as $dest name=dests}
                                        {$dest.name|escape:html}{if !$smarty.foreach.dests.last}, {/if}
                                    {/foreach}
                                </span>
                            {/if}
                        </div>
                    </div>

                    {* Circuit details *}
                    <div class="sphinx-offer-details">
                        <div class="sphinx-offer-room">
                            <strong>{$circuit.duration.days} {__("travel_core.days")|default:"days"} / {$circuit.duration.nights} {__("travel_core.nights")|default:"nights"}</strong>
                        </div>
                        <div class="sphinx-offer-dates">
                            {$circuit.departure_date|date_format:"%d.%m.%Y"}
                        </div>
                        {if $circuit.departures}
                            <div class="sphinx-circuit-departure" style="font-size: 13px; color: #666;">
                                {__("sphinx_holidays.departure_from")|default:"Departure from"}: {$circuit.departures[0].name|escape:html}
                            </div>
                        {/if}
                        {if $circuit.summary}
                            <div class="sphinx-circuit-summary" style="font-size: 13px; color: #555; margin-top: 5px;">
                                {$circuit.summary|truncate:150:"..."|escape:html}
                            </div>
                        {/if}
                    </div>

                    {* Price and action *}
                    <div class="sphinx-offer-price-action">
                        <div class="sphinx-offer-price">
                            <span class="sphinx-price-amount">{$circuit.pricing.selling_price|number_format:2:",":"."}</span>
                            <span class="sphinx-price-currency">{$circuit.pricing.currency|default:$sphinx_circuit_params.currency|default:'EUR'}</span>
                            {if $circuit.pricing.discount > 0}
                                <div style="font-size: 12px; color: #d32f2f; text-decoration: line-through;">
                                    {$circuit.pricing.marketing_price|number_format:2:",":"."} {$circuit.pricing.currency|default:'EUR'}
                                </div>
                            {/if}
                        </div>
                        <a href="{"sphinx_booking.circuit_booking_form?circuit_id=`$circuit.circuit_id`&departure_date=`$circuit.departure_date`&departure_id=`$circuit.departures[0].id`"|fn_url}"
                           class="sphinx-offer-book-btn">
                            {__("sphinx_holidays.get_quote")|default:"Get Quote"}
                        </a>
                    </div>

                </div>
            {/foreach}

            {* Pagination *}
            {if $sphinx_circuit_meta.last_page > 1}
                <div class="sphinx-pagination" style="margin-top: 20px; text-align: center;">
                    {for $p=1 to $sphinx_circuit_meta.last_page}
                        {if $p == $sphinx_circuit_params.page}
                            <strong style="padding: 5px 10px;">{$p}</strong>
                        {else}
                            <a href="{"sphinx_booking.circuit_search?destination_id=`$sphinx_circuit_params.destination_id`&transport_type=`$sphinx_circuit_params.transport_type`&month=`$sphinx_circuit_params.month`&page=`$p`"|fn_url}"
                               style="padding: 5px 10px; text-decoration: none; color: #003580;">{$p}</a>
                        {/if}
                    {/for}
                </div>
            {/if}
        </div>

    {else}
        <div class="sphinx-no-results">
            <p>{__("sphinx_holidays.no_circuits_found")|default:"No circuits found. Please try different filters."}</p>
        </div>
    {/if}

</div>


