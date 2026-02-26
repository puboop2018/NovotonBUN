{** Add Hotels as Products - Configuration Form **}

{capture name="mainbox"}

<style>
{literal}
.add-products-form { max-width: 900px; }
.add-products-form .section { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.add-products-form .section h3 { margin: 0 0 15px 0; color: #003580; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; }
.add-products-form .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
.add-products-form .stat-item { background: #fff; padding: 15px; border-radius: 6px; text-align: center; border: 1px solid #e0e0e0; }
.add-products-form .stat-number { font-size: 28px; font-weight: bold; color: #003580; }
.add-products-form .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
.add-products-form .resort-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; max-height: 300px; overflow-y: auto; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
.add-products-form .resort-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: #f8f9fa; border-radius: 4px; font-size: 12px; }
.add-products-form .resort-item input { margin: 0; }
.add-products-form .resort-count { color: #666; font-size: 11px; }
.add-products-form .country-tabs { margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap; }
.add-products-form .country-tabs a { display: inline-block; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: bold; }
.add-products-form .country-tabs a.active { background: #003580; color: white; }
.add-products-form .country-tabs a:not(.active) { background: #e8e8e8; color: #003580; }
.add-products-form .country-tabs a:not(.active):hover { background: #d0d0d0; }
{/literal}
</style>

<div class="add-products-form">

    {** Country selector tabs **}
    {if $available_countries|count > 1}
    <div class="country-tabs">
        {foreach from=$available_countries item=c}
            <a href="{"novoton_holidays.add_hotels_as_products&country=`$c`"|fn_url}"
               {if $c == $country}class="active"{/if}>{$c}</a>
        {/foreach}
    </div>
    {/if}

    <form action="{"novoton_holidays.add_hotels_as_products"|fn_url}" method="post">
        <input type="hidden" name="security_hash" value="{$security_hash}">
        <input type="hidden" name="run" value="1">
        <input type="hidden" name="country" value="{$country}">

        {** Statistics **}
        <div class="section">
            <h3>Statistics for {$country}</h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <div class="stat-number">{$stats.total|default:0}</div>
                    <div class="stat-label">Total Hotels</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #28a745;">{$stats.with_prices|default:0}</div>
                    <div class="stat-label">With Prices</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #17a2b8;">{$stats.already_products|default:0}</div>
                    <div class="stat-label">Already Products</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #fd7e14;">{$stats.to_add|default:0}</div>
                    <div class="stat-label">To Add</div>
                </div>
            </div>
        </div>

        {** Import Settings **}
        <div class="section">
            <h3>Import Settings</h3>

            <div class="control-group">
                <label class="control-label">Category:</label>
                <div class="controls">
                    <select name="category_id" class="input-large" required>
                        <option value="">-- Select Category --</option>
                        {foreach from=$categories item=cat}
                        <option value="{$cat.category_id}">{$cat.category}</option>
                        {/foreach}
                    </select>
                    <p class="muted">Select the category where hotel products will be created</p>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label">Import Mode:</label>
                <div class="controls">
                    <label class="radio inline">
                        <input type="radio" name="import_mode" value="new_only" checked> New hotels only
                    </label>
                    <label class="radio inline">
                        <input type="radio" name="import_mode" value="update"> All hotels (update existing)
                    </label>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label">Languages:</label>
                <div class="controls">
                    {foreach from=$languages item=lang}
                    <label class="checkbox inline">
                        <input type="checkbox" name="languages[]" value="{$lang.lang_code}"
                               {if $lang.lang_code == 'en' || $lang.lang_code == 'ro'}checked{/if}>
                        {$lang.name}
                    </label>
                    {/foreach}
                </div>
            </div>

            <div class="control-group">
                <label class="control-label">Limit:</label>
                <div class="controls">
                    <input type="number" name="limit" value="50" min="0" max="5000" class="input-small">
                    <p class="muted">0 = no limit (process all)</p>
                </div>
            </div>
        </div>

        {** Resort Selection **}
        {if $resorts}
        <div class="section">
            <h3>Select Resorts</h3>
            <p class="muted">Leave all unchecked to import from all resorts, or select specific resorts:</p>
            <div style="margin-bottom: 10px;">
                <button type="button" onclick="toggleAllResorts(true)" class="btn btn-small">Select All</button>
                <button type="button" onclick="toggleAllResorts(false)" class="btn btn-small">Deselect All</button>
            </div>
            <div class="resort-grid">
                {foreach from=$resorts item=resort}
                <label class="resort-item">
                    <input type="checkbox" name="resorts[]" value="{$resort.city|escape:'html'}">
                    <span>{$resort.city}</span>
                    <span class="resort-count">({$resort.with_prices}/{$resort.hotel_count})</span>
                </label>
                {/foreach}
            </div>
        </div>
        {/if}

        {** Submit **}
        <div class="buttons-container">
            <button type="submit" class="btn btn-primary btn-large">
                <i class="icon-plus"></i> Start Import
            </button>
            <a href="{"novoton_holidays.manage"|fn_url}" class="btn">Cancel</a>
        </div>
    </form>
</div>

<script>
function toggleAllResorts(checked) {
    document.querySelectorAll('input[name="resorts[]"]').forEach(function(cb) {
        cb.checked = checked;
    });
}
</script>

{/capture}

{capture name="buttons"}
    <a class="btn" href="{"novoton_holidays.manage"|fn_url}">
        Back to Dashboard
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title="Add Hotels as Products - `$country`"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
