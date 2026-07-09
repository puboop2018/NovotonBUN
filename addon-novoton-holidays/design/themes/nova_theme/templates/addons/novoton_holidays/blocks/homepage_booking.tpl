{**
 * Homepage Booking Engine - Booking.com Style v2.6.42
 * Uses same React booking form as product detail page
 * Includes "Where are you going?" destination field
 *}

{style src="css/addons/novoton_holidays/styles.css"}

<div class="novoton-homepage-booking" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    
    <h2 style="font-size: 28px; font-weight: 700; color: #1a1a1a; margin: 0 0 20px; text-align: center;">
        {__("novoton_holidays.find_your_next_stay")|default:"Find your next stay"}
    </h2>
    
    {* React-based booking form with destination field *}
    <div id="novoton-homepage-form-root" 
         data-mode="homepage"
         data-hotel-id=""
         data-product-id=""
         data-lang="{$smarty.const.CART_LANGUAGE|default:'en'}">
    </div>
    
</div>

{* Load React for booking form (same as product page) *}


{* Client i18n for the booking engine — shared travel_core partial
   (replaces the hand-maintained window.NovotonTranslations block). *}
{include file="addons/travel_core/components/travel_i18n.tpl"}
{$cache_ver = $smarty.const.TRAVEL_CACHE_VER|default:$smarty.const.NOVOTON_CACHE_VER|default:'1'}
<script src="{$config.current_location}/js/addons/travel_core/react-vendor.js?v={$cache_ver}" defer></script>
<script src="{$config.current_location}/js/addons/travel_core/react19-bundle.js?v={$cache_ver}" defer></script>
