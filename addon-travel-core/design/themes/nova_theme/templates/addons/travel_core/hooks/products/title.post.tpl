{* Location line under the hotel name (both providers), assigned by
   _travel_core_prepare_hotel_seo_data(): "street, city, country" when the
   hotel has a street address (sphinx), else "city, region, country"
   (novoton), plus a Google Maps link from the stored coordinates.
   Rendered inside the products:title hook (within the h1) — display:block
   drops it to its own line; the font resets stop it inheriting the h1 look.
   Inline styles by design: no travel_core stylesheet is guaranteed to load
   on the product page. Empty for non-hotel products (vars unset). *}
{if $travel_hotel_location_line || ($travel_hotel_lat && $travel_hotel_lng)}
    <span class="travel-pdp-location" style="display:block; font-size:14px; line-height:1.4; font-weight:normal; color:#666; margin-top:4px;">
        {$travel_hotel_location_line|escape:html}{if $travel_hotel_lat && $travel_hotel_lng}{if $travel_hotel_location_line} - {/if}<a href="https://www.google.com/maps?q={$travel_hotel_lat},{$travel_hotel_lng}" target="_blank" rel="noopener" class="travel-hotel-map-link" style="font-size:13px; white-space:nowrap;">{__("travel_core.location_show_map")|default:"Location - show map"}</a>{/if}
    </span>
{/if}
