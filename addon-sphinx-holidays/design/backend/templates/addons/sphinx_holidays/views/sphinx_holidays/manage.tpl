{capture name="mainbox"}

<div class="travel-admin-panel">

    {* API Configuration Warning *}
    {if !$is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("sphinx_holidays.api_not_configured")}
        </div>
    {/if}

    {* Sync Controls *}
    <div class="travel-update-section">
        <form action="{""|fn_url}" method="post">
            <input type="hidden" name="dispatch" value="sphinx_holidays.sync_destinations" />
            <button type="submit" class="btn btn-primary" {if !$is_configured}disabled{/if}
                    onclick="return confirm('{__("sphinx_holidays.sync_destinations_confirm")|escape:javascript}');">
                <i class="icon-refresh"></i> {__("sphinx_holidays.sync_destinations")}
            </button>
        </form>

        {if $last_synced}
            <span class="muted">
                {__("sphinx_holidays.last_synced")}: {$last_synced}
            </span>
        {else}
            <span class="muted">
                {__("sphinx_holidays.never_synced")}
            </span>
        {/if}
    </div>

    {* Stats Cards *}
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

    {* Browse link *}
    {if $total_destinations > 0}
        <div class="travel-action-buttons">
            <a href="{"sphinx_holidays.destinations"|fn_url}" class="btn">
                <i class="icon-list"></i> {__("sphinx_holidays.destinations")}
            </a>
        </div>
    {/if}

    {* Recent Sync Logs *}
    {if $sync_logs}
        <h4>{__("sphinx_holidays.sync_log")}</h4>
        {foreach from=$sync_logs item=log}
            <div class="sync-log-entry">
                <div class="sync-log-header">
                    <span class="sync-log-date">{$log.started_at}</span>
                    <span class="status-badge status-{if $log.status == 'completed'}ok{elseif $log.status == 'failed'}cancelled{else}pending{/if}">
                        {$log.status|escape:html}
                    </span>
                </div>
                <div class="sync-log-stats">
                    <span>{__("sphinx_holidays.items_synced")}: {$log.items_synced}/{$log.items_total}</span>
                    {if $log.duration_ms}
                        <span>{__("sphinx_holidays.duration")}: {($log.duration_ms/1000)|string_format:"%.1f"}s</span>
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
