{** Novoton Holidays Dashboard **}

{capture name="mainbox"}

<style>
{literal}
.novoton-dashboard { }
.novoton-stats { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 25px; }
.novoton-stat-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; min-width: 180px; flex: 1; }
.novoton-stat-card h3 { margin: 0 0 15px 0; color: #003580; font-size: 14px; text-transform: uppercase; }
.novoton-stat-number { font-size: 32px; font-weight: bold; color: #333; }
.novoton-stat-label { font-size: 12px; color: #666; margin-top: 5px; }
.novoton-stat-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.novoton-stat-row:last-child { border-bottom: none; }

.novoton-actions { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
.novoton-actions h3 { margin: 0 0 15px 0; color: #333; }
.novoton-btn-group { display: flex; flex-wrap: wrap; gap: 10px; }
.novoton-btn { display: inline-block; padding: 10px 20px; background: #003580; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; }
.novoton-btn:hover { background: #00264d; color: #fff; }
.novoton-btn-secondary { background: #6c757d; }
.novoton-btn-secondary:hover { background: #5a6268; }
.novoton-btn-success { background: #28a745; }
.novoton-btn-success:hover { background: #218838; }
.novoton-btn-warning { background: #ffc107; color: #333; }
.novoton-btn-warning:hover { background: #e0a800; }

.novoton-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
.novoton-section h3 { margin: 0 0 15px 0; color: #003580; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; }

.novoton-cron-url { background: #f5f5f5; padding: 8px 12px; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all; margin: 5px 0; }
.novoton-table { width: 100%; border-collapse: collapse; }
.novoton-table th, .novoton-table td { padding: 10px; text-align: left; border-bottom: 1px solid #e0e0e0; }
.novoton-table th { background: #f8f9fa; font-weight: 600; color: #333; }
.novoton-table tr:hover { background: #f8f9fa; }

.novoton-country-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
.novoton-country-card { background: #f8f9fa; border-radius: 6px; padding: 15px; }
.novoton-country-card h4 { margin: 0 0 10px 0; color: #003580; }

.novoton-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.novoton-badge-success { background: #d4edda; color: #155724; }
.novoton-badge-warning { background: #fff3cd; color: #856404; }
.novoton-badge-info { background: #d1ecf1; color: #0c5460; }
{/literal}
</style>

<div class="novoton-dashboard">
    
    {** Statistics Cards **}
    <div class="novoton-stats">
        <div class="novoton-stat-card">
            <h3>[H] Hotels</h3>
            <div class="novoton-stat-number">{$stats.hotels.total|default:0}</div>
            <div class="novoton-stat-row">
                <span>Real-time (room_price) available</span>
                <span class="novoton-badge novoton-badge-success">{$stats.hotels.with_prices|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Season prices (priceinfo) available</span>
                <span class="novoton-badge novoton-badge-success">{$stats.hotels.with_packages|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>As Products</span>
                <span class="novoton-badge novoton-badge-info">{$stats.hotels.with_products|default:0}</span>
            </div>
        </div>
        
        <div class="novoton-stat-card">
            <h3>[L] Bookings</h3>
            <div class="novoton-stat-number">{$stats.bookings.total|default:0}</div>
            <div class="novoton-stat-row">
                <span>Pending</span>
                <span class="novoton-badge novoton-badge-warning">{$stats.bookings.pending|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Confirmed</span>
                <span class="novoton-badge novoton-badge-success">{$stats.bookings.confirmed|default:0}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Cancelled</span>
                <span>{$stats.bookings.cancelled|default:0}</span>
            </div>
        </div>
        
        <div class="novoton-stat-card">
            <h3>[S] Last Sync</h3>
            <div class="novoton-stat-row">
                <span>Hotel List</span>
                <span>{if $last_syncs.hotellist}{$last_syncs.hotellist|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Hotel Info</span>
                <span>{if $last_syncs.hotelinfo}{$last_syncs.hotelinfo|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Prices</span>
                <span>{if $last_syncs.prices}{$last_syncs.prices|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Offers Update</span>
                <span>{if $last_syncs.offers_update}{$last_syncs.offers_update|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Facilities</span>
                <span>{if $last_syncs.facilities}{$last_syncs.facilities|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
            <div class="novoton-stat-row">
                <span>Resort List</span>
                <span>{if $last_syncs.resort_list}{$last_syncs.resort_list|date_format:"%d.%m %H:%M"}{else}Never{/if}</span>
            </div>
        </div>
    </div>
    
    {** Quick Actions **}
    <div class="novoton-actions">
        <h3>Actions</h3>
        <div class="novoton-btn-group">
            <a href="{"novoton_prices.check_prices"|fn_url}" class="novoton-btn novoton-btn-secondary">$ Check Prices</a>
            <a href="{"novoton_prices.check_prices_hotel"|fn_url}" class="novoton-btn novoton-btn-secondary">$ Check Prices (Per-Hotel)</a>
            <a href="{"novoton_holidays.check_packages"|fn_url}" class="novoton-btn novoton-btn-secondary">[P] Check Packages</a>
            <a href="{"novoton_holidays.add_hotels_as_products"|fn_url}" class="novoton-btn novoton-btn-success">+ Add Hotels as Products</a>
            <a href="{"novoton_bookings.manage"|fn_url}" class="novoton-btn novoton-btn-secondary">[L] Manage Bookings</a>
            <a href="{"novoton_alternatives.manage"|fn_url}" class="novoton-btn novoton-btn-warning">[R] Alternative Requests</a>
            <a href="{"novoton_holidays.export_hotel_features_csv"|fn_url}" class="novoton-btn novoton-btn-secondary">[CSV] Export Hotel Features</a>
            <a href="{"novoton_holidays.download_hotel_features_csv"|fn_url}" class="novoton-btn novoton-btn-info" style="font-size:11px;">[CSV] Download</a>
            <a href="{"novoton_holidays.export_hotel_features_xml"|fn_url}" class="novoton-btn novoton-btn-secondary">[XML] Export Hotel Features</a>
            <a href="{"novoton_holidays.download_hotel_features_xml"|fn_url}" class="novoton-btn novoton-btn-info" style="font-size:11px;">[XML] Download</a>
            <a href="{"novoton_tools.test_api"|fn_url}" class="novoton-btn novoton-btn-warning" target="_blank">[T] Test API</a>
            <a href="{"novoton_diagnostic.health"|fn_url}" class="novoton-btn novoton-btn-info" target="_blank">[H] Health Check</a>
            <a href="{"novoton_price_compare.manage"|fn_url}" class="novoton-btn novoton-btn-secondary">[C] Price Comparison Tool</a>
        </div>
    </div>
    
    {** Country Statistics **}
    {if $stats.by_country}
    <div class="novoton-section">
        <h3>[W] Statistics by Country</h3>
        <div class="novoton-country-grid">
            {foreach from=$stats.by_country key=country item=country_stats}
            {if $country_stats.with_prices > 0 || $country_stats.with_packages > 0}
            <div class="novoton-country-card">
                <h4>{$country}</h4>
                <div class="novoton-stat-row">
                    <span>Total Hotels</span>
                    <span><strong>{$country_stats.total}</strong></span>
                </div>
                <div class="novoton-stat-row">
                    <span>Real-time (room_price) available</span>
                    <span class="novoton-badge novoton-badge-success">{$country_stats.with_prices}</span>
                </div>
                <div class="novoton-stat-row">
                    <span>Season prices (priceinfo) available</span>
                    <span class="novoton-badge novoton-badge-success">{$country_stats.with_packages}</span>
                </div>
                <div class="novoton-stat-row">
                    <span>As Products</span>
                    <span class="novoton-badge novoton-badge-info">{$country_stats.with_products}</span>
                </div>
                <div style="margin-top: 10px;">
                    <a href="{"novoton_holidays.add_hotels_as_products?country=`$country`"|fn_url}" class="novoton-btn" style="font-size: 11px; padding: 6px 12px;">Add as Products -></a>
                </div>
            </div>
            {/if}
            {/foreach}
        </div>
    </div>
    {/if}
    
    {** Cron URLs **}
    <div class="novoton-section">
        <h3>[C] Cron Job URLs</h3>
        {if $cron_key}
        <p style="color: #666; margin-bottom: 15px;">Copy these URLs for use in cPanel cron jobs or external cron services:</p>

        {* Recommended Batched Sync - Highlighted *}
        <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 2px solid #28a745; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span style="background: #28a745; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">RECOMMENDED</span>
                <strong style="font-size: 16px;">Hotel Info Batched</strong>
            </div>
            <p style="color: #155724; margin-bottom: 10px; font-size: 13px;">
                Smart sync with resume capability. First run syncs all hotels, then daily syncs only new/changed hotels.
                Automatically does full re-sync every 6 months.
            </p>
            <div class="novoton-cron-url" style="background: white; padding: 10px; border-radius: 4px; margin-bottom: 10px;">{$cron_urls.hotel_info_batched}</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{$cron_urls.hotel_info_batched}" target="_blank" class="novoton-btn novoton-btn-success">Run Now</a>
                <a href="{$cron_urls.hotel_info_batched}&status=1" target="_blank" class="novoton-btn">Check Status</a>
                <a href="{$cron_urls.hotel_info_batched}&force_full=1" target="_blank" class="novoton-btn">Force Full Sync</a>
                <a href="{$cron_urls.hotel_info_batched}&reset=1" target="_blank" class="novoton-btn" style="background: #dc3545; color: white;">Reset</a>
            </div>
            <p style="color: #666; margin-top: 10px; font-size: 12px;">
                <strong>cPanel:</strong> <code>*/5 * * * *</code> (every 5 min) |
                <strong>Shared hosting:</strong> Add <code>&batch_size=50&max_time=120</code>
            </p>
        </div>

        {* Recommended Batched Priceinfo Sync - Highlighted *}
        <div style="background: linear-gradient(135deg, #cce5ff 0%, #b8daff 100%); border: 2px solid #0066cc; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span style="background: #0066cc; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">RECOMMENDED</span>
                <strong style="font-size: 16px;">Price Info Batched</strong>
            </div>
            <p style="color: #004085; margin-bottom: 10px; font-size: 13px;">
                Smart price sync with resume capability. Syncs all package prices in batches.
                Automatically re-syncs stale packages (older than 24h) and does full re-sync every 7 days.
            </p>
            <div class="novoton-cron-url" style="background: white; padding: 10px; border-radius: 4px; margin-bottom: 10px;">{$cron_urls.sync_priceinfo_batched}</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{$cron_urls.sync_priceinfo_batched}" target="_blank" class="novoton-btn" style="background: #0066cc;">Run Now</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&status=1" target="_blank" class="novoton-btn">Check Status</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&force_full=1" target="_blank" class="novoton-btn">Force Full Sync</a>
                <a href="{$cron_urls.sync_priceinfo_batched}&reset=1" target="_blank" class="novoton-btn" style="background: #dc3545; color: white;">Reset</a>
            </div>
            <p style="color: #666; margin-top: 10px; font-size: 12px;">
                <strong>cPanel:</strong> <code>*/5 * * * *</code> (every 5 min) |
                <strong>Shared hosting:</strong> Add <code>&batch_size=30&max_time=120</code>
            </p>
        </div>

        {* Other Cron Jobs Table *}
        <p style="color: #666; margin-bottom: 10px;"><strong>Other Cron Jobs:</strong></p>
        <table class="novoton-table">
            <tr>
                <th style="width: 180px;">Job</th>
                <th>URL</th>
                <th style="width: 100px;">Schedule</th>
                <th style="width: 60px;">Run</th>
            </tr>
            <tr>
                <td><strong>Hotel List</strong><br><small style="color:#666;">Basic hotel data</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.hotel_list}</div></td>
                <td>Daily</td>
                <td><a href="{$cron_urls.hotel_list}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Facilities</strong><br><small style="color:#666;">Sync facilities list</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.list_facilities}</div></td>
                <td>Weekly</td>
                <td><a href="{$cron_urls.list_facilities}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Resort List</strong><br><small style="color:#666;">Sync resort names</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.resort_list}</div></td>
                <td>Weekly</td>
                <td><a href="{$cron_urls.resort_list}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Booking Status</strong><br><small style="color:#666;">Check ASK bookings</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.resinfo}</div></td>
                <td>Every 2 hours</td>
                <td><a href="{$cron_urls.resinfo}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Offers Update</strong><br><small style="color:#666;">Check new offers</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.offers_update}</div></td>
                <td>Every 2 hours</td>
                <td><a href="{$cron_urls.offers_update}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Add Products</strong><br><small style="color:#666;">Create CS-Cart products</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.add_products}</div></td>
                <td>After sync</td>
                <td><a href="{$cron_urls.add_products}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
            <tr>
                <td><strong>Exchange Rates</strong><br><small style="color:#666;">Update BNR rates</small></td>
                <td><div class="novoton-cron-url">{$cron_urls.exchange_rates}</div></td>
                <td>Daily</td>
                <td><a href="{$cron_urls.exchange_rates}" target="_blank" class="novoton-btn" style="font-size:11px;padding:4px 10px;">Run</a></td>
            </tr>
        </table>
        {else}
        <div style="background: #fff3cd; padding: 15px; border-radius: 4px; color: #856404;">
            <strong>[!] Cron Access Key Not Set</strong><br>
            Please set the <strong>Cron Access Key</strong> in <a href="{"addons.update?addon=novoton_holidays"|fn_url}">addon settings</a> to enable cron jobs.
        </div>
        {/if}
    </div>
    
    {** Excluded Resorts Management **}
    <div class="novoton-section">
        <h3>[E] Excluded Resorts</h3>
        <p style="color: #666; margin-bottom: 15px;">Select resorts to EXCLUDE when adding hotels as products. Hotels from excluded resorts will be skipped.</p>
        
        {if $resorts_by_country}
        <form action="{"novoton_holidays.save_excluded_resorts"|fn_url}" method="post" id="excluded-resorts-form">
            
            {* Search and Filter Controls *}
            <div style="display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                {* Search Input *}
                <div style="flex: 1; min-width: 200px;">
                    <input type="text" id="resort-search" placeholder="Search resorts..." 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;"
                           onkeyup="filterResorts()">
                </div>
                
                {* Country Filter *}
                <div>
                    <select id="country-filter" onchange="filterResorts()" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; min-width: 150px;">
                        <option value="">All Countries</option>
                        {foreach from=$resorts_by_country key=country item=resorts}
                        <option value="{$country|escape:'html'}">{$country} ({$resorts|count})</option>
                        {/foreach}
                    </select>
                </div>
                
                {* Quick Actions *}
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-small" onclick="selectAllVisible()">Select Visible</button>
                    <button type="button" class="btn btn-small" onclick="deselectAllVisible()">Deselect Visible</button>
                </div>
            </div>
            
            {* Resorts List *}
            <div id="resorts-container" style="max-height: 450px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px; padding: 15px; background: #f9f9f9;">
                {foreach from=$resorts_by_country key=country item=resorts}
                <div class="country-group" data-country="{$country|escape:'html'}" style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; color: #003580; border-bottom: 1px solid #ddd; padding-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">
                        <span>
                            {$country}
                            <span style="font-weight: normal; font-size: 12px; color: #666;">({$resorts|count} resorts)</span>
                        </span>
                        <span style="font-size: 11px;">
                            <a href="javascript:void(0)" onclick="selectCountry('{$country|escape:'javascript'}')" style="color: #003580;">Select all</a> | 
                            <a href="javascript:void(0)" onclick="deselectCountry('{$country|escape:'javascript'}')" style="color: #666;">Deselect all</a>
                        </span>
                    </h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        {foreach from=$resorts item=resort}
                        <label class="resort-item" data-resort="{$resort|lower|escape:'html'}" data-country="{$country|escape:'html'}"
                               style="display: inline-flex; align-items: center; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 12px; transition: all 0.2s;">
                            <input type="checkbox" name="excluded_resorts[]" value="{$resort|escape:'html'}" 
                                   {if in_array($resort, $excluded_resorts)}checked{/if}
                                   style="margin-right: 6px;">
                            {$resort}
                        </label>
                        {/foreach}
                    </div>
                </div>
                {/foreach}
            </div>
            
            {* No results message *}
            <div id="no-results" style="display: none; padding: 20px; text-align: center; color: #666;">
                No resorts match your search criteria.
            </div>
            
            {* Summary and Save *}
            <div style="margin-top: 15px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">Save Excluded Resorts</button>
                <span style="color: #666; font-size: 12px;">
                    Currently excluded: <strong id="excluded-count">{$excluded_resorts|count}</strong> resort(s)
                </span>
                <span id="visible-count" style="color: #999; font-size: 12px;"></span>
            </div>
        </form>
        
        <script>
        {literal}
        function filterResorts() {
            var search = document.getElementById('resort-search').value.toLowerCase();
            var country = document.getElementById('country-filter').value;
            var items = document.querySelectorAll('.resort-item');
            var groups = document.querySelectorAll('.country-group');
            var visibleCount = 0;
            var visibleGroups = {};
            
            // Filter individual items
            items.forEach(function(item) {
                var resortName = item.getAttribute('data-resort');
                var resortCountry = item.getAttribute('data-country');
                var matchSearch = !search || resortName.indexOf(search) !== -1;
                var matchCountry = !country || resortCountry === country;
                
                if (matchSearch && matchCountry) {
                    item.style.display = 'inline-flex';
                    visibleCount++;
                    visibleGroups[resortCountry] = true;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show/hide country groups based on visible items
            groups.forEach(function(group) {
                var groupCountry = group.getAttribute('data-country');
                if (visibleGroups[groupCountry]) {
                    group.style.display = 'block';
                } else {
                    group.style.display = 'none';
                }
            });
            
            // Update visible count
            document.getElementById('visible-count').textContent = '(Showing ' + visibleCount + ' resorts)';
            
            // Show/hide no results message
            document.getElementById('no-results').style.display = visibleCount === 0 ? 'block' : 'none';
            document.getElementById('resorts-container').style.display = visibleCount === 0 ? 'none' : 'block';
        }
        
        function selectAllVisible() {
            document.querySelectorAll('.resort-item').forEach(function(item) {
                if (item.style.display !== 'none') {
                    item.querySelector('input[type="checkbox"]').checked = true;
                }
            });
            updateExcludedCount();
        }
        
        function deselectAllVisible() {
            document.querySelectorAll('.resort-item').forEach(function(item) {
                if (item.style.display !== 'none') {
                    item.querySelector('input[type="checkbox"]').checked = false;
                }
            });
            updateExcludedCount();
        }
        
        function selectCountry(country) {
            document.querySelectorAll('.resort-item[data-country="' + country + '"]').forEach(function(item) {
                if (item.style.display !== 'none') {
                    item.querySelector('input[type="checkbox"]').checked = true;
                }
            });
            updateExcludedCount();
        }
        
        function deselectCountry(country) {
            document.querySelectorAll('.resort-item[data-country="' + country + '"]').forEach(function(item) {
                item.querySelector('input[type="checkbox"]').checked = false;
            });
            updateExcludedCount();
        }
        
        function updateExcludedCount() {
            var count = document.querySelectorAll('#excluded-resorts-form input[name="excluded_resorts[]"]:checked').length;
            document.getElementById('excluded-count').textContent = count;
        }
        
        // Update count when checkboxes change
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#excluded-resorts-form input[type="checkbox"]').forEach(function(cb) {
                cb.addEventListener('change', updateExcludedCount);
            });
        });
        {/literal}
        </script>
        
        {else}
        <div style="background: #fff3cd; padding: 15px; border-radius: 4px; color: #856404;">
            <strong>No resorts found.</strong><br>
            Run the "Hotel List Sync" cron job first to load resort data from the API.
        </div>
        {/if}
    </div>
    
    {** Recent Sync Logs **}
    {if $recent_syncs}
    <div class="novoton-section">
        <h3>[A] Recent Sync Activity</h3>
        <table class="novoton-table">
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Total</th>
                <th>Updated</th>
                <th>Failed</th>
                <th>Status</th>
                <th>Duration</th>
            </tr>
            {foreach from=$recent_syncs item=log}
            <tr>
                <td>{$log.sync_date|date_format:"%d.%m.%Y %H:%M"}</td>
                <td><span class="novoton-badge novoton-badge-info">{$log.sync_type}</span></td>
                <td>{$log.products_total}</td>
                <td>{$log.products_updated}</td>
                <td style="color: {if $log.products_failed > 0}red{else}#999{/if};">{$log.products_failed}</td>
                <td>
                    {if $log.status == 'completed'}
                        <span class="novoton-badge novoton-badge-success">OK</span>
                    {elseif $log.status == 'failed'}
                        <span class="novoton-badge novoton-badge-danger">Failed</span>
                    {elseif $log.status == 'running'}
                        <span class="novoton-badge novoton-badge-warning">Running</span>
                    {else}
                        <span class="novoton-badge">{$log.status}</span>
                    {/if}
                </td>
                <td>{$log.duration_seconds}s</td>
            </tr>
            {/foreach}
        </table>
    </div>
    {/if}

</div>

{/capture}

{capture name="buttons"}
    <a class="btn btn-primary" href="{"addons.update?addon=novoton_holidays"|fn_url}">
        {__("settings")}
    </a>
{/capture}

{include file="common/mainbox.tpl"
    title="Novoton Holidays Dashboard{if $addon_version} v{$addon_version}{/if}"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
