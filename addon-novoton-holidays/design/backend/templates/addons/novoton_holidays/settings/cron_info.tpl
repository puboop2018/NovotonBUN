<div class="control-group">
    <div class="well">
        <h4>{__("novoton_holidays.quick_actions")}</h4>
        <p>
            <a href="admin.php?dispatch=novoton_holidays.manage" class="btn btn-primary">
                {__("novoton_holidays.open_dashboard")}
            </a>
        </p>
        <p class="muted">
            {__("novoton_holidays.cron_info_description")}
        </p>

        <h4>{__("novoton_holidays.cron_commands")}</h4>
        <p class="muted">{__("novoton_holidays.cron_schedule_hint")}</p>
        <table class="table table-condensed">
            <thead><tr><th>{__("novoton_holidays.cron_mode")}</th><th>{__("novoton_holidays.cron_command")}</th><th>{__("novoton_holidays.cron_suggested_schedule")}</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>hotel_info_batched</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=hotel_info_batched</code></td>
                    <td>Daily (<code>0 3 * * *</code>)</td>
                </tr>
                <tr>
                    <td><strong>sync_priceinfo_batched</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=sync_priceinfo_batched</code></td>
                    <td>Every 30 min (<code>*/30 * * * *</code>)</td>
                </tr>
                <tr>
                    <td><strong>compute_prices</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=compute_prices</code></td>
                    <td>Every 5 min (<code>*/5 * * * *</code>)</td>
                </tr>
                <tr>
                    <td><strong>recompute_calendar_prices</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=recompute_calendar_prices</code></td>
                    <td>After sync or on demand</td>
                </tr>
                <tr>
                    <td><strong>resinfo</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=resinfo</code></td>
                    <td>Every 15 min (<code>*/15 * * * *</code>)</td>
                </tr>
                <tr>
                    <td><strong>cleanup</strong></td>
                    <td><code>php {$smarty.const.DIR_ROOT}/index.php?dispatch=novoton_cron.run&amp;access_key=YOUR_KEY&amp;mode=cleanup</code></td>
                    <td>Daily (<code>0 4 * * *</code>)</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
