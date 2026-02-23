/**
 * Novoton Holidays - Shared JavaScript Utilities
 * 
 * Common functions used across multiple JS files to avoid duplication.
 * 
 * @package NovotonHolidays
 * @since 3.0.0
 */

window.NovotonUtils = (function() {
    'use strict';
    
    // Cache for DOM elements
    const elementCache = new Map();
    
    // Debounce function
    function debounce(func, wait, immediate) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    }
    
    // Throttle function
    function throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
    
    // Format price
    function formatPrice(price, currency = 'EUR', locale = 'ro-RO') {
        const symbols = { EUR: '€', USD: '$', GBP: '£', RON: 'lei', BGN: 'лв' };
        const formatted = parseFloat(price).toFixed(2).replace('.', ',');
        return formatted + ' ' + (symbols[currency] || currency);
    }
    
    // Format date
    function formatDate(date, locale = 'ro-RO') {
        if (!date) return '';
        const d = typeof date === 'string' ? new Date(date) : date;
        return d.toLocaleDateString(locale, { day: '2-digit', month: 'short', year: 'numeric' });
    }
    
    // Parse date from various formats
    function parseDate(dateStr) {
        if (!dateStr) return null;
        // Handle YYYY-MM-DD
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
            return new Date(dateStr + 'T00:00:00');
        }
        // Handle DD/MM/YYYY or DD.MM.YYYY
        const parts = dateStr.split(/[\/\.]/);
        if (parts.length === 3) {
            return new Date(parts[2], parts[1] - 1, parts[0]);
        }
        return new Date(dateStr);
    }
    
    // Calculate nights between dates
    function calculateNights(checkIn, checkOut) {
        const d1 = parseDate(checkIn);
        const d2 = parseDate(checkOut);
        if (!d1 || !d2) return 0;
        return Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
    }
    
    // Get element with caching
    function getElement(selector, context = document) {
        const key = selector + (context === document ? '' : context.id || '');
        if (!elementCache.has(key)) {
            elementCache.set(key, context.querySelector(selector));
        }
        return elementCache.get(key);
    }
    
    // Clear element cache
    function clearCache() {
        elementCache.clear();
    }
    
    // Safe JSON parse
    function parseJSON(str, defaultValue = null) {
        try {
            return JSON.parse(str);
        } catch (e) {
            return defaultValue;
        }
    }
    
    // Fetch with timeout
    async function fetchWithTimeout(url, options = {}, timeout = 30000) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal
            });
            clearTimeout(id);

            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }

            return response;
        } catch (error) {
            clearTimeout(id);
            if (error.name === 'AbortError') {
                throw new Error('Request timeout after ' + timeout + 'ms');
            }
            throw error;
        }
    }
    
    // Local storage helpers with expiry
    const storage = {
        set: function(key, value, ttlMinutes = 60) {
            const item = {
                value: value,
                expiry: Date.now() + (ttlMinutes * 60 * 1000)
            };
            try {
                localStorage.setItem('nvt_' + key, JSON.stringify(item));
            } catch (e) {
                console.warn('NovotonUtils: localStorage not available');
            }
        },
        
        get: function(key) {
            try {
                const itemStr = localStorage.getItem('nvt_' + key);
                if (!itemStr) return null;
                
                const item = JSON.parse(itemStr);
                if (Date.now() > item.expiry) {
                    localStorage.removeItem('nvt_' + key);
                    return null;
                }
                return item.value;
            } catch (e) {
                return null;
            }
        },
        
        remove: function(key) {
            try {
                localStorage.removeItem('nvt_' + key);
            } catch (e) {}
        }
    };
    
    // Event delegation helper
    function delegate(parent, eventType, selector, handler) {
        parent.addEventListener(eventType, function(e) {
            const target = e.target.closest(selector);
            if (target && parent.contains(target)) {
                handler.call(target, e);
            }
        });
    }
    
    // Pluralize (Romanian)
    function pluralize(count, singular, plural, pluralFew = null) {
        count = parseInt(count, 10);
        if (count === 1) return singular;
        if (pluralFew && count >= 2 && count <= 19) return pluralFew;
        return plural;
    }
    
    // Pluralization for common words (uses NovotonTranslations when available)
    const roPlural = {
        noapte: (n) => {
            var t = window.NovotonTranslations || {};
            if (n === 1) return t.night || 'night';
            if (n >= 2 && n <= 19) return t.nights || 'nights';
            return t.nightsMany || t.nights || 'nights';
        },
        adult: (n) => {
            var t = window.NovotonTranslations || {};
            return n === 1 ? (t.adult || 'adult') : (t.adults || 'adults');
        },
        copil: (n) => {
            var t = window.NovotonTranslations || {};
            return n === 1 ? (t.child || 'child') : (t.children || 'children');
        },
        camera: (n) => {
            var t = window.NovotonTranslations || {};
            return n === 1 ? (t.room || 'room') : (t.rooms || 'rooms');
        },
        an: (n) => {
            var t = window.NovotonTranslations || {};
            return n === 1 ? (t.yearOld || 'year') : (t.yearsOld || 'years');
        }
    };
    
    // Escape HTML to prevent XSS
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Calculate age at a target date
    function calculateAge(birthDate, targetDate) {
        var age = targetDate.getFullYear() - birthDate.getFullYear();
        var m = targetDate.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && targetDate.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }

    // Scroll to element smoothly
    function scrollTo(element, offset = 100) {
        const y = element.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({ top: y, behavior: 'smooth' });
    }
    
    // Show/hide loading indicator
    function setLoading(element, loading, text) {
        if (!text) {
            var t = window.NovotonTranslations || {};
            text = t.loading || 'Loading...';
        }
        if (!element) return;

        if (loading) {
            element.dataset.originalText = element.textContent;
            element.innerHTML = '<i class="icon-refresh"></i> ' + text;
            element.disabled = true;
        } else {
            element.textContent = element.dataset.originalText || element.textContent;
            element.disabled = false;
        }
    }
    
    // Public API
    return {
        debounce,
        throttle,
        formatPrice,
        formatDate,
        parseDate,
        calculateNights,
        calculateAge,
        escapeHtml,
        getElement,
        clearCache,
        parseJSON,
        fetchWithTimeout,
        storage,
        delegate,
        pluralize,
        roPlural,
        scrollTo,
        setLoading
    };
})();
