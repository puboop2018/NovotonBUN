{** Travel Core - Tools & Cron Management **}

{capture name="mainbox"}

<div class="travel-admin-panel">

    {if $cron_key}

    {* ── Cron Jobs Section ── *}
    <div style="margin-bottom: 30px;">
        <h3 style="margin-top: 0;">{__("travel_core.tools_cron_jobs")}</h3>
        <p class="muted" style="margin-bottom: 15px;">{__("travel_core.tools_cron_jobs_desc")}</p>

        <table class="table table-middle" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 200px;">{__("travel_core.tools_col_job")}</th>
                    <th>{__("travel_core.tools_col_url")}</th>
                    <th style="width: 120px;">{__("travel_core.tools_col_schedule")}</th>
                    <th style="width: 80px;">{__("travel_core.tools_col_cpanel")}</th>
                    <th style="width: 100px;">{__("travel_core.tools_col_run")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$cron_jobs item=job key=job_key}
                <tr>
                    <td>
                        <strong>{$job.name}</strong>
                        <br><small class="muted">{$job.description}</small>
                    </td>
                    <td>
                        <div style="position: relative;">
                            <code style="display: block; padding: 8px 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 3px; font-size: 11px; word-break: break-all; color: #333;">{$job.url}</code>
                        </div>
                    </td>
                    <td>{$job.schedule}</td>
                    <td><code style="font-size: 11px;">{$job.cpanel}</code></td>
                    <td>
                        <form method="post" action="{"travel_tools.`$job.run_action`"|fn_url}" style="display: inline;">
                            <input type="hidden" name="security_hash" value="{$security_hash}" />
                            <button type="submit" class="btn btn-primary btn-small">{__("travel_core.tools_run_now")}</button>
                        </form>
                        {if $job.url}
                        <a href="{$job.url}" target="_blank" class="btn btn-small" style="margin-top: 4px;" title="{__("travel_core.tools_open_cron_url")}">
                            <i class="icon-external-link"></i> {__("travel_core.tools_open_cron_url")}
                        </a>
                        {/if}
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>

    {* ── External Cron Setup Info ── *}
    <div style="background: #e8f4fd; border: 1px solid #b8daff; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
        <strong><i class="icon-info-sign"></i> {__("travel_core.tools_cron_setup_title")}</strong>
        <p style="margin: 8px 0 0 0; font-size: 13px;">
            {__("travel_core.tools_cron_setup_desc")}
        </p>
    </div>

    {else}

    {* ── No Cron Key Warning ── *}
    <div class="alert alert-warning">
        <strong>{__("warning")}</strong>:
        {__("travel_core.tools_no_cron_key")}
        <a href="{"addons.update?addon=travel_core"|fn_url}">{__("travel_core.tools_addon_settings")}</a>
    </div>

    {/if}

    {* ── Reverse geocoding ──
       Lives here, not in the addon settings form: CS-Cart only creates
       settings rows from addon.xml at install/upgrade time, so on a store
       installed before this section existed there is no checkbox to tick.
       Saved to ?:storage_data instead. *}
    <div class="travel-geocoding-section">
        <h3>{__("travel_core.geocoding_section")}</h3>
        <p class="muted">{__("travel_core.geocoding_section_desc")}</p>

        <form method="post" action="{"travel_tools.save_geocoding"|fn_url}" class="travel-geocoding-form">
            <input type="hidden" name="security_hash" value="{$security_hash}" />
            <input type="hidden" name="geocoding[enabled]" value="N" />

            <div class="control-group">
                <label class="checkbox">
                    <input type="checkbox" name="geocoding[enabled]" value="Y" {if $geocoding.enabled}checked{/if} />
                    {__("travel_core.geocoding_enabled")}
                </label>
            </div>

            <div class="control-group">
                <label for="travel_geocoding_email">{__("travel_core.geocoding_contact_email")}:</label>
                <input type="text" id="travel_geocoding_email" name="geocoding[contact_email]"
                       value="{$geocoding.contact_email|escape:html}" size="40" />
                <p class="muted">{__("travel_core.geocoding_contact_email_desc")}</p>
            </div>

            <div class="control-group">
                <label for="travel_geocoding_endpoint">{__("travel_core.geocoding_endpoint")}:</label>
                <input type="text" id="travel_geocoding_endpoint" name="geocoding[endpoint]"
                       value="{$geocoding.endpoint|escape:html}" size="60" />
                <p class="muted">{__("travel_core.geocoding_endpoint_desc")}</p>
            </div>

            <button type="submit" name="dispatch[travel_tools.save_geocoding]" class="btn btn-primary">
                {__("save")}
            </button>
        </form>
    </div>

</div>

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.tools_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
