{*
  FGO Invoicing — admin list view.
  Mounted by controllers/backend/fgo_invoicing.php (mode=manage).
*}
{capture name="mainbox"}

<div class="alert {if $fgo_sandbox}alert-warning{else}alert-info{/if}">
    {if $fgo_sandbox}
        {__("fgo_invoicing.banner_sandbox")}
    {else}
        {__("fgo_invoicing.banner_production")}
    {/if}
</div>

<table class="table table--relative table-middle table-objects">
    <thead>
        <tr>
            <th>{__("order_id")}</th>
            <th>{__("status")}</th>
            <th>{__("fgo_invoicing.invoice_series")}</th>
            <th>{__("fgo_invoicing.invoice_number")}</th>
            <th>{__("fgo_invoicing.pdf_link")}</th>
            <th>{__("fgo_invoicing.last_error")}</th>
            <th>{__("created")}</th>
            <th>{__("updated")}</th>
            <th>&nbsp;</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$fgo_invoices item="row"}
        <tr>
            <td>
                <a href="{"orders.details?order_id=`$row.order_id`"|fn_url}">#{$row.order_id}</a>
            </td>
            <td>
                <span class="label label-{if $row.status == "issued"}success{elseif $row.status == "failed"}important{elseif $row.status == "pending"}warning{else}default{/if}">
                    {$row.status}
                </span>
            </td>
            <td>{$row.invoice_series|default:"-"}</td>
            <td>{$row.invoice_number|default:"-"}</td>
            <td>
                {if $row.pdf_link}
                    <a href="{$row.pdf_link}" target="_blank" rel="noopener">PDF</a>
                {else}-{/if}
            </td>
            <td>
                {if $row.last_error}
                    <code title="{$row.last_error|escape:html}">{$row.last_error|truncate:40:"…"|escape:html}</code>
                {else}-{/if}
            </td>
            <td>{$row.created_at}</td>
            <td>{$row.updated_at}</td>
            <td>
                <a class="btn btn-default btn-mini" href="{"fgo_invoicing.view?order_id=`$row.order_id`"|fn_url}">
                    {__("view")}
                </a>
            </td>
        </tr>
        {foreachelse}
        <tr><td colspan="9" class="center">{__("fgo_invoicing.no_invoices")}</td></tr>
        {/foreach}
    </tbody>
</table>

{/capture}
{include file="common/mainbox.tpl" title=__("fgo_invoicing.manage_title") content=$smarty.capture.mainbox}
