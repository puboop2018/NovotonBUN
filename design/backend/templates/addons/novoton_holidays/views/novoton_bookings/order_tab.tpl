{*
 * Novoton Booking Alternatives Tab for Order Page
 * Shows alternative hotels when booking is cancelled/rejected
 *}

{if $bookings}
<div class="novoton-alternatives-tab">
    {foreach from=$bookings item=booking}
    <div class="booking-section" style="margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
        {* Booking Header *}
        <div style="background: #003580; color: #fff; padding: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>{$booking.hotel_name}</strong>
                    <span style="opacity: 0.8;">| {$booking.room_id|replace:'%2b':'+'} | {$booking.board_id}</span>
                </div>
                <div>
                    {if $booking.novoton_status == 'OK'}
                        <span class="label label-success">Confirmed</span>
                    {elseif $booking.novoton_status == 'ASK'}
                        <span class="label label-warning">Pending</span>
                    {elseif $booking.novoton_status == 'ST'}
                        <span class="label label-danger">Cancelled</span>
                    {elseif $booking.novoton_status == 'WT'}
                        <span class="label label-info">Waiting</span>
                    {elseif $booking.novoton_status == 'RQ'}
                        <span class="label label-primary">Alternatives Pending</span>
                    {else}
                        <span class="label label-default">{$booking.novoton_status|default:'Unknown'}</span>
                    {/if}
                </div>
            </div>
            <div style="margin-top: 8px; font-size: 13px; opacity: 0.9;">
                {$booking.check_in|date_format:"%d.%m.%Y"} - {$booking.check_out|date_format:"%d.%m.%Y"} 
                ({$booking.nights} nights) | NT {$booking.novoton_invoice_id|default:'N/A'}
            </div>
        </div>
        
        {* Actions *}
        <div style="padding: 10px 15px; background: #f5f5f5; border-bottom: 1px solid #ddd;">
            <form action="{"novoton_bookings.resinfo"|fn_url}" method="post" style="display: inline;">
                <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
                <input type="hidden" name="return_url" value="{$config.current_url}" />
                <button type="submit" class="btn btn-sm btn-default">
                    <i class="icon-refresh"></i> Check Status
                </button>
            </form>
            
            {if $booking.novoton_status == 'ST' || $booking.novoton_status == 'RQ'}
            <form action="{"novoton_bookings.request_alternatives"|fn_url}" method="post" style="display: inline; margin-left: 10px;">
                <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
                <input type="hidden" name="return_url" value="{$config.current_url}" />
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="icon-list"></i> Request Alternatives
                </button>
            </form>
            {/if}
        </div>
        
        {* Alternatives List *}
        {if $booking.alternatives && count($booking.alternatives) > 0}
        <div style="padding: 15px;">
            <h4 style="margin: 0 0 15px 0; color: #003580;">
                <i class="icon-list-alt"></i> Alternative Hotels ({$booking.alternatives|count})
            </h4>
            
            <table class="table table-striped table-condensed" style="margin-bottom: 0;">
                <thead>
                    <tr style="background: #003580; color: #fff;">
                        <th>Hotel / Package</th>
                        <th>Room</th>
                        <th>Board</th>
                        <th>Dates</th>
                        <th>Availability</th>
                        <th>Price</th>
                        <th>Match</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$booking.alternatives item=alt}
                    <tr>
                        <td>
                            <strong>{$alt.hotel_name|default:$alt.hotel_id}</strong><br>
                            <small style="color: #666;">{$alt.package_name}</small>
                            {if $alt.hotel_city}<br><small>{$alt.hotel_city}</small>{/if}
                        </td>
                        <td>{$alt.room_id|replace:'%2b':'+'}</td>
                        <td>{$alt.board_id}</td>
                        <td>
                            {$alt.check_in|date_format:"%d.%m"} - {$alt.check_out|date_format:"%d.%m.%Y"}
                        </td>
                        <td>
                            {if $alt.quota > 0}
                                <span class="label label-success">{$alt.quota} rooms</span>
                            {elseif $alt.quota == 0}
                                <span class="label label-warning">On request</span>
                            {else}
                                <span class="label label-danger">Not available</span>
                            {/if}
                        </td>
                        <td>
                            <strong>{$alt.total|number_format:2} {$smarty.const.CART_PRIMARY_CURRENCY}</strong>
                        </td>
                        <td>
                            {if $alt.alt_from_req == 'Y' || $alt.alt_from_req == '1'}
                                <span class="label label-success" title="Same as original request">[OK] Match</span>
                            {else}
                                <span class="label label-default">Different</span>
                            {/if}
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        {elseif $booking.novoton_status == 'ST' || $booking.novoton_status == 'RQ'}
        <div style="padding: 20px; text-align: center; color: #999;">
            <i class="icon-info-sign"></i> No alternatives available yet. Click "Request Alternatives" to fetch from API.
        </div>
        {/if}
    </div>
    {/foreach}
</div>
{else}
<div style="padding: 20px; text-align: center; color: #999;">
    <i class="icon-info-sign"></i> No hotel bookings found for this order.
</div>
{/if}
