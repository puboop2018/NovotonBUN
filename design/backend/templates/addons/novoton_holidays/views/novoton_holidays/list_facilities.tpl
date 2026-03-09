{* Novoton Facilities List *}

<div class="well">
    <div class="row-fluid">
        <div class="span6">
            <h4><i class="icon-list"></i> Novoton Facilities</h4>
            <p class="muted">Hotel facilities/amenities from Novoton API. Classify each as "hotel" or "room" level.</p>
        </div>
        <div class="span6" style="text-align: right;">
            <p>
                <strong>Total:</strong> {$facilities_count} facilities<br>
                <strong>Last sync:</strong> {$last_sync|default:'Never'}
            </p>
            <a href="{fn_url('novoton_holidays.sync_facilities')}" class="btn btn-primary">
                <i class="icon-refresh"></i> Sync Facilities List
            </a>
            <a href="{fn_url('novoton_holidays.sync_hotel_facilities')}" class="btn btn-success">
                <i class="icon-link"></i> Sync Hotel Facilities
            </a>
        </div>
    </div>
</div>

{if $facilities}
<form action="{fn_url('novoton_holidays.save_facilities')}" method="post">
<input type="hidden" name="security_hash" value="{$security_hash}">
<table class="table table-striped table-condensed">
    <thead>
        <tr>
            <th width="6%">ID</th>
            <th width="30%">Facility Name (EN)</th>
            <th width="32%">Facility Name (RO)</th>
            <th width="14%">Type</th>
            <th width="12%">Last Synced</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$facilities item=f}
        <tr>
            <td>{$f.facility_id}</td>
            <td>{$f.facility_name_en}</td>
            <td>
                <input type="text" name="facility_translations[{$f.facility_id}]"
                       value="{$f.facility_name_ro|escape:'html'}"
                       class="input-xlarge" style="width: 95%;"
                       placeholder="{$f.facility_name_en|escape:'html'}" />
            </td>
            <td>
                <select name="facility_types[{$f.facility_id}]" class="input-small" style="width: 90px;">
                    <option value="hotel"{if $f.facility_type == 'hotel'} selected{/if}>Hotel</option>
                    <option value="room"{if $f.facility_type == 'room'} selected{/if}>Room</option>
                </select>
            </td>
            <td>{$f.synced_at|date_format:"%Y-%m-%d %H:%M"}</td>
        </tr>
        {/foreach}
    </tbody>
</table>
<div style="text-align: right; margin-top: 10px;">
    <button type="submit" class="btn btn-primary">
        <i class="icon-ok"></i> Save Changes
    </button>
</div>
</form>
{else}
<div class="alert alert-info">
    <i class="icon-info-sign"></i> No facilities synced yet. Click "Sync from API" to load facilities.
</div>
{/if}
