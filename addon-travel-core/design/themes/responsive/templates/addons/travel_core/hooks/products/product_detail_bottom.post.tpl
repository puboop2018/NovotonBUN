{*
 * Travel Core: Booking Form + JSON-LD for hotel product pages.
 *
 * Variables assigned by dispatch_before_display PHP hook (cart_hooks.php):
 *   $travel_booking_product_id   - product ID (only for hotel products)
 *   $travel_booking_product_code - product code (NVT.../SPX...)
 *   $travel_booking_scripts      - array of React JS bundle URLs
 *   $travel_hotel_schema_json    - JSON-LD schema string
 *
 * This is the PRIMARY booking form injection point — it runs through
 * travel_core's own hook which is more reliable than cross-addon hooks.
 *}

{* ── Booking Search Form (React mount point) ── *}
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

{* ── JSON-LD Structured Data ── *}
{if $travel_hotel_schema_json}
<script type="application/ld+json">
{$travel_hotel_schema_json nofilter}
</script>
{/if}
