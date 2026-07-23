{* block-description:Hotel Prices *}
{*
 * Novoton Hotel Prices Tab Template
 *
 * NOTE: In Smarty 5 (CS-Cart 4.18+), we cannot use custom Smarty plugins
 * or modify $product during gather_additional_product_data_post without
 * crashing the page (Data.php:265 memory exhaustion). The prices tab data
 * will be loaded via AJAX in a future version. For now, the tab is hidden
 * for non-hotel products and shows a placeholder for hotel products.
 *}

{* Only show for Novoton hotel products (detected by product_code prefix) *}
{if !$product.product_code || $product.product_code|substr:0:3 != 'NVT'}
    {* Not a Novoton hotel — return empty so CS-Cart hides the tab *}
{else}
    <div class="novoton-hotel-prices" id="novoton_prices_tab">
        <p class="muted">{__("novoton_holidays.prices_use_search_form")}</p>
    </div>
{/if}
