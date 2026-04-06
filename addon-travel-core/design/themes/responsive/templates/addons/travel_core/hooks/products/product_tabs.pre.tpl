{*
 * Travel Core: Booking Form (React mount point) — before product tabs.
 *
 * Variables assigned by dispatch_before_display PHP hook (cart_hooks.php):
 *   $travel_booking_product_id   - product ID (only for hotel products)
 *   $travel_booking_scripts      - array of React JS bundle URLs
 *
 * This is the PRIMARY booking form injection point for hotel product pages.
 *}

<!-- [travel_core] product_tabs.pre.tpl LOADED -->

{if $travel_booking_product_id}
<div id="travel-booking-root"
     data-travel-booking
     data-product-id="{$travel_booking_product_id}"
     style="margin-bottom: 20px;">
    <div class="travel-loading-state">
        <div class="nvt-skeleton-row">
            <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
            <div class="nvt-skeleton-field"></div>
            <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
        </div>
    </div>
</div>
{if $travel_booking_scripts}
    {foreach $travel_booking_scripts as $script_url}
    <script src="{$script_url}" defer></script>
    {/foreach}
{/if}
{/if}
