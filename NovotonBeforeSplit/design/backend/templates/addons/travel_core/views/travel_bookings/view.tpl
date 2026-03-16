{capture name="mainbox"}

{if $booking}
<div class="control-group">
    <h4>{__("travel_core.complete_booking")} #{$booking.booking_id}</h4>
</div>

<table class="table table-middle table-condensed">
    <tr>
        <td class="span3"><strong>{__("order_id")}:</strong></td>
        <td><a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">{$booking.order_id}</a></td>
    </tr>
    <tr>
        <td><strong>{__("provider")}:</strong></td>
        <td>{$booking.provider|escape:html}</td>
    </tr>
    {if $booking.provider_booking_id}
    <tr>
        <td><strong>Provider Booking ID:</strong></td>
        <td>{$booking.provider_booking_id|escape:html}</td>
    </tr>
    {/if}
    <tr>
        <td><strong>{__("hotel_name")}:</strong></td>
        <td>{$booking.hotel_name|escape:html}</td>
    </tr>
    <tr>
        <td><strong>{__("status")}:</strong></td>
        <td>{$booking.status|escape:html}</td>
    </tr>
    <tr>
        <td><strong>{__("travel_core.dates")}:</strong></td>
        <td>{$booking.check_in} &mdash; {$booking.check_out}</td>
    </tr>
    {if $booking.rooms}
    <tr>
        <td><strong>{__("travel_core.room")}:</strong></td>
        <td>{$booking.rooms}</td>
    </tr>
    {/if}
    {if $booking.guests}
    <tr>
        <td><strong>{__("travel_core.guests")}:</strong></td>
        <td>{$booking.guests}</td>
    </tr>
    {/if}
    {if $booking.total_price}
    <tr>
        <td><strong>{__("price")}:</strong></td>
        <td>{$booking.total_price}</td>
    </tr>
    {/if}
    <tr>
        <td><strong>{__("created_at")}:</strong></td>
        <td>{$booking.created_at}</td>
    </tr>
    {if $booking.raw_response}
    <tr>
        <td><strong>Raw Response:</strong></td>
        <td><pre style="max-height:300px;overflow:auto;">{$booking.raw_response|escape:html}</pre></td>
    </tr>
    {/if}
</table>

<div class="buttons-container">
    <a href="{"travel_bookings.manage"|fn_url}" class="btn">{__("back")}</a>
</div>
{/if}

{/capture}

{include file="common/mainbox.tpl" title="{__("travel_core.complete_booking")} #{$booking.booking_id}" content=$smarty.capture.mainbox}
