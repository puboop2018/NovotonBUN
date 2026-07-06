{*
 * Travel Core: Booking Form (React mount point) — before product tabs.
 *
 * Default position. Renders unless the shared travel_core setting
 * booking_form_position is 'after_description' (then
 * product_detail_bottom.post.tpl renders the same mount instead).
 * Mount markup lives in components/booking_form_mount.tpl.
 *}

{if $addons.travel_core.booking_form_position != 'after_description'}
    {include file="addons/travel_core/components/booking_form_mount.tpl"}
{/if}
