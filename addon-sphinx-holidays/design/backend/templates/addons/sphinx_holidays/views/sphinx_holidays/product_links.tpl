{*
 * Sphinx hotel ⇄ product link audit.
 *
 * A hotel product page is only bookable when a ?:sphinx_hotels row points at
 * it; otherwise it renders as a plain catalog item (no booking form, no
 * location line). This page names every product in that state, says WHY, and
 * runs the repair — the relink action is POST-only, so this is its home in
 * the admin (there is no URL that can trigger it).
 *}
{capture name="mainbox"}

<div class="travel-admin-panel">

    <div class="sync-stats" style="margin-bottom: 20px;">
        <div class="stat-card {if $unlinked_sphinx_products > 0}warning{else}success{/if}">
            <div class="stat-value">{$unlinked_sphinx_products|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.unlinked_products")}</div>
        </div>
    </div>

    <p class="muted" style="margin-bottom: 15px;">
        {__("sphinx_holidays.product_links_description")}
    </p>

    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i> {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}

    {if $unlinked_sphinx_products > 0}
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.relink_products" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.relink_confirm")|escape:javascript}');">
                <i class="icon-link"></i> {__("sphinx_holidays.relink_existing_products")} ({$unlinked_sphinx_products})
            </button>
        </form>
    {else}
        <div class="alert alert-success">
            <i class="icon-ok"></i> {__("sphinx_holidays.all_products_linked")}
        </div>
    {/if}

    {if $unlinked_product_rows}
        <table class="table table-middle" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>{__("sphinx_holidays.product")}</th>
                    <th>{__("product_code")}</th>
                    <th>{__("sphinx_holidays.hotel_id")}</th>
                    <th>{__("status")}</th>
                </tr>
            </thead>
            <tbody>
            {foreach from=$unlinked_product_rows item=row}
                <tr>
                    <td>
                        <a href="{"products.update?product_id=`$row.product_id`"|fn_url}">
                            {$row.name|default:$row.product_code|escape:html}
                        </a>
                    </td>
                    <td>{$row.product_code|escape:html}</td>
                    <td>{$row.hotel_id|escape:html}</td>
                    <td>
                        {if $row.state == 'missing'}
                            <span class="label label-warning">{__("sphinx_holidays.link_state_missing")}</span>
                        {elseif $row.state == 'unlinked'}
                            <span class="label">{__("sphinx_holidays.link_state_unlinked")}</span>
                        {else}
                            <span class="label label-important">
                                {__("sphinx_holidays.link_state_conflict", ["[product_id]" => $row.linked_product_id])}
                            </span>
                        {/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {/if}

</div>

{/capture}

{include file="common/mainbox.tpl"
    title=__("sphinx_holidays.product_links")
    content=$smarty.capture.mainbox
}
