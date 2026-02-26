{*
 * Novoton Booking Details View
 *}

{capture name="mainbox"}

{if $booking}
<div class="novoton-booking-view">

    {* Booking Header *}
    <div class="booking-header">
        <div class="header-layout">
            <div>
                <h2>{$booking.hotel_name}</h2>
                <p class="subtitle">{$booking.package_name|default:$booking.city}</p>
            </div>
            <div style="text-align: right;">
                {if $booking.novoton_invoice_id}
                <div class="novoton-id-box">
                    <strong>Novoton ID: {$booking.novoton_invoice_id}</strong>
                </div>
                {/if}
                {if $booking.novoton_status == 'OK'}
                    <span class="label label-success status-label">&#10003; Confirmed</span>
                {elseif $booking.novoton_status == 'ASK'}
                    <span class="label label-warning status-label">&#8987; On Request</span>
                {elseif $booking.novoton_status == 'ST'}
                    <span class="label label-danger status-label">&#10007; Cancelled</span>
                {elseif $booking.novoton_status == 'WT'}
                    <span class="label label-info status-label">&#8987; Waiting</span>
                {elseif $booking.novoton_status == 'RQ'}
                    <span class="label label-primary status-label">&#8634; Alternatives</span>
                {else}
                    <span class="label status-label">{$booking.status|default:'pending'}</span>
                {/if}
            </div>
        </div>
    </div>

    {* Booking Details Grid *}
    <div class="info-grid">
        <div class="info-section">
            <h4>Booking Details</h4>
            <table class="info-table">
                <tr><td>Booking ID:</td><td><strong>#{$booking.booking_id}</strong></td></tr>
                <tr><td>Order ID:</td><td>{if $booking.order_id > 0}<a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">#{$booking.order_id}</a>{else}<span class="muted">-</span>{/if}</td></tr>
                <tr><td>Hotel ID:</td><td>{$booking.hotel_id}</td></tr>
                <tr><td>Room:</td><td>{$booking.room_type|default:$booking.room_id}</td></tr>
                <tr><td>Board:</td><td>{$booking.board_id}</td></tr>
            </table>
        </div>

        <div class="info-section">
            <h4>Stay Details</h4>
            <table class="info-table">
                <tr><td>Check-in:</td><td><strong class="novoton-badge-success" style="padding: 2px 6px; border-radius: 3px;">{$booking.check_in|date_format:"%d.%m.%Y"}</strong></td></tr>
                <tr><td>Check-out:</td><td><strong class="novoton-badge-danger" style="padding: 2px 6px; border-radius: 3px;">{$booking.check_out|date_format:"%d.%m.%Y"}</strong></td></tr>
                <tr><td>Nights:</td><td><strong>{$booking.nights}</strong></td></tr>
                <tr><td>Rooms:</td><td>{$booking.num_rooms|default:1}</td></tr>
            </table>
        </div>

        <div class="info-section">
            <h4>Guests</h4>
            <table class="info-table">
                <tr><td>Adults:</td><td>{$booking.adults}</td></tr>
                <tr><td>Children:</td><td>{$booking.children}{if $booking.children_ages} ({$booking.children_ages}){/if}</td></tr>
                <tr><td>Holder:</td><td><strong>{$booking.holder_name|default:$booking.guest_name}</strong></td></tr>
                <tr><td>Email:</td><td>{$booking.guest_email|default:'-'}</td></tr>
                <tr><td>Phone:</td><td>{$booking.guest_phone|default:'-'}</td></tr>
            </table>
        </div>
    </div>

    {* Price Section *}
    <div class="price-section">
        <div>
            <span class="price-label">Total Price</span>
            <div class="price-total">{$booking.total_price|number_format:2} {$booking.currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
        </div>
        {if $booking.api_price && $booking.api_price != $booking.total_price}
        <div>
            <span class="price-label">API Price</span>
            <div class="price-api">{$booking.api_price|number_format:2} {$booking.currency|default:$smarty.const.CART_PRIMARY_CURRENCY}</div>
        </div>
        {/if}
        <div style="text-align: right;">
            <span class="price-label">Created</span>
            <div>{$booking.created_at|date_format:"%d.%m.%Y %H:%M"}</div>
        </div>
    </div>

    {* Rooms Details *}
    {if $booking.rooms_data}
    {assign var="rooms" value=null}
    {if is_string($booking.rooms_data)}
        {assign var="rooms" value=$booking.rooms_data|json_decode:true}
    {else}
        {assign var="rooms" value=$booking.rooms_data}
    {/if}
    {if $rooms && is_array($rooms) && $rooms|@count > 0}
    <div class="rooms-section">
        <h4>Rooms ({$rooms|@count})</h4>
        <div class="rooms-grid">
            {foreach from=$rooms item=room key=idx}
            <div class="room-card">
                <strong>Room {$idx+1}</strong>
                <div class="room-details">
                    <div>{$room.room_name|default:$room.room_id}</div>
                    <div>{$room.board_name|default:$room.board_id}</div>
                    <div>{$room.adults} adults{if $room.children}, {$room.children} children{/if}</div>
                    <div class="room-price">{$room.price|default:0|number_format:2} {$smarty.const.CART_PRIMARY_CURRENCY}</div>
                </div>
            </div>
            {/foreach}
        </div>
    </div>
    {/if}
    {/if}

    {* Guest Details *}
    {if $booking.guests_data}
    {assign var="guests_parsed" value=null}
    {if is_string($booking.guests_data)}
        {assign var="guests_parsed" value=$booking.guests_data|json_decode:true}
    {else}
        {assign var="guests_parsed" value=$booking.guests_data}
    {/if}
    {if $guests_parsed && is_array($guests_parsed)}
    <div class="guests-section">
        <h4>Guest List</h4>
        <table class="table table-bordered table-condensed" style="background: #fff;">
            <thead>
                <tr><th>#</th><th>Name</th><th>Birthday</th><th>Type</th><th>Age</th><th>Room</th></tr>
            </thead>
            <tbody>
                {foreach from=$guests_parsed item=guest key=idx}
                <tr>
                    <td>{$idx+1}</td>
                    <td><strong>{$guest.name}</strong></td>
                    <td>{$guest.birthday|default:'-'}</td>
                    <td>{if $guest.type == 'child'}<span class="label label-info">Child</span>{else}<span class="label">Adult</span>{/if}</td>
                    <td>{if $guest.type == 'child'}{$guest.age|default:''}{else}-{/if}</td>
                    <td>R{$guest.room|default:1}</td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}
    {/if}

    {* Manual Novoton ID Entry *}
    <div class="novoton-id-section">
        <h4>Novoton Reservation Link</h4>
        <form action="{"novoton_bookings.update_novoton_id"|fn_url}" method="post" class="form-inline">
            <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
            <div class="control-group" style="margin-bottom: 0;">
                <label class="control-label">Novoton ID (IdNum/ConfirmAgency):</label>
                <div class="controls">
                    <input type="text" name="novoton_invoice_id" value="{$booking.novoton_invoice_id}" placeholder="e.g., 311921" style="width: 150px;" />
                    <button type="submit" class="btn btn-primary">Save & Check Status</button>
                </div>
            </div>
        </form>
        <p class="muted" style="margin-top: 10px; font-size: 12px;">Enter the Novoton reservation ID to link this booking and check status via API.</p>
    </div>

    {* API Debug Section - Collapsible *}
    <div class="debug-toggle-section">
        <h4 class="debug-toggle" onclick="document.getElementById('api-debug').style.display = document.getElementById('api-debug').style.display === 'none' ? 'block' : 'none';">
            API Debug Information <span class="debug-hint">(click to toggle)</span>
        </h4>
        <div id="api-debug" style="display: none;">
            <div class="row-fluid">
                <div class="span6">
                    <h5>API Request Sent</h5>
                    {if $booking.api_request}
                    {assign var="api_req" value=null}
                    {if is_string($booking.api_request)}
                        {assign var="api_req" value=$booking.api_request|json_decode:true}
                    {else}
                        {assign var="api_req" value=$booking.api_request}
                    {/if}
                    <pre class="debug-section">{$api_req|@print_r:true}</pre>
                    {else}
                    <p class="muted">No API request recorded (API submission may be disabled)</p>
                    {/if}
                </div>
                <div class="span6">
                    <h5>API Response Received</h5>
                    {if $booking.api_response}
                    {assign var="api_resp" value=null}
                    {if is_string($booking.api_response)}
                        {assign var="api_resp" value=$booking.api_response|json_decode:true}
                    {else}
                        {assign var="api_resp" value=$booking.api_response}
                    {/if}
                    <pre class="debug-section">{$api_resp|@print_r:true}</pre>
                    {else}
                    <p class="muted">No API response recorded (API may be disabled or not yet called)</p>
                    {/if}
                </div>
            </div>
        </div>
    </div>

    {* Alternatives Preview *}
    {if $booking.alternatives_data}
    {assign var="alts" value=null}
    {if is_string($booking.alternatives_data)}
        {assign var="alts" value=$booking.alternatives_data|json_decode:true}
    {else}
        {assign var="alts" value=$booking.alternatives_data}
    {/if}
    {if $alts && is_array($alts) && $alts|@count > 0}
    <div class="alternatives-preview">
        <h4>{$alts|@count} Alternative(s) Found</h4>
        <table class="table table-condensed table-striped" style="background: #fff;">
            <thead>
                <tr><th>Hotel</th><th>Room</th><th>Dates</th><th>Price</th><th>Quota</th></tr>
            </thead>
            <tbody>
                {foreach from=$alts item=alt name=alts_loop}
                {if $smarty.foreach.alts_loop.index < 5}
                <tr>
                    <td><strong>{$alt.package_name|default:$alt.hotel_id}</strong></td>
                    <td>{$alt.room_id} / {$alt.board_id}</td>
                    <td>{$alt.check_in} - {$alt.check_out}</td>
                    <td><strong>{$alt.total} {$smarty.const.CART_PRIMARY_CURRENCY}</strong></td>
                    <td>{if $alt.quota > 0}<span class="label label-success">{$alt.quota}</span>{else}<span class="label label-warning">RQ</span>{/if}</td>
                </tr>
                {/if}
                {/foreach}
            </tbody>
        </table>
        {if $alts|@count > 5}
        <p class="muted">Showing first 5 of {$alts|@count} alternatives.</p>
        {/if}
        <a href="{"novoton_bookings.alternatives?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-success">View All Alternatives</a>
    </div>
    {/if}
    {/if}

</div>
{else}
<div class="alert alert-error">Booking not found</div>
{/if}

{/capture}

{capture name="buttons"}
<a class="btn" href="{"novoton_bookings.manage"|fn_url}">&larr; Back to Bookings</a>
{if $booking}
    {if $booking.novoton_status == 'ASK' || $booking.novoton_invoice_id}
        <form action="{"novoton_bookings.resinfo"|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
            <button type="submit" class="btn btn-primary">Check Status</button>
        </form>
    {/if}
    {if $booking.novoton_status == 'RQ' || $booking.alternatives_requested}
        <a href="{"novoton_bookings.alternatives?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-success">View Alternatives</a>
    {/if}
{/if}
{/capture}

{include file="common/mainbox.tpl"
    title="Booking #`$booking.booking_id` - `$booking.hotel_name`"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
