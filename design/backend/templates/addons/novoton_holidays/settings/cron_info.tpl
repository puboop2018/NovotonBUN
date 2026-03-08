<div class="control-group">
    <div class="well">
        <h4>Quick Actions</h4>
        <p>
            <a href="admin.php?dispatch=novoton_holidays.manage" class="btn btn-primary">
                Open Novoton Dashboard
            </a>
        </p>
        <p class="muted">
            All tools and cron information are available in the Novoton Dashboard.
        </p>

        <h4>Cron Commands</h4>
        <p class="muted">Schedule these via your server's crontab. Replace <code>YOUR_KEY</code> with the cron access key configured above.</p>
        <table class="table table-condensed">
            <thead><tr><th>Mode</th><th>Command</th><th>Suggested Schedule</th></tr></thead>
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
