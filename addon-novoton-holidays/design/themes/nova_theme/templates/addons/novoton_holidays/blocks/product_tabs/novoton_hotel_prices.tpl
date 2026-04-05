{* block-description:Hotel Prices *}
{*
 * Novoton Hotel Prices Tab Template (nova_theme)
 *
 * Smarty 5 compatibility: no custom plugins, no $product modification.
 *}

{if !$product.product_code || $product.product_code|substr:0:3 != 'NVT'}
    {* Not a Novoton hotel — return empty so CS-Cart hides the tab *}
{else}
    <div class="novoton-hotel-prices" id="novoton_prices_tab">
        <p class="muted">{__("novoton_holidays.prices_use_search_form")}</p>
    </div>
{/if}
