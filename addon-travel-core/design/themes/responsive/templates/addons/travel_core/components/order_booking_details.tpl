{*
 * Shared per-line order booking-detail block.
 *
 * Renders hotel / dates / room / board / guests / guest names for a travel
 * booking order item, from its `extra` array. Dates and guest names are the
 * fields fn_travel_core_get_order_info() normalizes onto every travel-booking
 * product (check_in_formatted / check_out_formatted, and guests_data[] with
 * display_name / type / age / room). Area-agnostic: the caller wraps it for the
 * storefront (a <div>) or admin (a full-width <tr><td>) order-details table.
 *
 * Parameters:
 *   booking_extra        (array,  required) the order item's extra.
 *   booking_view_id      (int,    default 0) unified travel_bookings surrogate id;
 *                                            when > 0 an admin "View Booking #N"
 *                                            link renders. Pass 0 on the storefront.
 *   booking_label_prefix (string, default 'travel_core') langvar namespace.
 *
 * @package TravelCore
 *}
{$_be = $booking_extra}
{$_bp = $booking_label_prefix|default:'travel_core'}
{$_bvid = $booking_view_id|default:0}
{if $_be}
    {if $_be.hotel_name}<strong>{__("`$_bp`.hotel")}:</strong> {$_be.hotel_name|escape:'html'}<br>{/if}

    <strong>{__("`$_bp`.check_in")}:</strong> {$_be.check_in_formatted|default:$_be.check_in|default:''} |
    <strong>{__("`$_bp`.check_out")}:</strong> {$_be.check_out_formatted|default:$_be.check_out|default:''} |
    <strong>{__("`$_bp`.nights")}:</strong> {$_be.nights|default:0}<br>

    {$_room = $_be.room_type_display|default:$_be.room_name|default:''}
    {if $_room}<strong>{__("`$_bp`.room")}:</strong> {$_room|escape:'html'}<br>{/if}
    {if $_be.board_name}<strong>{__("`$_bp`.board")}:</strong> {$_be.board_name|escape:'html'}<br>{/if}

    <strong>{__("`$_bp`.guests")}:</strong>
    {$_be.adults|default:0} {__("`$_bp`.adults")}{if $_be.children}, {$_be.children} {__("`$_bp`.children")}{if $_be.children_ages} ({$_be.children_ages}){/if}{/if}<br>

    {* Guest names — guests_data is pre-formatted into an array by
       fn_travel_core_get_order_info (display_name / type / age / room). *}
    {if $_be.guests_data && is_array($_be.guests_data)}
        <strong>{__("`$_bp`.guest_names")}:</strong><br>
        {foreach from=$_be.guests_data item=_g}
            &nbsp;&nbsp;{$_g.display_name|default:$_g.name|default:''|escape:'html'}{if $_g.type == 'child'} ({$_g.age|default:0} {__("`$_bp`.years_old")}){/if}{if $_g.room} &mdash; {__("`$_bp`.room")} {$_g.room}{/if}<br>
        {/foreach}
    {elseif $_be.holder_name}
        <strong>{__("`$_bp`.main_guest")}:</strong> {$_be.holder_name|escape:'html'}<br>
    {/if}

    {if $_bvid > 0}
        <div style="margin-top:6px;">
            <a href="{"travel_bookings.view?booking_id=`$_bvid`"|fn_url}">{__("`$_bp`.view_booking")} #{$_bvid}</a>
        </div>
    {/if}
{/if}
