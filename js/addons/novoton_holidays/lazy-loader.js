/**
 * Novoton Holidays - Lazy Loader
 * 
 * Loads React booking engine components on demand to improve page load.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

window.NovotonLazy = (function() {
    'use strict';
    
    const loadedScripts = new Set();
    const loadingPromises = new Map();
    
    /**
     * Load a script dynamically
     */
    function loadScript(src) {
        if (loadedScripts.has(src)) {
            return Promise.resolve();
        }
        
        if (loadingPromises.has(src)) {
            return loadingPromises.get(src);
        }
        
        const promise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            
            script.onload = () => {
                loadedScripts.add(src);
                loadingPromises.delete(src);
                resolve();
            };
            
            script.onerror = () => {
                loadingPromises.delete(src);
                reject(new Error('Failed to load: ' + src));
            };
            
            document.head.appendChild(script);
        });
        
        loadingPromises.set(src, promise);
        return promise;
    }
    
    /**
     * Load CSS dynamically
     */
    function loadCSS(href) {
        if (document.querySelector(`link[href="${href}"]`)) {
            return Promise.resolve();
        }
        
        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            
            link.onload = resolve;
            link.onerror = reject;
            
            document.head.appendChild(link);
        });
    }
    
    /**
     * Initialize booking engine when element becomes visible
     */
    function initOnVisible(selector, initCallback) {
        const elements = document.querySelectorAll(selector);
        
        if (!elements.length) return;
        
        // Use Intersection Observer for lazy init
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        observer.unobserve(entry.target);
                        initCallback(entry.target);
                    }
                });
            }, {
                rootMargin: '100px' // Start loading slightly before visible
            });
            
            elements.forEach(el => observer.observe(el));
        } else {
            // Fallback for older browsers
            elements.forEach(el => initCallback(el));
        }
    }
    
    /**
     * Load and initialize React booking engine
     */
    async function loadBookingEngine(container, config = {}) {
        const basePath = config.basePath || '/js/addons/novoton_holidays/';
        
        // Show loading indicator
        container.innerHTML = `
            <div class="nvt-loading" style="text-align:center;padding:40px;">
                <div class="nvt-spinner" style="
                    width:40px;height:40px;margin:0 auto 16px;
                    border:3px solid #e7e7e7;border-top-color:#0071c2;
                    border-radius:50%;animation:nvt-spin 1s linear infinite;
                "></div>
                <div style="color:#6b6b6b;">Se încarcă...</div>
            </div>
            <style>
                @keyframes nvt-spin { to { transform: rotate(360deg); } }
            </style>
        `;
        
        try {
            // Load utilities first
            await loadScript(basePath + 'utils.js');
            
            // Load React bundle
            await loadScript(basePath + 'react19-bundle.js');
            
            // Wait for React to be available
            await waitForReact();
            
            // Initialize the booking engine
            if (window.initNovotonBooking) {
                window.initNovotonBooking(container, config);
            } else {
                console.error('NovotonLazy: initNovotonBooking not found');
            }
            
        } catch (error) {
            console.error('NovotonLazy: Failed to load booking engine', error);
            container.innerHTML = `
                <div class="nvt-error" style="text-align:center;padding:40px;color:#c53030;">
                    <p>Eroare la încărcarea formularului de rezervare.</p>
                    <button onclick="location.reload()" style="
                        margin-top:16px;padding:8px 24px;
                        background:#0071c2;color:white;border:none;
                        border-radius:4px;cursor:pointer;
                    ">Reîncarcă pagina</button>
                </div>
            `;
        }
    }
    
    /**
     * Wait for React to be available
     */
    function waitForReact(timeout = 5000) {
        return new Promise((resolve, reject) => {
            const start = Date.now();
            
            function check() {
                if (window.React && window.ReactDOM) {
                    resolve();
                } else if (Date.now() - start > timeout) {
                    reject(new Error('React not loaded'));
                } else {
                    setTimeout(check, 50);
                }
            }
            
            check();
        });
    }
    
    /**
     * Preload resources (call early for faster subsequent load)
     */
    function preload(basePath = '/js/addons/novoton_holidays/') {
        // Preload hints for browser
        const resources = [
            { href: basePath + 'react19-bundle.js', as: 'script' },
            { href: basePath + 'utils.js', as: 'script' }
        ];
        
        resources.forEach(res => {
            const link = document.createElement('link');
            link.rel = 'preload';
            link.href = res.href;
            link.as = res.as;
            document.head.appendChild(link);
        });
    }
    
    // Public API
    return {
        loadScript,
        loadCSS,
        initOnVisible,
        loadBookingEngine,
        preload
    };
})();

// Auto-initialize lazy loading for booking containers
document.addEventListener('DOMContentLoaded', function() {
    // Find all booking containers that should be lazy loaded
    NovotonLazy.initOnVisible('[data-nvt-lazy="booking"]', function(container) {
        const config = {
            basePath: container.dataset.basePath || '/js/addons/novoton_holidays/',
            productId: container.dataset.productId,
            hotelId: container.dataset.hotelId,
            mode: container.dataset.mode || 'product'
        };
        
        NovotonLazy.loadBookingEngine(container, config);
    });
});
