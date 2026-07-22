{*
    Shared hotel-identity header — the ONE place the hotel name/stars/location
    block is rendered. Consumed by both providers on the search results page
    and the guest booking form (4 surfaces, 2 themes = this file + its
    nova_theme mirror, synced by `composer mirror`).

    Data contract: $travel_hotel_header, assigned from
    Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel::toViewArray().

    Include params:
      hh_extra_class     extra class(es) appended to the h1 (provider hooks)
      hh_location_class  extra class(es) appended to the location line
      hh_new_tab         truthy => the product link opens in a new tab
                         (booking forms — an in-progress form is never lost)

    Name style: the theme's product-listing classes (ty-product-list__item-name
    wrapper + product-title link) with <bdi> isolation for RTL/mixed-script
    names. h1 kept for single-hotel page semantics.
*}
{if $travel_hotel_header && $travel_hotel_header.name}
    <h1 class="ty-product-list__item-name{if $hh_extra_class} {$hh_extra_class}{/if}">
        {if $travel_hotel_header.product_id}
            <bdi><a href="{"products.view?product_id=`$travel_hotel_header.product_id`"|fn_url}"{if $hh_new_tab} target="_blank" rel="noopener"{/if} class="product-title travel-hotel-name-link" title="{$travel_hotel_header.name|escape:html}">{$travel_hotel_header.name|escape:html}</a></bdi>
        {else}
            <bdi>{$travel_hotel_header.name|escape:html}</bdi>
        {/if}
        {if $travel_hotel_header.stars > 0} <span class="travel-hotel-stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $travel_hotel_header.stars])|escape:html}">{"★"|str_repeat:$travel_hotel_header.stars}</span>{/if}
    </h1>
    {if $travel_hotel_header.location_line || $travel_hotel_header.map_url}
        {* The " - " separator before the map link mirrors the PDP
           (main_info_title.post.tpl); spacing comes from the markup, not CSS. *}
        <p class="travel-hotel-location{if $hh_location_class} {$hh_location_class}{/if}">{$travel_hotel_header.location_line|escape:html}{if $travel_hotel_header.map_url}{if $travel_hotel_header.location_line} - {/if}<a href="{$travel_hotel_header.map_url|escape:html}" target="_blank" rel="noopener" class="travel-hotel-map-link">{__("travel_core.location_show_map")}</a>{/if}</p>
    {/if}
{/if}
