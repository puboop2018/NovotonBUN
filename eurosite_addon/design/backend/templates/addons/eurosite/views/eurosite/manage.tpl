{capture name="mainbox"}

<div class="travel-admin-panel">

    {* ── API status ── *}
    {if !$eurosite_is_configured}
        <div class="alert alert-warning">
            <i class="icon-warning-sign"></i>
            {__("eurosite.api_not_configured", ["[default]" => "Eurosite API credentials are not configured — enter them in the addon settings. All catalogs and searches will fail with error -1000 until then."])}
        </div>
    {/if}

    <div class="control-group">
        <h4>{__("eurosite.api_connection", ["[default]" => "API connection"])}</h4>
        <p>
            <code>{$eurosite_api_url}</code>
            &nbsp; {__("eurosite.api_user", ["[default]" => "API user"])}: <code>{$eurosite_api_user|escape:html}</code>
        </p>
        <form action="{""|fn_url}" method="post" style="display:inline;">
            <input type="hidden" name="dispatch" value="eurosite.test_connection" />
            <button type="submit" class="btn">
                <i class="icon-ok"></i> {__("eurosite.test_connection", ["[default]" => "Test API connection"])}
            </button>
        </form>
        <a href="{"addons.update&addon=eurosite"|fn_url}" class="btn btn-micro">
            <i class="icon-cog"></i> {__("eurosite.addon_settings", ["[default]" => "Addon settings"])}
        </a>
    </div>

    {* ── Catalog counts ── *}
    <h4>{__("eurosite.catalogs", ["[default]" => "Static-data catalogs"])}</h4>
    <table class="table table-middle" style="max-width: 720px;">
        <thead>
            <tr>
                <th>{__("eurosite.catalog", ["[default]" => "Catalog"])}</th>
                <th>{__("eurosite.rows", ["[default]" => "Rows"])}</th>
                <th>{__("eurosite.last_sync", ["[default]" => "Last sync"])}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {foreach from=["countries" => $eurosite_counts.countries,
                           "cities" => $eurosite_counts.cities,
                           "own_cities" => $eurosite_counts.own_cities,
                           "hotels" => $eurosite_counts.hotels,
                           "room_types" => $eurosite_counts.room_types,
                           "tags" => $eurosite_counts.tags,
                           "product_info" => $eurosite_counts.cache] key=catalog item=count}
                {assign var="last" value=$eurosite_last_syncs.$catalog}
                <tr>
                    <td><code>{$catalog}</code></td>
                    <td>{$count}</td>
                    <td>
                        {if $last}
                            <span class="label {if $last.status == 'completed'}label-success{elseif $last.status == 'failed'}label-important{else}label-warning{/if}">{$last.status}</span>
                            {$last.synced}/{$last.total} &middot; {$last.started_at} ({$last.duration_s}s)
                            {if $last.error}<div class="text-error">{$last.error|escape:html|truncate:120}</div>{/if}
                        {else}
                            <span class="muted">{__("eurosite.never_synced", ["[default]" => "never"])}</span>
                        {/if}
                    </td>
                    <td>
                        {if $eurosite_sync_modes.$catalog}
                            <form action="{""|fn_url}" method="post" style="display:inline;">
                                <input type="hidden" name="dispatch" value="eurosite.run_sync" />
                                <input type="hidden" name="sync_type" value="{$catalog}" />
                                <button type="submit" class="btn btn-micro" {if !$eurosite_is_configured}disabled{/if}>
                                    <i class="icon-refresh"></i> {__("eurosite.sync_now", ["[default]" => "Sync now"])}
                                </button>
                            </form>
                        {/if}
                    </td>
                </tr>
            {/foreach}
        </tbody>
    </table>

    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="eurosite.run_sync" />
        <input type="hidden" name="sync_type" value="full" />
        <button type="submit" class="btn btn-primary" {if !$eurosite_is_configured}disabled{/if}
                onclick="return confirm('{__("eurosite.sync_full_confirm", ["[default]" => "Run the full static-data sync now? This makes many API calls."])|escape:javascript}');">
            <i class="icon-refresh"></i> {__("eurosite.sync_full", ["[default]" => "Run full sync"])}
        </button>
    </form>
    <a href="{"eurosite.whitelist"|fn_url}" class="btn">
        <i class="icon-map-marker"></i> {__("eurosite.destination_whitelist", ["[default]" => "Destination whitelist"])}
        ({$eurosite_counts.whitelist})
    </a>
    <form action="{""|fn_url}" method="post" style="display:inline;">
        <input type="hidden" name="dispatch" value="eurosite.seed_menu" />
        <button type="submit" class="btn">
            <i class="icon-list"></i> {__("eurosite.seed_menu", ["[default]" => "Seed storefront menu"])}
        </button>
    </form>

    {* ── Recent bookings ── *}
    <h4 style="margin-top: 20px;">{__("eurosite.recent_bookings", ["[default]" => "Recent bookings"])} ({$eurosite_counts.bookings})</h4>
    {if $eurosite_recent_bookings}
        <table class="table table-middle" style="max-width: 900px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>{__("eurosite.hotel", ["[default]" => "Hotel"])}</th>
                    <th>{__("eurosite.check_in", ["[default]" => "Check-in"])}</th>
                    <th>{__("eurosite.total", ["[default]" => "Total"])}</th>
                    <th>{__("eurosite.status", ["[default]" => "Status"])}</th>
                    <th>{__("eurosite.order", ["[default]" => "Order"])}</th>
                    <th>{__("eurosite.created", ["[default]" => "Created"])}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$eurosite_recent_bookings item=b}
                    <tr>
                        <td><a href="{"travel_bookings.manage&provider=eurosite"|fn_url}">#{$b.booking_id}</a></td>
                        <td>{$b.hotel_name|escape:html}</td>
                        <td>{$b.check_in}</td>
                        <td>{$b.total}</td>
                        <td><span class="label {if $b.status == 'confirmed'}label-success{elseif $b.status == 'cancelled' || $b.status == 'failed'}label-important{else}label-warning{/if}">{$b.status}</span></td>
                        <td>{if $b.order_id}<a href="{"orders.details&order_id=`$b.order_id`"|fn_url}">#{$b.order_id}</a>{else}&mdash;{/if}</td>
                        <td>{$b.created_at}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        <a href="{"travel_bookings.manage&provider=eurosite"|fn_url}" class="btn btn-micro">
            {__("eurosite.all_bookings", ["[default]" => "All Eurosite bookings"])}
        </a>
    {else}
        <p class="muted">{__("eurosite.no_bookings", ["[default]" => "No bookings yet."])}</p>
    {/if}

    {* ── Cron URLs ── *}
    <h4 style="margin-top: 20px;">{__("eurosite.cron_jobs", ["[default]" => "Cron jobs"])}</h4>
    <p class="muted">
        {__("eurosite.cron_hint", ["[default]" => "Schedule these from the server crontab / cPanel. CLI equivalent:"])}
        <code>php app/addons/eurosite/cron.php access_key={$eurosite_cron_key} mode=full</code>
    </p>
    <table class="table table-middle" style="max-width: 900px;">
        <tbody>
            {foreach from=$eurosite_cron_urls key=cron_mode item=url}
                <tr>
                    <td style="width: 120px;"><code>{$cron_mode}</code></td>
                    <td class="muted">{$eurosite_sync_modes.$cron_mode|escape:html}</td>
                    <td><a href="{$url}" target="_blank" rel="noopener" class="btn btn-micro">{__("eurosite.open", ["[default]" => "Open"])}</a></td>
                </tr>
            {/foreach}
        </tbody>
    </table>

</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl" title=__("eurosite.dashboard_title", ["[default]" => "Eurosite Touring"]) content=$smarty.capture.mainbox buttons=$smarty.capture.buttons}
