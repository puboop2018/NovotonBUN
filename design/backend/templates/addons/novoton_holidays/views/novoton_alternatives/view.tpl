{** Novoton Alternatives - View Request Details **}

{capture name="mainbox"}

<div class="well">
    <div class="row-fluid">
        <div class="span6">
            <h4>Request #{$request.request_id}</h4>
            <table class="table table-condensed">
                <tr><td width="35%"><strong>Hotel:</strong></td><td>{$request.hotel_name|default:$request.hotel_id}</td></tr>
                <tr><td><strong>Check-in:</strong></td><td>{$request.check_in|date_format:"%d %b %Y"}</td></tr>
                <tr><td><strong>Check-out:</strong></td><td>{$request.check_out|date_format:"%d %b %Y"}</td></tr>
                <tr><td><strong>Nights:</strong></td><td>{$request.nights}</td></tr>
                <tr><td><strong>Guests:</strong></td><td>{$request.adults} adults{if $request.children > 0}, {$request.children} children{/if}</td></tr>
                <tr><td><strong>Rooms:</strong></td><td>{$request.num_rooms}</td></tr>
            </table>
        </div>
        <div class="span6">
            <h4>Contact Info</h4>
            <table class="table table-condensed">
                <tr><td width="35%"><strong>Email:</strong></td><td><a href="mailto:{$request.contact_email}">{$request.contact_email}</a></td></tr>
                <tr><td><strong>Phone:</strong></td><td>{$request.contact_phone|default:'-'}</td></tr>
                <tr><td><strong>Notes:</strong></td><td>{$request.notes|default:'-'}</td></tr>
                <tr><td><strong>Status:</strong></td><td>
                    {if $request.status == 'pending'}<span class="label label-warning">Pending</span>
                    {elseif $request.status == 'alternatives_found'}<span class="label label-success">Alternatives Found</span>
                    {elseif $request.status == 'notified'}<span class="label label-info">Notified</span>
                    {else}<span class="label">{$request.status}</span>{/if}
                </td></tr>
                <tr><td><strong>Created:</strong></td><td>{$request.created_at}</td></tr>
                {if $request.notified_at}<tr><td><strong>Notified:</strong></td><td>{$request.notified_at}</td></tr>{/if}
            </table>
        </div>
    </div>
</div>

{* Novoton API Section *}
<div class="well">
    <h4><i class="icon-cloud"></i> Novoton API</h4>
    
    <div class="row-fluid">
        <div class="span6">
            <table class="table table-condensed">
                <tr>
                    <td width="40%"><strong>Novoton IdNum:</strong></td>
                    <td>
                        {if $request.novoton_request_id}
                            <span class="label label-success" style="font-size: 14px;">{$request.novoton_request_id}</span>
                        {else}
                            <span class="label label-warning">Not sent to API</span>
                        {/if}
                    </td>
                </tr>
            </table>
        </div>
        <div class="span6">
            {if $request.novoton_request_id && $request.status == 'pending'}
            <form method="post" action="{fn_url('')}" style="display: inline;">
                <input type="hidden" name="dispatch" value="novoton_alternatives.check_alternatives">
                <input type="hidden" name="request_id" value="{$request.request_id}">
                <button type="submit" class="btn btn-primary">
                    <i class="icon-search"></i> Check for Alternatives (alternative_RS)
                </button>
            </form>
            <p class="muted" style="margin-top: 10px; font-size: 11px;">
                <i class="icon-info-sign"></i> Alternatives are typically available 24-48 hours after request submission.
            </p>
            {/if}
        </div>
    </div>
</div>

{* XML Request Sent *}
{if $request.api_request_xml}
<div class="well">
    <h4><i class="icon-code"></i> hotel_request XML Sent to API</h4>
    <pre class="novoton-xml-preview">{$request.api_request_xml|escape:'html'}</pre>
</div>
{/if}

{* API Response *}
{if $request.api_response}
<div class="well">
    <h4><i class="icon-download-alt"></i> API Response (hotel_request_RS)</h4>
    <pre class="novoton-code-preview">{$request.api_response|escape:'html'}</pre>
</div>
{/if}

{if $request.alternatives}
<div class="well">
    <h4><i class="icon-list-alt"></i> Available Alternatives ({$request.alternatives|@count})</h4>
    
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Res #</th>
                <th>Hotel</th>
                <th>Room</th>
                <th>Dates</th>
                <th>Quota</th>
                <th>Price</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$request.alternatives item=alt}
            <tr>
                <td>{$alt.res_num|default:'-'}</td>
                <td>
                    {$alt.package_name|default:$alt.hotel_id}
                </td>
                <td>{$alt.room_id}<br><small class="muted">{$alt.board_id}</small></td>
                <td>{$alt.check_in} - {$alt.check_out}</td>
                <td>{if $alt.quota > 0}<span class="label label-success">{$alt.quota}</span>{else}<span class="label label-warning">RQ</span>{/if}</td>
                <td><strong>{$alt.total|default:'-'} {$smarty.const.CART_PRIMARY_CURRENCY}</strong></td>
            </tr>
            {/foreach}
        </tbody>
    </table>
    
    {if $request.status == 'alternatives_found'}
    <form method="post" action="{fn_url('')}" style="margin-top: 15px;">
        <input type="hidden" name="dispatch" value="novoton_alternatives.notify_customer">
        <input type="hidden" name="request_id" value="{$request.request_id}">
        <button type="submit" class="btn btn-success">
            <i class="icon-envelope"></i> Send Alternatives to Customer
        </button>
    </form>
    {/if}
</div>
{/if}

{/capture}

{capture name="buttons"}
<a href="{fn_url('novoton_alternatives.manage')}" class="btn">{__("back")}</a>
{/capture}

{include file="common/mainbox.tpl" 
    title="Alternative Request #{$request.request_id}" 
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
