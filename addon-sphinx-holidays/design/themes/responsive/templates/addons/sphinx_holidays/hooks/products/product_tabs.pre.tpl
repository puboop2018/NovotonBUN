{*
 * Hook: products:product_tabs
 * Injects Sphinx booking form before product tabs on hotel product pages.
 * Thin Smarty: React fetches config from travel_booking.booking_config.
 *}

{if $product.product_code|substr:0:3 == 'SPX'}
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
<script src="{$config.current_location}/js/addons/addon-travel-core/react-vendor.js?v={$_cv}" defer></script>
<script src="{$config.current_location}/js/addons/addon-travel-core/react19-bundle.js?v={$_cv}" defer></script>
{/if}
