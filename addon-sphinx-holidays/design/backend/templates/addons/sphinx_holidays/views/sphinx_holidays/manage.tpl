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
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
            </a>
        {else}
            <span class="text-warning">
                <i class="icon-warning-sign"></i> {__("sphinx_holidays.no_sync_targets")}
            </span>
            <a href="{"sphinx_holidays.whitelist"|fn_url}" class="btn btn-micro">
                <i class="icon-cog"></i> {__("sphinx_holidays.destination_whitelist")}
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
            <div class="stat-value">{math equation="x+y" x=$counts_by_type.city|default:0 y=$counts_by_type.destination|default:0}</div>
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
        {if $skipped_hotels > 0}
        <div class="stat-card" style="border-top: 3px solid #dc3545;">
            <div class="stat-value" style="color: #dc3545;">{$skipped_hotels}</div>
            <div class="stat-label">{__("sphinx_holidays.skipped_hotels")}</div>
        </div>
        {/if}
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
    {if $skipped_hotels > 0}
        <form action="{""|fn_url}" method="post" style="display:inline; margin-left: 8px;">
            <input type="hidden" name="dispatch" value="sphinx_holidays.retry_skipped" />
            <button type="submit" class="btn btn-warning"
                    onclick="return confirm('{__("sphinx_holidays.retry_skipped_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.retry_skipped")}
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

    {* ── Cron Commands ── *}
    <h4>{__("sphinx_holidays.cron_commands")}</h4>
    <div class="well">
        <table class="table table-condensed table-hover" style="table-layout:fixed; width:100%;">
            <colgroup>
                <col style="width:120px;" />
                <col style="width:260px;" />
                <col style="width:180px;" />
                <col />
                <col style="width:40px;" />
            </colgroup>
            <thead>
                <tr>
                    <th>Mode</th>
                    <th>Description</th>
                    <th>Schedule</th>
                    <th>URL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>destinations</strong></td>
                    <td>Sync all destinations from Sphinx API</td>
                    <td><code>0 2 * * 0</code> (weekly)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.destinations}</code></td>
                    <td><a href="{$cron_urls.destinations}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>hotels</strong></td>
                    <td>Sync hotels for selected destinations</td>
                    <td><code>0 3 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.hotels}</code></td>
                    <td><a href="{$cron_urls.hotels}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>add_products</strong></td>
                    <td>Create CS-Cart products for unlinked hotels</td>
                    <td><code>30 3 * * *</code> (daily after hotels)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.add_products}</code></td>
                    <td><a href="{$cron_urls.add_products}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>package_routes</strong></td>
                    <td>Sync package routes from Sphinx API</td>
                    <td><code>0 4 * * 1</code> (weekly)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.package_routes}</code></td>
                    <td><a href="{$cron_urls.package_routes}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>order_status</strong></td>
                    <td>Sync booking statuses with Sphinx API</td>
                    <td><code>*/15 * * * *</code> (every 15 min)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.order_status}</code></td>
                    <td><a href="{$cron_urls.order_status}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>cache_refresh</strong></td>
                    <td>Refresh cached search results</td>
                    <td><code>*/30 * * * *</code> (every 30 min)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.cache_refresh}</code></td>
                    <td><a href="{$cron_urls.cache_refresh}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>cleanup</strong></td>
                    <td>Clean expired cache and old sync logs</td>
                    <td><code>0 5 * * *</code> (daily)</td>
                    <td style="word-break:break-all; font-size:11px; overflow:hidden;"><code>{$cron_urls.cleanup}</code></td>
                    <td><a href="{$cron_urls.cleanup}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>discover_boards</strong></td>
                    <td>Discover meal plans from cache API</td>
                    <td><code>0 3 * * *</code> (daily)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.discover_boards}</code></td>
                    <td><a href="{$cron_urls.discover_boards}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>assign_boards</strong></td>
                    <td>Assign board codes to hotels</td>
                    <td><code>30 3 * * *</code> (daily, after discover)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.assign_boards}</code></td>
                    <td><a href="{$cron_urls.assign_boards}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>update_products</strong></td>
                    <td>Push changed hotel data to CS-Cart products</td>
                    <td><code>0 6 * * *</code> (daily, after hotels)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.update_products}</code></td>
                    <td><a href="{$cron_urls.update_products}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
                <tr>
                    <td><strong>sync_images</strong></td>
                    <td>Download and attach hotel images to CS-Cart products</td>
                    <td><code>0 4 * * *</code> (daily, after add_products)</td>
                    <td style="max-width:350px; word-break:break-all; font-size:11px;"><code>{$cron_urls.sync_images}</code></td>
                    <td><a href="{$cron_urls.sync_images}" target="_blank" class="btn btn-mini">Run</a></td>
                </tr>
            </tbody>
        </table>

        {if !$cron_key}
            <div class="alert alert-warning">
                <i class="icon-warning-sign"></i>
                {__("sphinx_holidays.cron_key_not_set")}
            </div>
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

{include file="common/mainbox.tpl" title=__("sphinx_holidays.sphinx_dashboard") content=$smarty.capture.mainbox}
