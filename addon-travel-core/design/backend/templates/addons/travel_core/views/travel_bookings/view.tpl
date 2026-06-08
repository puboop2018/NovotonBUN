{*
 * Unified Travel Booking Detail View
 * Shows common booking fields + provider-specific detail panel
 *}

{capture name="mainbox"}

{if $booking}
<div class="travel-booking-view">

    {* Header with status badge *}
    <div class="well well-sm">
        <div class="row-fluid">
            <div class="span6">
                <h4 style="margin: 0;">
                    Booking #{$booking.booking_id}
                    {if $booking.provider == 'novoton'}
                        <span class="label label-info">Novoton</span>
                    {elseif $booking.provider == 'sphinx'}
                        <span class="label label-success">Sphinx</span>
                    {else}
                        <span class="label">{$booking.provider|escape:html}</span>
                    {/if}
                </h4>
            </div>
            <div class="span6 text-right">
                {if $booking.provider_display.status_label}
                    {$booking.provider_display.status_label nofilter}
                {elseif $booking.status == 'confirmed'}
                    <span class="label label-success label-large">Confirmed</span>
                {elseif $booking.status == 'pending'}
                    <span class="label label-warning label-large">Pending</span>
                {elseif $booking.status == 'cancelled'}
                    <span class="label label-danger label-large">Cancelled</span>
                {elseif $booking.status == 'failed'}
                    <span class="label label-danger label-large">Failed</span>
                {else}
                    <span class="label label-large">{$booking.status|escape:html}</span>
                {/if}
            </div>
        </div>
    </div>

    {* Common Booking Details *}
    <div class="row-fluid">
        <div class="span6">
            <h5>Booking Details</h5>
            <table class="table table-condensed">
                <tr>
                    <td class="span4"><strong>{__("order_id")}:</strong></td>
                    <td>
                        {if $booking.order_id > 0}
                            <a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">#{$booking.order_id}</a>
                        {else}
                            <span class="muted">Not linked to order</span>
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td><strong>{__("hotel_name")}:</strong></td>
                    <td>{$booking.hotel_name|escape:html}</td>
                </tr>
                {if $booking.destination}
                <tr>
                    <td><strong>Destination:</strong></td>
                    <td>{$booking.destination|escape:html}</td>
                </tr>
                {/if}
                {if $booking.room_name}
                <tr>
                    <td><strong>{__("travel_core.room")}:</strong></td>
                    <td>{$booking.room_name|escape:html}</td>
                </tr>
                {/if}
                {if $booking.board_code}
                <tr>
                    <td><strong>Board:</strong></td>
                    <td>{$booking.board_code|escape:html}</td>
                </tr>
                {/if}
            </table>
        </div>
        <div class="span6">
            <h5>Stay Details</h5>
            <table class="table table-condensed">
                <tr>
                    <td class="span4"><strong>{__("travel_core.check_in")}:</strong></td>
                    <td>{if $booking.check_in_short}{$booking.check_in_short}{else}&mdash;{/if}</td>
                </tr>
                <tr>
                    <td><strong>{__("travel_core.check_out")}:</strong></td>
                    <td>{if $booking.check_out_short}{$booking.check_out_short}{else}&mdash;{/if}</td>
                </tr>
                <tr>
                    <td><strong>{__("travel_core.nights")}:</strong></td>
                    <td>{$booking.nights|default:'-'}</td>
                </tr>
                <tr>
                    <td><strong>{__("travel_core.guests")}:</strong></td>
                    <td>{$booking.adults|default:0} Adults{if $booking.children > 0}, {$booking.children} Children{/if}</td>
                </tr>
                {if $booking.children_ages}
                <tr>
                    <td><strong>Child Ages:</strong></td>
                    <td>{$booking.children_ages|escape:html}</td>
                </tr>
                {/if}
            </table>
        </div>
    </div>

    {* Price Section *}
    <div class="row-fluid">
        <div class="span6">
            <h5>Price</h5>
            <table class="table table-condensed">
                <tr>
                    <td class="span4"><strong>Total Price:</strong></td>
                    <td><strong>{$booking.total_price|default:0|number_format:2} {$booking.currency|default:'EUR'}</strong></td>
                </tr>
                <tr>
                    <td><strong>{__("created_at")}:</strong></td>
                    <td>{$booking.created_at}</td>
                </tr>
                <tr>
                    <td><strong>Updated:</strong></td>
                    <td>{$booking.updated_at}</td>
                </tr>
            </table>
        </div>
    </div>

    {* Guests Table *}
    {if $booking.guests_decoded}
    <h5>{__("travel_core.guests")}</h5>
    <table class="table table-striped table-condensed">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Type</th>
                <th>Age</th>
                <th>Room</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$booking.guests_decoded item=guest key=idx}
            <tr>
                <td>{$idx+1}</td>
                <td>
                    {if $guest.first_name || $guest.last_name}
                        {$guest.first_name|escape:html} {$guest.last_name|escape:html}
                    {elseif $guest.name}
                        {$guest.name|escape:html}
                    {else}
                        <span class="muted">-</span>
                    {/if}
                    {if $guest.is_holder} <span class="label label-mini">Holder</span>{/if}
                </td>
                <td>{$guest.type|default:'adult'|capitalize}</td>
                <td>{$guest.age|default:'-'}</td>
                <td>{$guest.room|default:'-'}</td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    {/if}

    {* Provider-Specific Detail Panel *}
    {if $booking.provider_display}
    <h5>{$booking.provider|capitalize} Details</h5>
    <table class="table table-condensed">
        {foreach from=$booking.provider_display key=field item=value}
            {* Skip internal fields used for rendering *}
            {if $field != 'status_label' && $field != 'provider_ref'}
            <tr>
                <td class="span3"><strong>{$field|replace:'_':' '|capitalize}:</strong></td>
                <td>
                    {if is_array($value)}
                        <pre style="max-height:200px;overflow:auto;font-size:11px;">{$value|json_encode:128|escape:html}</pre>
                    {else}
                        {$value|escape:html}
                    {/if}
                </td>
            </tr>
            {/if}
        {/foreach}
    </table>
    {/if}

    {* Action Buttons *}
    <div class="buttons-container">
        <a href="{"travel_bookings.manage"|fn_url}" class="btn">{__("back")}</a>

        {* Check Status button *}
        <form action="{"travel_bookings.check_status"|fn_url}" method="post" style="display: inline;">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <input type="hidden" name="booking_id" value="{$booking.booking_id}" />
            <button type="submit" class="btn btn-default">
                <i class="icon-refresh"></i> Check Status
            </button>
        </form>

        {* Provider-specific actions *}
        {if $booking.provider_actions}
            {foreach from=$booking.provider_actions item=action}
                {if $action.method == 'POST'}
                <form action="{$action.url|fn_url}" method="post" style="display: inline;">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    {if $action.booking_id}
                    <input type="hidden" name="booking_id" value="{$action.booking_id}" />
                    {/if}
                    <button type="submit" class="btn {$action.css_class|default:'btn-default'}">
                        <i class="{$action.icon|default:'icon-cog'}"></i> {$action.label|escape:html}
                    </button>
                </form>
                {else}
                <a href="{$action.url|fn_url}" class="btn {$action.css_class|default:'btn-default'}">
                    <i class="{$action.icon|default:'icon-cog'}"></i> {$action.label|escape:html}
                </a>
                {/if}
            {/foreach}
        {/if}

        {* Link to provider's own view *}
        {if $booking.provider_view_url}
        <a href="{$booking.provider_view_url|fn_url}" class="btn btn-default">
            <i class="icon-share-alt"></i> View in {$booking.provider|capitalize}
        </a>
        {/if}
    </div>
</div>
{/if}

{/capture}

{capture name="buttons"}
    <a href="{"travel_bookings.manage"|fn_url}" class="btn">{__("back")}</a>
{/capture}

{$_bk_title = "Booking #`$booking.booking_id` - `$booking.hotel_name`"}

{include file="common/mainbox.tpl"
    title=$_bk_title
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
