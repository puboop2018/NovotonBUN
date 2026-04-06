{*
 * Open Graph meta tags for hotel product pages.
 * Data assigned by travel_core's dispatch_before_display hook.
 * Only renders on product pages with hotel data.
 *}
{if $travel_og_title}
<meta property="og:title" content="{$travel_og_title|escape:html}" />
<meta property="og:description" content="{$travel_og_description|escape:html}" />
<meta property="og:type" content="{$travel_og_type|default:'website'}" />
{if $travel_og_image}
<meta property="og:image" content="{$travel_og_image|escape:html}" />
{/if}
{/if}
