/**
 * Travel Core - Debug diagnostic script.
 * Only loaded when ?travel_debug=1 is appended to URL.
 */
(function() {
    var results = [];
    function log(label, value, ok) {
        results.push({ label: label, value: value, ok: ok });
        console.log('[travel_core DEBUG] ' + label + ': ' + value + (ok ? ' OK' : ' FAIL'));
    }

    window.addEventListener('DOMContentLoaded', function() {
        var root = document.getElementById('travel-booking-root');
        log('Booking root #travel-booking-root', root ? 'FOUND' : 'NOT FOUND', !!root);
        if (root) {
            log('  data-product-id', root.dataset.productId || 'empty', !!root.dataset.productId);
            log('  data-provider', root.dataset.provider || '(none - AJAX mode)', true);
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
        ['product_tabs.pre.tpl', 'product_detail_bottom.post.tpl', 'scripts.post.tpl'].forEach(function(hook) {
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

        // Test booking_config AJAX endpoint
        var productId = root ? root.dataset.productId : '';
        if (productId) {
            var baseUrl = (window.Tygh && window.Tygh.current_location || window.location.origin) + '/index.php';
            var configUrl = baseUrl + '?dispatch=travel_booking.booking_config&product_id=' + encodeURIComponent(productId);
            log('booking_config URL', configUrl, true);
            fetch(configUrl).then(function(r) {
                log('booking_config HTTP status', r.status + ' ' + r.statusText, r.ok);
                log('booking_config Content-Type', r.headers.get('content-type') || 'missing', (r.headers.get('content-type') || '').indexOf('json') !== -1);
                return r.text();
            }).then(function(text) {
                log('booking_config response length', text.length + ' chars', text.length > 0);
                var firstChar = text.charAt(0);
                var isJson = firstChar === '{';
                log('booking_config starts with brace', isJson ? 'YES (valid JSON start)' : 'NO: "' + text.substring(0, 80) + '"', isJson);
                try {
                    var data = JSON.parse(text);
                    log('booking_config JSON parse', 'OK', true);
                    log('booking_config isHotel', String(data.isHotel), data.isHotel === true);
                    log('booking_config provider', data.provider || 'missing', !!data.provider);
                    log('booking_config searchDispatch', data.searchDispatch || 'missing', !!data.searchDispatch);
                    log('booking_config hotelId', data.hotelId || 'missing', !!data.hotelId);
                    // Show in debug panel
                    var apiPanel = document.getElementById('travel-debug-api');
                    if (apiPanel) apiPanel.innerHTML = '<pre style="color:#0f0;margin:0;">' + JSON.stringify(data, null, 2) + '</pre>';
                } catch(e) {
                    log('booking_config JSON parse', 'FAILED: ' + e.message, false);
                    log('booking_config raw (first 200)', text.substring(0, 200), false);
                    apiPanel = document.getElementById('travel-debug-api');
                    if (apiPanel) apiPanel.innerHTML = '<pre style="color:#e94560;margin:0;">JSON PARSE ERROR: ' + e.message + '\n\nRaw:\n' + text.substring(0, 500) + '</pre>';
                }
                console.table(results);
            }).catch(function(err) {
                log('booking_config fetch', 'NETWORK ERROR: ' + err.message, false);
                console.table(results);
            });
        }

        console.table(results);

        // Floating badge
        var badge = document.createElement('div');
        var ok = results.filter(function(r) { return r.ok; }).length;
        var fail = results.filter(function(r) { return !r.ok; }).length;
        badge.innerHTML = 'Travel Debug: ' + ok + ' OK, ' + fail + ' issues';
        badge.style.cssText = 'position:fixed;bottom:10px;right:10px;background:#1a1a2e;color:' + (fail > 0 ? '#e94560' : '#0f0') + ';padding:8px 16px;border-radius:20px;font-family:monospace;font-size:12px;z-index:99999;cursor:pointer;border:1px solid #e94560;';
        badge.onclick = function() {
            var panel = document.getElementById('travel-debug-panel');
            if (panel) panel.scrollIntoView({ behavior: 'smooth' });
        };
        document.body.appendChild(badge);
    });

    // Script load errors
    window.addEventListener('error', function(e) {
        if (e.target && e.target.tagName === 'SCRIPT' && e.target.src && e.target.src.indexOf('travel_core') !== -1) {
            console.error('[travel_core DEBUG] Script FAILED to load: ' + e.target.src);
        }
    }, true);
})();
