{* Cross-provider "no availability" contact requests (read-only grid).
   Novoton rows mirror novoton_alternative_requests (deep API detail lives on
   the Novoton → Alternative requests page); sphinx rows are internal-only —
   no provider API request exists for them. *}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>{__("search")}</h6>

    <form action="{""|fn_url}" method="get" name="travel_alt_filter_form">
        <input type="hidden" name="dispatch" value="travel_alternatives.manage" />

        <div class="sidebar-field">
            <label>{__("travel_core.provider")}:</label>
            <select name="provider">
                <option value="">--</option>
                <option value="novoton" {if $search.provider == "novoton"}selected{/if}>Novoton</option>
                <option value="sphinx" {if $search.provider == "sphinx"}selected{/if}>Sphinx</option>
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("status")}:</label>
            <select name="status">
                <option value="">--</option>
                {foreach from=["pending", "pending_manual", "alternatives_found", "notified", "booked", "expired", "cancelled"] item=st}
                    <option value="{$st}" {if $search.status == $st}selected{/if}>{$st}</option>
                {/foreach}
            </select>
        </div>

        <div class="sidebar-field">
            <label>{__("search")}:</label>
            <input type="text" name="q" value="{$search.q|escape:html}" placeholder="{__("travel_core.hotel_or_email")|default:"Hotel or e-mail"}" />
        </div>

        <div class="sidebar-field">
            <input type="submit" class="btn" value="{__("search")}" />
        </div>
    </form>
</div>
{/capture}

{capture name="mainbox"}

{include file="common/pagination.tpl" save_current_url=true}

{if $alt_requests}
<table class="table table-middle table-striped">
    <thead>
        <tr>
            <th width="60">ID</th>
            <th width="90">{__("travel_core.provider")}</th>
            <th>{__("travel_core.hotel")|default:"Hotel"}</th>
            <th width="180">{__("travel_core.period")}</th>
            <th width="140">{__("travel_core.guests")}</th>
            <th>{__("travel_core.contact")}</th>
            <th width="120">{__("status")}</th>
            <th width="130">{__("travel_core.created")}</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$alt_requests item=req}
        <tr>
            <td><code style="font-size:11px;">#{$req.request_id}</code></td>
            <td>
                {if $req.provider == 'sphinx'}
                    <span class="label">{$req.provider|escape:html}</span>
                {else}
                    <span class="label label-info">{$req.provider|escape:html}</span>
                {/if}
            </td>
            <td>
                {$req.hotel_name|default:"-"|escape:html}
                <div class="muted" style="font-size:11px;">{$req.hotel_id|escape:html}{if $req.provider_request_id} &middot; {__("travel_core.provider_ref")|default:"provider ref"} #{$req.provider_request_id}{/if}</div>
            </td>
            <td>
                {$req.check_in|default:"-"} &rarr; {$req.check_out|default:"-"}
                {if $req.nights}<div class="muted" style="font-size:11px;">{$req.nights} {__("travel_core.nights")|default:"nights"}</div>{/if}
            </td>
            <td>
                {$req.adults}A{if $req.children > 0} + {$req.children}C{/if} &middot; {$req.num_rooms} {__("travel_core.rooms_short")|default:"cam."}
                {if $req.children_ages}<div class="muted" style="font-size:11px;">{$req.children_ages|escape:html}</div>{/if}
            </td>
            <td>
                {$req.contact_email|escape:html}
                {if $req.contact_phone}<div class="muted" style="font-size:11px;">{$req.contact_phone|escape:html}</div>{/if}
                {if $req.notes}<div class="muted" style="font-size:11px;">{$req.notes|escape:html|truncate:80}</div>{/if}
            </td>
            <td>
                {if $req.status == 'pending' || $req.status == 'pending_manual'}
                    <span class="label label-warning">{$req.status|escape:html}</span>
                {elseif $req.status == 'booked' || $req.status == 'notified' || $req.status == 'alternatives_found'}
                    <span class="label label-success">{$req.status|escape:html}</span>
                {else}
                    <span class="label">{$req.status|escape:html}</span>
                {/if}
            </td>
            <td>{$req.created_at|default:"-"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p class="no-items">{__("travel_core.no_alternative_requests")|default:"No availability requests yet."}</p>
{/if}

{include file="common/pagination.tpl"}

{/capture}

{include file="common/mainbox.tpl"
    title="{__('travel_core.alternative_requests')}"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
