{* View Hotels to Add as Products *}

{capture name="mainbox"}

<div class="well">
    <h4><i class="icon-plus-sign"></i> Add Hotels as Products</h4>
    <p class="muted">Hotels with active prices from Novoton that are not yet in CS-Cart as products.</p>
</div>

{* Country Filter *}
<form method="get" class="form-inline" style="margin-bottom: 20px;">
    <input type="hidden" name="dispatch" value="novoton_holidays.view_hotels_to_add">
    <label>Country:</label>
    <select name="country" onchange="this.form.submit()">
        {foreach from=$countries item=c}
        <option value="{$c.country}" {if $c.country == $country}selected{/if}>{$c.country} ({$c.cnt})</option>
        {/foreach}
    </select>
</form>

{* Statistics *}
<div class="row-fluid" style="margin-bottom: 20px;">
    <div class="span4">
        <div class="well well-small" style="text-align: center;">
            <h2 style="margin: 0; color: #f0ad4e;">{$hotels|@count}</h2>
            <p class="muted" style="margin: 0;">Hotels to Add (API)</p>
        </div>
    </div>
    <div class="span4">
        <div class="well well-small" style="text-align: center;">
            <h2 style="margin: 0; color: #5cb85c;">{$in_cart_count}</h2>
            <p class="muted" style="margin: 0;">Already in Database</p>
        </div>
    </div>
    <div class="span4">
        <div class="well well-small" style="text-align: center;">
            <h2 style="margin: 0; color: #5bc0de;">{$hotels|@count + $in_cart_count}</h2>
            <p class="muted" style="margin: 0;">Total with Prices</p>
        </div>
    </div>
</div>

{if $hotels|@count > 0}

{* Action Buttons *}
<div class="well">
    <h5>Add Hotels as Products</h5>
    <p class="muted">Configure import settings (category, languages, resorts) before adding:</p>

    <div style="margin-top: 15px;">
        <a href="{"novoton_holidays.add_hotels_as_products&country=`$country`"|fn_url}" class="btn btn-primary">
            <i class="icon-plus"></i> Configure &amp; Add {$hotels|@count} Hotels as Products
        </a>
        <a href="{"novoton_holidays.manage"|fn_url}" class="btn">
            <i class="icon-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

{* Hotels List *}
<table class="table table-striped table-condensed">
    <thead>
        <tr>
            <th width="8%">Hotel ID</th>
            <th width="25%">Hotel Name</th>
            <th width="15%">City</th>
            <th width="10%">Region</th>
            <th width="5%">Type</th>
            <th width="37%">Page Title Preview</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$hotels item=hotel name=hotels_loop}
        {if $smarty.foreach.hotels_loop.index < 100}
        <tr>
            <td><code>NVT{$hotel.hotel_id}</code></td>
            <td>{$hotel.hotel_name}</td>
            <td>{$hotel.city}</td>
            <td>{$hotel.region}</td>
            <td>{$hotel.hotel_type}</td>
            <td>
                <small class="muted">
                    {* Build preview title *}
                    {assign var="name_lower" value=$hotel.hotel_name|lower}
                    {if $name_lower|strpos:'villa' !== false || $name_lower|strpos:'aparthotel' !== false || $name_lower|strpos:'complex' !== false || $name_lower|strpos:'resort' !== false}
                        {$hotel.hotel_name|regex_replace:"/\s*\*+\s*/":""|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'},
                    {else}
                        Hotel {$hotel.hotel_name|regex_replace:"/\s*\*+\s*/":""|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'},
                    {/if}
                    {$hotel.city|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'},
                    {$hotel.country|mb_convert_case:constant('MB_CASE_TITLE'):'UTF-8'}
                    (prices {$current_year})
                </small>
            </td>
        </tr>
        {/if}
        {/foreach}
    </tbody>
</table>

{if $hotels|@count > 100}
<p class="muted">Showing first 100 of {$hotels|@count} hotels.</p>
{/if}

{else}
<div class="alert alert-success">
    <i class="icon-ok"></i> All hotels with active prices from {$country} are already in CS-Cart!
</div>
{/if}

{/capture}

{capture name="sidebar"}
<div class="sidebar-row">
    <h6>Cron Job</h6>
    <p class="muted" style="font-size: 11px;">
        Run weekly to check for new hotels:
    </p>
    <code style="font-size: 10px; word-break: break-all;">
        0 5 * * 0 curl -s "{$config.http_location}/index.php?dispatch=novoton_cron.run&amp;password=PASS&amp;mode=offers_update&amp;country=BULGARIA"
    </code>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title="View Hotels to Add - `$country`"
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
