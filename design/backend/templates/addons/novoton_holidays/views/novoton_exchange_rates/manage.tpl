{* Novoton Holidays - Exchange Rates Management *}

{capture name="mainbox"}

<div class="control-group">
    <h4>{__("novoton_holidays.exchange_rates_info")}</h4>

    <table class="table table-middle">
        <thead>
            <tr>
                <th>{__("currency")}</th>
                <th>{__("novoton_holidays.coefficient")}</th>
                <th>{__("novoton_holidays.is_primary")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$exchange_info.currencies key=code item=currency}
            <tr>
                <td><strong>{$code}</strong></td>
                <td>
                    {if $currency.is_primary}
                        1.0000 <span class="muted">({__("novoton_holidays.primary_currency")})</span>
                    {else}
                        {$currency.coefficient|number_format:4}
                    {/if}
                </td>
                <td>
                    {if $currency.is_primary}
                        <span class="label label-success">{__("yes")}</span>
                    {else}
                        <span class="label">{__("no")}</span>
                    {/if}
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>
</div>

<div class="control-group">
    <h4>{__("novoton_holidays.update_info")}</h4>

    <dl class="dl-horizontal">
        <dt>{__("novoton_holidays.last_update")}:</dt>
        <dd>
            {if $exchange_info.last_update && $exchange_info.last_update != 'Never'}
                {$exchange_info.last_update}
            {else}
                <span class="muted">{__("never")}</span>
            {/if}
        </dd>

        <dt>{__("novoton_holidays.currency_risk_commission")}:</dt>
        <dd>{$exchange_info.commission}%</dd>

        <dt>{__("novoton_holidays.data_source")}:</dt>
        <dd>
            <a href="https://curs.bnr.ro/nbrfxrates.xml" target="_blank">
                BNR - National Bank of Romania
            </a>
        </dd>
    </dl>
</div>

<div class="control-group">
    <h4>{__("novoton_holidays.manual_update")}</h4>

    <p class="muted">{__("novoton_holidays.manual_update_description")}</p>

    <a href="{"novoton_exchange_rates.update"|fn_url}" class="btn btn-primary">
        <i class="icon-refresh"></i>
        {__("novoton_holidays.update_rates_now")}
    </a>
</div>

<div class="control-group">
    <h4>{__("novoton_holidays.cron_setup")}</h4>

    <p class="muted">{__("novoton_holidays.cron_setup_description")}</p>

    {if $cron_password}
        <div class="well">
            <p><strong>{__("novoton_holidays.cron_url_frontend")}:</strong> <span class="muted">({__("novoton_holidays.recommended")})</span></p>
            <code style="word-break: break-all;">{$cron_url_frontend}</code>

            <p class="top-padding"><strong>{__("novoton_holidays.cron_url_admin")}:</strong></p>
            <code style="word-break: break-all;">{$cron_url_admin}</code>

            <p class="top-padding"><strong>{__("novoton_holidays.cron_command")}:</strong></p>
            <code>5 13 * * * curl -s "{$cron_url_frontend}" > /dev/null 2>&1</code>

            <p class="muted top-padding">
                {__("novoton_holidays.cron_schedule_note")}
            </p>
        </div>
    {else}
        <div class="alert alert-warning">
            {__("novoton_holidays.cron_password_not_set")}
            <a href="{"addons.update?addon=novoton_holidays"|fn_url}">{__("novoton_holidays.configure_settings")}</a>
        </div>
    {/if}
</div>

<div class="control-group">
    <h4>{__("novoton_holidays.how_it_works")}</h4>

    <ol>
        <li>{__("novoton_holidays.how_it_works_step1")}</li>
        <li>{__("novoton_holidays.how_it_works_step2")}</li>
        <li>{__("novoton_holidays.how_it_works_step3")}</li>
        <li>{__("novoton_holidays.how_it_works_step4")}</li>
    </ol>
</div>

{/capture}

{capture name="buttons"}
    {include file="common/popupbox.tpl"
        id="exchange_rates_help"
        text=__("novoton_holidays.exchange_rates_help_title")
        link_text=__("help")
        content=$smarty.capture.help_content
        act="general"
    }
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.exchange_rates")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
