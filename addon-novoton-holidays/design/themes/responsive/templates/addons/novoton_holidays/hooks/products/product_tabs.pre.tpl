{*
 * Hook: products:product_tabs
 * Injects Novoton booking form before product tabs on hotel product pages.
 *
 * Thin Smarty pattern: only a mount point div + script tags.
 * React fetches all config (provider, colors, translations) from
 * travel_booking.booking_config AJAX endpoint. Zero Smarty scope depth.
 *}

{if $product.product_code|substr:0:3 == 'NVT'}
<div id="travel-booking-root"
     data-travel-booking
     data-product-id="{$product.product_id}">
    <div class="travel-loading-state">
        <div class="nvt-skeleton-row">
            <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
            <div class="nvt-skeleton-field"></div>
            <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
        </div>
    </div>
</div>
{$_cv = $smarty.const.TRAVEL_CACHE_VER|default:'1'}
<script src="{$config.current_location}/js/addons/travel_core/react-vendor.js?v={$_cv}" defer></script>
<script src="{$config.current_location}/js/addons/travel_core/react19-bundle.js?v={$_cv}" defer></script>
{/if}
