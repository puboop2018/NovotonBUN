{capture name="mainbox"}

<div class="travel-admin-panel">

    {* API Configuration Warning *}
    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}

    {* ── Sync Controls ── *}
    <div class="travel-update-section">
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_destinations" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_destinations_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_destinations")}
            </button>
        </form>

        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_hotels" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_hotels_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_hotels")}
            </button>
        </form>

        {if $selected_countries}
            <span class="muted">
                {__("sphinx_holidays.sync_targets")}: <code>{', '|implode:$selected_countries}</code>
            </span>
            <a href="{"addons.update&addon=sphinx_holidays"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.change_settings")}
            </a>
        {/if}
    </div>

    {* ── Destination Stats ── *}
    <h4>{__("sphinx_holidays.destinations")}</h4>
    <div class="sync-stats">
        <div class="stat-card info">
            <div class="stat-value">{$total_destinations|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.total_destinations")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.continent|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.continents")}</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value">{$counts_by_type.country|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.countries")}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{$counts_by_type.region|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.regions")}</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">{$counts_by_type.city|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.cities")}</div>
        </div>
    </div>

    {if $dest_last_synced}
        <p class="muted">{__("sphinx_holidays.last_synced")}: {$dest_last_synced}</p>
    {/if}

    {* ── Hotel Stats ── *}
    <h4>{__("sphinx_holidays.hotels")}</h4>
    <div class="sync-stats">
        <div class="stat-card info">
            <div class="stat-value">{$total_hotels|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.total_hotels")}</div>
        </div>
        {foreach from=$hotels_by_country key=cc item=cnt name=hbc}
            {if $smarty.foreach.hbc.index < 5}
            <div class="stat-card">
                <div class="stat-value">{$cnt}</div>
                <div class="stat-label">{$cc}</div>
            </div>
            {/if}
        {/foreach}
    </div>

    {if $hotel_last_synced}
        <p class="muted">{__("sphinx_holidays.last_synced")}: {$hotel_last_synced}</p>
    {else}
        <p class="muted">{__("sphinx_holidays.never_synced")}</p>
    {/if}

    {* ── Product Stats ── *}
    <h4>{__("sphinx_holidays.products")}</h4>
    <div class="sync-stats">
        <div class="stat-card success">
            <div class="stat-value">{$linked_products|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.linked_products")}</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">{$unlinked_hotels|default:0}</div>
            <div class="stat-label">{__("sphinx_holidays.unlinked_hotels")}</div>
        </div>
    </div>

    {if $unlinked_hotels > 0}
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.add_products" />
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('{__("sphinx_holidays.create_products_confirm")|escape:javascript}');">
                <i class="icon-plus"></i> {__("sphinx_holidays.create_products")}
            </button>
        </form>
    {/if}

    {* ── Browse Links ── *}
    <div class="travel-action-buttons">
        {if $total_destinations > 0}
            <a href="{"sphinx_holidays.destinations"|fn_url}" class="btn">
                <i class="icon-globe"></i> {__("sphinx_holidays.destinations")}
            </a>
        {/if}
        {if $total_hotels > 0}
            <a href="{"sphinx_holidays.hotels"|fn_url}" class="btn">
                <i class="icon-building"></i> {__("sphinx_holidays.hotels")}
            </a>
        {/if}
    </div>

    {* ── Recent Sync Logs ── *}
    {if $sync_logs}
        <h4>{__("sphinx_holidays.sync_log")}</h4>
        {foreach from=$sync_logs item=log}
            <div class="sync-log-entry">
                <div class="sync-log-header">
                    <span class="sync-log-date">{$log.started_at}</span>
                    <span class="status-badge status-{if $log.status == 'completed'}ok{elseif $log.status == 'failed'}cancelled{else}pending{/if}">
                        {$log.sync_type|escape:html}: {$log.status|escape:html}
                    </span>
                    {if $log.sync_mode == 'incremental'}
                        <span class="status-badge status-ok">{__("sphinx_holidays.sync_mode_incremental")}</span>
                    {elseif $log.sync_mode == 'full'}
                        <span class="status-badge">{__("sphinx_holidays.sync_mode_full")}</span>
                    {/if}
                </div>
                <div class="sync-log-stats">
                    <span>{__("sphinx_holidays.items_synced")}: {$log.items_synced}/{$log.items_total}</span>
                    {if $log.duration_ms}
                        <span>{__("sphinx_holidays.duration")}: {($log.duration_ms/1000)|string_format:"%.1f"}s</span>
                    {/if}
                    {if $log.rate_limit_hits > 0}
                        <span class="text-warning">{__("sphinx_holidays.rate_limited_requests")}: {$log.rate_limit_hits}</span>
                    {/if}
                    {if $log.error_message}
                        <span class="text-error">{$log.error_message|escape:html|truncate:100}</span>
                    {/if}
                </div>
            </div>
        {/foreach}
    {/if}

</div>

{/capture}

{include file="common/mainbox.tpl" title="{__("sphinx_holidays.sphinx_dashboard")}" content=$smarty.capture.mainbox}
