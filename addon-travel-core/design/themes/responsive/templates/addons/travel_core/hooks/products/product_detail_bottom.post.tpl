{*
 * Travel Core: Booking Form + JSON-LD for hotel product pages.
 *
 * Variables assigned by dispatch_before_display PHP hook (cart_hooks.php):
 *   $travel_booking_product_id   - product ID (only for hotel products)
 *   $travel_booking_product_code - product code (NVT.../SPX...)
 *   $travel_booking_scripts      - array of React JS bundle URLs
 *   $travel_hotel_schema_json    - JSON-LD schema string
 *
 * This is the PRIMARY booking form injection point — it runs through
 * travel_core's own hook which is more reliable than cross-addon hooks.
 *}

{* ── DEBUG: Confirm this Smarty hook is rendering ── *}
<!-- [travel_core] product_detail_bottom.post.tpl LOADED -->
{if $travel_debug_enabled && $travel_debug_output}
<div id="travel-debug-panel" style="background:#1a1a2e;color:#0f0;font-family:monospace;font-size:12px;padding:15px;margin:10px 0;border:2px solid #e94560;border-radius:8px;max-height:500px;overflow-y:auto;white-space:pre-wrap;position:relative;z-index:9999;">
    <div style="color:#e94560;font-weight:bold;font-size:14px;margin-bottom:10px;">🔧 TRAVEL CORE DEBUG PANEL</div>
    <div style="color:#eee;margin-bottom:5px;">Smarty hook: <span style="color:#0f0">products/product_detail_bottom.post.tpl ✓ RENDERING</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_product_id: <span style="color:#0f0">{$travel_booking_product_id|default:'NOT SET'}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_product_code: <span style="color:#0f0">{$travel_booking_product_code|default:'NOT SET'}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_scripts: <span style="color:#0f0">{if $travel_booking_scripts}SET ({$travel_booking_scripts|count} scripts){else}NOT SET{/if}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_hotel_schema_json: <span style="color:#0f0">{if $travel_hotel_schema_json}SET ({$travel_hotel_schema_json|strlen} chars){else}NOT SET{/if}</span></div>
    <hr style="border-color:#333;margin:10px 0;">
    <details>
        <summary style="color:#e94560;cursor:pointer;">Full Debug Data (click to expand)</summary>
        <pre style="color:#0f0;margin-top:10px;">{$travel_debug_output|escape:'html'}</pre>
    </details>
</div>
{/if}

{* ── Booking Search Form (React mount point) ── *}
{if $travel_booking_product_id}
<div id="travel-booking-root"
     data-travel-booking
     data-product-id="{$travel_booking_product_id}"
     style="margin-bottom: 20px;">
    <div class="travel-loading-state">
        <div class="nvt-skeleton-row">
            <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
            <div class="nvt-skeleton-field"></div>
            <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
        </div>
    </div>
</div>
{if $travel_booking_scripts}
    {foreach $travel_booking_scripts as $script_url}
    <script src="{$script_url}" defer></script>
    {/foreach}
{/if}
{/if}

{* ── Client-side debug (only with ?travel_debug=1) ── *}
{if $travel_debug_enabled}
<script>
(function() {
    var results = [];
    function log(label, value, ok) {
        results.push({ label: label, value: value, ok: ok });
        console.log('[travel_core DEBUG] ' + label + ': ' + value + (ok ? ' ✓' : ' ✗'));
    }

    window.addEventListener('DOMContentLoaded', function() {
        // Check booking root
        var root = document.getElementById('travel-booking-root');
        log('Booking root #travel-booking-root', root ? 'FOUND' : 'NOT FOUND', !!root);
        if (root) {
            log('  data-product-id', root.dataset.productId || 'empty', !!root.dataset.productId);
            log('  data-provider', root.dataset.provider || 'empty', true);
            log('  innerHTML length', root.innerHTML.length + ' chars', true);
        }

        // Check scripts loaded
        var scripts = document.querySelectorAll('script[src]');
        var travelScripts = [];
        scripts.forEach(function(s) {
            if (s.src.indexOf('travel_core') !== -1) travelScripts.push(s.src);
        });
        log('travel_core scripts in DOM', travelScripts.length + ' found', travelScripts.length > 0);
        travelScripts.forEach(function(s) { log('  script', s, true); });

        // Check for React
        log('React loaded', typeof window.React !== 'undefined' ? 'YES' : 'NO', typeof window.React !== 'undefined');
        log('ReactDOM loaded', typeof window.ReactDOM !== 'undefined' ? 'YES' : 'NO', typeof window.ReactDOM !== 'undefined');

        // Check HTML comments for hook markers
        var html = document.documentElement.innerHTML;
        var hooks = [
            'product_detail_bottom.post.tpl',
            'scripts.post.tpl',
            'product_tabs.pre.tpl'
        ];
        hooks.forEach(function(hook) {
            log('Smarty hook ' + hook, html.indexOf(hook) !== -1 ? 'RENDERED' : 'NOT RENDERED', html.indexOf(hook) !== -1);
        });

        // Check JSON-LD
        var jsonld = document.querySelector('script[type="application/ld+json"]');
        log('JSON-LD schema', jsonld ? 'PRESENT' : 'MISSING', !!jsonld);

        // Check CSS
        var stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
        var travelCSS = false;
        stylesheets.forEach(function(s) {
            if (s.href.indexOf('travel_core') !== -1) travelCSS = true;
        });
        log('travel_core CSS', travelCSS ? 'LOADED' : 'NOT LOADED', travelCSS);

        // Print summary table to console
        console.table(results);

        // Show floating summary badge
        var badge = document.createElement('div');
        badge.id = 'travel-debug-badge';
        var ok = results.filter(function(r) { return r.ok; }).length;
        var fail = results.filter(function(r) { return !r.ok; }).length;
        badge.innerHTML = '🔧 Travel Debug: ' + ok + ' OK, ' + fail + ' issues';
        badge.style.cssText = 'position:fixed;bottom:10px;right:10px;background:#1a1a2e;color:' + (fail > 0 ? '#e94560' : '#0f0') + ';padding:8px 16px;border-radius:20px;font-family:monospace;font-size:12px;z-index:99999;cursor:pointer;border:1px solid #e94560;';
        badge.title = 'Click to scroll to debug panel';
        badge.onclick = function() {
            var panel = document.getElementById('travel-debug-panel');
            if (panel) panel.scrollIntoView({ behavior: 'smooth' });
        };
        document.body.appendChild(badge);
    });

    // Check script load errors
    window.addEventListener('error', function(e) {
        if (e.target && e.target.tagName === 'SCRIPT' && e.target.src && e.target.src.indexOf('travel_core') !== -1) {
            console.error('[travel_core DEBUG] Script FAILED to load: ' + e.target.src);
        }
    }, true);
})();
</script>
{/if}

{* ── JSON-LD Structured Data ── *}
{if $travel_hotel_schema_json}
<script type="application/ld+json">
{$travel_hotel_schema_json nofilter}
</script>
{/if}
