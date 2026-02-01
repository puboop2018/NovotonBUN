{* Novoton Facilities List *}

<div class="well">
    <div class="row-fluid">
        <div class="span6">
            <h4><i class="icon-list"></i> Novoton Facilities</h4>
            <p class="muted">Hotel facilities/amenities from Novoton API. Used for product features.</p>
        </div>
        <div class="span6" style="text-align: right;">
            <p>
                <strong>Total:</strong> {$total_count} facilities<br>
                <strong>Last sync:</strong> {$last_sync|default:'Never'}
            </p>
            <a href="{fn_url('novoton_holidays.sync_facilities')}" class="btn btn-primary">
                <i class="icon-refresh"></i> Sync from API
            </a>
        </div>
    </div>
</div>

{if $facilities}
<table class="table table-striped table-condensed">
    <thead>
        <tr>
            <th width="10%">ID</th>
            <th width="70%">Facility Name</th>
            <th width="20%">Last Synced</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$facilities item=f}
        <tr>
            <td>{$f.facility_id}</td>
            <td>{$f.facility_name}</td>
            <td>{$f.synced_at|date_format:"%Y-%m-%d %H:%M"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
{else}
<div class="alert alert-info">
    <i class="icon-info-sign"></i> No facilities synced yet. Click "Sync from API" to load facilities.
</div>
{/if}
