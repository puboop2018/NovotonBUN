{*
 * Hook: checkout:products_list
 * "No Surprises" Price Alert Banner
 *
 * Displayed above the Order Summary when a price change was detected
 * during add_to_cart or pre_place_order. Stays visible until the user
 * clicks "Accept" or "Place Order" again.
 *
 * UX rules:
 *   - Price Increase: orange badge, "Old vs New" comparison
 *   - Price Decrease: green badge, "Price Dropped!" message
 *   - Small changes below tolerance threshold: not shown
 *}

{if !empty($novoton_price_change_alerts)}
    {foreach from=$novoton_price_change_alerts item=alert key=alert_key}
        {if $alert.significant}
            <div class="nvt-price-alert nvt-price-alert--{$alert.badge_type}" id="nvt_price_alert_{$alert_key}">
                <div class="nvt-price-alert__icon">
                    {if $alert.direction == 'increase'}
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    {else}
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    {/if}
                </div>
                <div class="nvt-price-alert__content">
                    {if $alert.direction == 'increase'}
                        <div class="nvt-price-alert__badge nvt-price-alert__badge--warning">
                            {__("novoton_holidays.price_updated_badge")|default:"Price Updated"}
                        </div>
                        <div class="nvt-price-alert__message">
                            {__("novoton_holidays.price_updated_explanation", [
                                '[old_price]' => $alert.old_price|fn_format_price,
                                '[new_price]' => $alert.new_price|fn_format_price
                            ])|default:"The current rate has been updated based on real-time availability."}
                        </div>
                        <div class="nvt-price-alert__comparison">
                            <span class="nvt-price-alert__old-price">{$alert.old_price|fn_format_price} {$alert.currency}</span>
                            <span class="nvt-price-alert__arrow">&rarr;</span>
                            <span class="nvt-price-alert__new-price">{$alert.new_price|fn_format_price} {$alert.currency}</span>
                        </div>
                    {else}
                        <div class="nvt-price-alert__badge nvt-price-alert__badge--success">
                            {__("novoton_holidays.price_dropped_badge")|default:"Price Dropped!"}
                        </div>
                        <div class="nvt-price-alert__message">
                            {__("novoton_holidays.price_dropped_explanation", [
                                '[new_price]' => $alert.new_price|fn_format_price
                            ])|default:"Good news! The price has decreased."}
                        </div>
                        <div class="nvt-price-alert__comparison">
                            <span class="nvt-price-alert__new-price nvt-price-alert__new-price--success">{$alert.new_price|fn_format_price} {$alert.currency}</span>
                        </div>
                    {/if}
                </div>
                <button type="button" class="nvt-price-alert__dismiss" onclick="document.getElementById('nvt_price_alert_{$alert_key}').style.display='none';" aria-label="Dismiss">&times;</button>
            </div>
        {/if}
    {/foreach}
{/if}
