{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{* Search/filter form *}
<form action="{""|fn_url}" method="get" name="travel_bookings_search_form">
    <input type="hidden" name="dispatch" value="travel_bookings.manage" />

    <div class="sidebar-row">
        <h6>{__("search")}</h6>
        <div class="sidebar-field">
            <label>{__("provider")}:</label>
            <select name="provider">
                <option value="">--</option>
                {foreach from=$providers key=code item=provider}
                    <option value="{$code}" {if $search.provider == $code}selected{/if}>{$provider.name|escape:html}</option>
                {/foreach}
            </select>
        </div>
        <div class="sidebar-field">
            <label>{__("status")}:</label>
            <select name="status">
                <option value="">--</option>
                <option value="confirmed" {if $search.status == "confirmed"}selected{/if}>{__("confirmed")}</option>
                <option value="pending" {if $search.status == "pending"}selected{/if}>{__("pending")}</option>
                <option value="cancelled" {if $search.status == "cancelled"}selected{/if}>{__("cancelled")}</option>
            </select>
        </div>
        <div class="sidebar-field">
            <label>{__("order_id")}:</label>
            <input type="text" name="order_id" value="{$search.order_id}" size="10" />
        </div>
        <div class="sidebar-field">
            <label>{__("hotel_name")}:</label>
            <input type="text" name="hotel_name" value="{$search.hotel_name|escape:html}" size="20" />
        </div>
        <div class="sidebar-field">
            <label>{__("date_from")}:</label>
            <input type="text" name="date_from" value="{$search.date_from}" class="cm-calendar" size="10" />
        </div>
        <div class="sidebar-field">
            <label>{__("date_to")}:</label>
            <input type="text" name="date_to" value="{$search.date_to}" class="cm-calendar" size="10" />
        </div>
        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </div>
</form>

{if $bookings}
<table class="table table-middle">
    <thead>
        <tr>
            <th>{__("id")}</th>
            <th>{__("order_id")}</th>
            <th>{__("provider")}</th>
            <th>{__("hotel_name")}</th>
            <th>{__("check_in")}</th>
            <th>{__("check_out")}</th>
            <th>{__("status")}</th>
            <th>{__("created_at")}</th>
            <th>&nbsp;</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$bookings item=booking}
        <tr>
            <td>{$booking.booking_id}</td>
            <td><a href="{"orders.details?order_id=`$booking.order_id`"|fn_url}">{$booking.order_id}</a></td>
            <td>{$booking.provider|escape:html}</td>
            <td>{$booking.hotel_name|escape:html}</td>
            <td>{$booking.check_in}</td>
            <td>{$booking.check_out}</td>
            <td>{$booking.status|escape:html}</td>
            <td>{$booking.created_at}</td>
            <td><a href="{"travel_bookings.view?booking_id=`$booking.booking_id`"|fn_url}" class="btn btn-primary btn-micro">{__("view")}</a></td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("no_data")}</p>
{/if}

{include file="common/pagination.tpl"}

{/capture}

{include file="common/mainbox.tpl" title="{__("travel_core.complete_booking")}" content=$smarty.capture.mainbox}
