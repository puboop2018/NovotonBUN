/**
 * Travel Core - Booking Form Validation & Price Recalculation
 * Version: 3.1.0
 *
 * Features:
 * - DOB input masking (DD/MM/YYYY)
 * - Age validation at check-in date
 * - Price recalculation via AJAX
 * - Room change warning modal
 *
 * Provider-agnostic: reads config from TravelBookingConfig with
 * NovotonConfig fallback. Public API on window.TravelBooking with
 * window.Novoton alias for backwards compatibility.
 *
 * @package TravelCore
 * @since 1.0.0
 */

(function() {
    'use strict';

    // =========================================================================
    // NAMESPACE SETUP
    // =========================================================================

    window.TravelBooking = window.TravelBooking || {};
    window.Novoton = window.TravelBooking; // Backwards compatibility

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    function _getConfig() {
        return window.TravelBookingConfig || window.NovotonConfig || {};
    }

    function _getTranslations() {
        return window.TravelTranslations || window.NovotonTranslations || {};
    }

    function _getUtils() {
        return window.TravelUtils || window.NovotonUtils || {};
    }

    var CONFIG = {
        debug: !!(_getConfig().debug),
        selectors: {
            priceDisplay: '.price-total, .total-price-value, .booking-total-value, .price-amount',
            priceSection: '.booking-price-box, .price-summary, .booking-summary'
        }
    };

    // =========================================================================
    // LOGGING UTILITIES
    // =========================================================================

    function log(message, data) {
        if (CONFIG.debug && console && console.log) {
            if (data !== undefined) {
                console.log('[TravelBooking] ' + message, data);
            } else {
                console.log('[TravelBooking] ' + message);
            }
        }
    }

    function logError(message, error) {
        if (console && console.error) {
            console.error('[TravelBooking ERROR] ' + message, error);
        }
    }

    // =========================================================================
    // HTML ESCAPING UTILITY
    // =========================================================================

    function escapeHtml(str) {
        var utils = _getUtils();
        return (utils && utils.escapeHtml)
            ? utils.escapeHtml(str)
            : (str || '');
    }

    // =========================================================================
    // DOB MASKING (DD/MM/YYYY)
    // =========================================================================

    var dobLastKeyWasBackspace = false;

    function handleDobKeydown(e) {
        dobLastKeyWasBackspace = (e.key === 'Backspace' || e.key === 'Delete');
    }

    function formatDobDigits(digits) {
        var masked = '';
        if (digits.length > 0) {
            masked = digits.substring(0, Math.min(2, digits.length));
        }
        if (digits.length > 2) {
            masked = digits.substring(0, 2) + '/' + digits.substring(2, Math.min(4, digits.length));
        }
        if (digits.length > 4) {
            masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/' + digits.substring(4, Math.min(8, digits.length));
        }
        return masked;
    }

    function applyDobMask(input) {
        var cursorPos = input.selectionStart;
        var oldValue = input.value;
        var oldLen = oldValue.length;
        var digits = oldValue.replace(/\D/g, '');

        if (dobLastKeyWasBackspace) {
            dobLastKeyWasBackspace = false;
            var masked = formatDobDigits(digits);
            input.value = masked;
            var newPos = Math.min(cursorPos, masked.length);
            try { input.setSelectionRange(newPos, newPos); } catch(e) {}
            return;
        }

        var masked = '';
        if (digits.length > 0) masked = digits.substring(0, Math.min(2, digits.length));
        if (digits.length >= 2) masked = digits.substring(0, 2) + '/';
        if (digits.length > 2) masked = digits.substring(0, 2) + '/' + digits.substring(2, Math.min(4, digits.length));
        if (digits.length >= 4) masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/';
        if (digits.length > 4) masked = digits.substring(0, 2) + '/' + digits.substring(2, 4) + '/' + digits.substring(4, Math.min(8, digits.length));

        input.value = masked;

        var newLen = masked.length;
        var newPos = cursorPos;
        if (newLen > oldLen) {
            if (cursorPos === 2 || cursorPos === 3) newPos = 3;
            else if (cursorPos === 5 || cursorPos === 6) newPos = 6;
            else newPos = newLen;
        }
        try { input.setSelectionRange(newPos, newPos); } catch(e) {}
    }

    function parseDobMasked(dobString) {
        if (!dobString || dobString.length !== 10) return null;
        var parts = dobString.split('/');
        if (parts.length !== 3) return null;

        var day = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var year = parseInt(parts[2], 10);

        if (isNaN(day) || isNaN(month) || isNaN(year)) return null;
        return { day: day, month: month, year: year };
    }

    function calculateAgeAtDate(birthDate, targetDate) {
        var utils = _getUtils();
        if (utils && utils.calculateAge) {
            return utils.calculateAge(birthDate, targetDate);
        }
        var age = targetDate.getFullYear() - birthDate.getFullYear();
        var m = targetDate.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && targetDate.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }

    // =========================================================================
    // PRICE DISPLAY UPDATES
    // =========================================================================

    function updatePriceDisplay(newPrice, formattedPrice, difference) {
        log('updatePriceDisplay', { newPrice: newPrice, formattedPrice: formattedPrice, difference: difference });

        var priceElements = document.querySelectorAll(CONFIG.selectors.priceDisplay);
        log('Found price elements: ' + priceElements.length);

        if (priceElements.length === 0) {
            logError('No price elements found! Selector: ' + CONFIG.selectors.priceDisplay);
            return;
        }

        // Use server-formatted price (includes currency symbol and rounding)
        // Fallback: format with currency from NovotonTranslations
        var displayValue = formattedPrice;
        if (!displayValue) {
            var currency = _getTranslations().currency || '€';
            displayValue = (newPrice ? parseFloat(newPrice).toFixed(2) : '0.00') + ' ' + currency;
        }

        priceElements.forEach(function(el) {
            log('Updating: ' + el.className + ' from "' + el.textContent + '" to "' + displayValue + '"');
            // Use innerHTML since formatted_price may contain <sup> for decimals
            el.innerHTML = displayValue;
        });

        // Hide recalc notice
        var recalcNotice = document.getElementById('price-recalc-notice');
        if (recalcNotice) recalcNotice.style.display = 'none';

        // Show price change notification above guest details heading
        if (difference && difference !== 0) {
            var notif = document.getElementById('price-change-notification');
            if (!notif) {
                notif = document.createElement('div');
                notif.id = 'price-change-notification';
                notif.className = 'novoton-price-change-notif';
                var heading = document.querySelector('.guest-names-section h3');
                if (heading && heading.parentNode) {
                    heading.parentNode.insertBefore(notif, heading);
                }
            }
            var changeText = difference > 0 ? '+' + difference.toFixed(2) : difference.toFixed(2);
            var changeColor = difference > 0 ? 'var(--nvt-danger, #dc3545)' : 'var(--nvt-success, #28a745)';
            var t = _getTranslations();
            notif.textContent = '';
            notif.appendChild(document.createTextNode(
                t.priceUpdated + ': '
            ));
            var strong = document.createElement('strong');
            strong.style.color = changeColor;
            strong.textContent = changeText + ' €';
            notif.appendChild(strong);
            notif.style.display = 'block';
        }
    }

    function showPriceRecalculationNotice(message) {
        var notif = document.getElementById('price-recalc-notice');
        if (!notif) {
            notif = document.createElement('div');
            notif.id = 'price-recalc-notice';
            notif.className = 'novoton-recalc-notice';
            var priceSection = document.querySelector(CONFIG.selectors.priceSection);
            if (priceSection && priceSection.parentNode) {
                priceSection.parentNode.insertBefore(notif, priceSection.nextSibling);
            }
        }
        // Use DOM methods to avoid innerHTML with message text
        notif.textContent = '';
        var icon = document.createElement('i');
        icon.className = 'icon-info-sign';
        notif.appendChild(icon);
        notif.appendChild(document.createTextNode(' ' + message));
        notif.style.display = 'block';
    }

    // =========================================================================
    // ROOM CHANGE WARNING MODAL
    // =========================================================================

    // Internal state for room change flow
    var _roomChangeState = {
        data: null,
        isActive: false,

        set: function(newData) {
            this.data = newData;
            this.isActive = true;
        },

        clear: function() {
            this.data = null;
            this.isActive = false;
        }
    };

    function showRoomChangeWarning(data) {
        log('showRoomChangeWarning', data);

        var existing = document.getElementById('room-change-warning');
        if (existing) existing.remove();

        var priceDiff = parseFloat(data.price_difference) || 0;
        var newPrice = parseFloat(data.new_price) || 0;
        var originalPrice = parseFloat(data.original_price) || 0;
        var originalRoom = escapeHtml(data.original_room || '');
        var newRoom = escapeHtml(data.new_room || '');

        var priceDiffText = '', priceDiffClass = '';
        if (priceDiff > 0) {
            priceDiffText = '+' + priceDiff.toFixed(2) + ' €';
            priceDiffClass = 'color: var(--nvt-danger, #dc3545); font-weight: bold;';
        } else if (priceDiff < 0) {
            priceDiffText = priceDiff.toFixed(2) + ' €';
            priceDiffClass = 'color: var(--nvt-success, #28a745); font-weight: bold;';
        }

        var t = _getTranslations();

        // Build modal HTML — all translations escaped
        var html = '<div id="room-change-warning" class="novoton-modal-overlay">' +
            '<div class="novoton-modal-content">' +
            '<div class="novoton-modal-header">' +
                '<div class="novoton-modal-icon"><i class="icon-warning-sign"></i></div>' +
                '<h3 class="novoton-modal-title">' + escapeHtml(t.roomChangedTitle) + '</h3>' +
            '</div>' +
            '<p class="novoton-modal-body">' + escapeHtml(t.roomChangedDueToAge) + '</p>' +
            '<div class="novoton-room-change-info">' +
                '<div class="novoton-room-change-compare">' +
                    '<div style="text-align:center;">' +
                        '<div class="novoton-room-change-label">' + escapeHtml(t.originalRoom) + '</div>' +
                        '<div class="novoton-room-change-old">' + originalRoom + '</div>' +
                    '</div>' +
                    '<div class="novoton-room-change-arrow"><i class="icon-arrow-right"></i></div>' +
                    '<div style="text-align:center;">' +
                        '<div class="novoton-room-change-label">' + escapeHtml(t.newRoom) + '</div>' +
                        '<div class="novoton-room-change-new">' + newRoom + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="novoton-price-change-box">' +
                '<div class="novoton-price-change-label">' + escapeHtml(t.priceChange) + '</div>' +
                '<div class="novoton-price-change-values">' +
                    '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' €</span> ' +
                    '<span style="' + priceDiffClass + '">(' + priceDiffText + ')</span> ' +
                    '<span style="font-weight:bold;color:var(--nvt-primary, #003580);">' + newPrice.toFixed(2) + ' €</span>' +
                '</div>' +
            '</div>' +
            '<div class="novoton-modal-actions">' +
                '<button type="button" onclick="TravelBooking.goBackToSearch()" class="novoton-modal-btn-secondary"><i class="icon-arrow-left"></i> ' + escapeHtml(t.goBackToSearch) + '</button>' +
                '<button type="button" onclick="TravelBooking.acceptRoomChange()" class="novoton-modal-btn-primary">' + escapeHtml(t.continueWithNewRoom) + ' <i class="icon-arrow-right"></i></button>' +
            '</div>' +
            '</div></div>';

        _roomChangeState.set(data);

        // Error boundary: if modal fails to render, fall back to alert
        try {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            var modal = wrapper.firstChild;
            if (!modal) throw new Error('Modal rendering failed');
            document.body.appendChild(modal);
            log('Modal displayed');
        } catch (e) {
            logError('Modal render error', e);
            _roomChangeState.clear();
            alert((t.roomChangedTitle || 'Room changed') + ': ' + (data.original_room || '') + ' → ' + (data.new_room || '') +
                  '\n' + (t.priceChange || 'Price') + ': ' + (data.new_price || 0).toFixed(2) + ' €');
        }
    }

    function acceptRoomChange() {
        var data = _roomChangeState.data || {};
        log('acceptRoomChange', data);

        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        _roomChangeState.clear();

        document.querySelectorAll('.room-name, .selected-room-name, [data-room-name]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });

        var roomIdInput = document.querySelector('input[name="room_id"]');
        if (roomIdInput) roomIdInput.value = data.new_room || '';

        if (window.bookingData) {
            window.bookingData.roomId = data.new_room || '';
            window.bookingData.currentPrice = parseFloat(data.new_price) || 0;
        }

        // Show confirmation
        var t = _getTranslations();
        var notif = document.createElement('div');
        notif.id = 'room-change-confirmation';
        notif.className = 'novoton-room-confirmed';
        // Build safely with DOM methods
        var icon = document.createElement('i');
        icon.className = 'icon-ok';
        notif.appendChild(icon);
        notif.appendChild(document.createTextNode(' '));
        var strong = document.createElement('strong');
        strong.textContent = t.roomUpdated || 'Room updated';
        notif.appendChild(strong);
        notif.appendChild(document.createTextNode(' ' + (data.new_room || '') + ' - ' + (parseFloat(data.new_price) || 0).toFixed(2) + ' €'));

        var section = document.querySelector('.guest-names-section h3, .booking-form-header');
        if (section && section.parentNode) {
            section.parentNode.insertBefore(notif, section.nextSibling);
        }

        setTimeout(function() {
            if (notif.parentNode) {
                notif.style.opacity = '0';
                setTimeout(function() { notif.remove(); }, 300);
            }
        }, 10000);
    }

    function goBackToSearch() {
        log('goBackToSearch');
        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        _roomChangeState.clear();

        var cfg = _getConfig();
        var searchDispatch = (cfg && cfg.searchDispatch) || 'novoton_booking.search';
        var backBtn = document.querySelector('.btn-back, a[href*="' + searchDispatch + '"]');
        if (backBtn) backBtn.click();
        else window.history.back();
    }

    // =========================================================================
    // PRICE RECALCULATION (AJAX)
    // =========================================================================

    function triggerPriceRecalculation(childrenAges, roomNum) {
        roomNum = roomNum || 1;
        log('triggerPriceRecalculation room ' + roomNum, childrenAges);

        if (!window.bookingData) {
            logError('bookingData not defined');
            return;
        }

        var isMultiRoom = window.bookingData.numRooms > 1 && window.bookingData.roomsData && window.bookingData.roomsData.length > 0;
        var roomIdx = roomNum - 1;

        // Get room-specific data for multi-room, or use single room data
        var roomData = {};
        if (isMultiRoom && window.bookingData.roomsData[roomIdx]) {
            roomData = window.bookingData.roomsData[roomIdx];
        } else {
            roomData = {
                room_id: window.bookingData.roomId,
                board_id: window.bookingData.boardId,
                adults: window.bookingData.adults,
                price: window.bookingData.currentPrice
            };
        }

        var priceDisplay = document.querySelector(CONFIG.selectors.priceDisplay);
        var loadingIndicator = document.getElementById('price-loading-indicator');

        if (loadingIndicator) loadingIndicator.style.display = 'inline-block';
        if (priceDisplay) priceDisplay.style.opacity = '0.5';

        var requestData = {
            hotel_id: window.bookingData.hotelId,
            room_id: roomData.room_id || window.bookingData.roomId,
            board_id: roomData.board_id || window.bookingData.boardId,
            check_in: window.bookingData.checkIn,
            nights: window.bookingData.nights,
            adults: roomData.adults || window.bookingData.adults,
            children_ages: childrenAges,
            package_name: roomData.package_name || window.bookingData.packageName,
            original_price: roomData.price || window.bookingData.currentPrice,
            room_num: roomNum,
            is_multi_room: isMultiRoom
        };

        log('AJAX request', requestData);

        // Clean AJAX URL — only dispatch param. All booking data goes in JSON body.
        // Do NOT inherit parent page URL params (children_ages[], etc.) as they
        // cause PHP warnings in CS-Cart's init that corrupt the JSON response.
        // Use pre-built URL from template (includes storefront_id) with fallback
        var cfg = _getConfig();
        var ajaxUrl = cfg.ajaxRecalcUrl
            || ((window.Tygh && window.Tygh.current_location) || window.location.origin) + '/index.php?dispatch=' + (cfg.ajaxRecalcDispatch || 'novoton_booking.ajax_recalculate_price');
        log('AJAX URL', ajaxUrl);

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            log('Response status: ' + response.status);
            return response.text();
        })
        .then(function(text) {
            log('Raw response', text.substring(0, 200));
            try {
                return JSON.parse(text);
            } catch (e) {
                logError('JSON parse error: ' + e.message);
                throw e;
            }
        })
        .then(function(data) {
            log('AJAX response', data);

            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (priceDisplay) priceDisplay.style.opacity = '1';

            if (data.success) {
                updatePriceDisplay(data.new_price, data.formatted_price, data.price_difference);

                if (data.room_changed) {
                    try {
                        showRoomChangeWarning(data);
                    } catch (e) {
                        logError('Modal error', e);
                        var t2 = _getTranslations();
                        alert((t2.roomChangedTitle || 'Room changed') + ': ' + (data.original_room || '') + ' → ' + (data.new_room || '') +
                              '\n' + (t2.priceChange || 'Price') + ': ' + (data.new_price || 0).toFixed(2) + ' €');
                    }
                }

                updateFormWithNewPricing(data);
            } else {
                var t3 = _getTranslations();
                showPriceRecalculationNotice(data.message || t3.priceWillBeVerified || 'Price will be verified at checkout.');
            }
        })
        .catch(function(error) {
            logError('AJAX error', error);
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (priceDisplay) priceDisplay.style.opacity = '1';
            var t3 = _getTranslations();
            showPriceRecalculationNotice(t3.priceWillBeVerified || 'Price will be verified at checkout.');
        });
    }

    function updateFormWithNewPricing(data) {
        var priceInput = document.querySelector('input[name="total_price"]');
        if (priceInput && data.new_price !== undefined) priceInput.value = data.new_price;

        var adultsInput = document.querySelector('input[name="adults"]');
        if (adultsInput && data.new_adults) adultsInput.value = data.new_adults;

        var childrenInput = document.querySelector('input[name="children"]');
        if (childrenInput && data.new_children !== undefined) childrenInput.value = data.new_children;

        if (window.bookingData) {
            if (data.new_price !== undefined) window.bookingData.currentPrice = data.new_price;
            if (data.new_adults) window.bookingData.adults = data.new_adults;
        }
    }

    // =========================================================================
    // PUBLIC API — namespaced under window.TravelBooking (with Novoton alias)
    // =========================================================================

    window.TravelBooking.handleDobKeydown = handleDobKeydown;
    window.TravelBooking.applyDobMask = applyDobMask;
    window.TravelBooking.parseDobMasked = parseDobMasked;
    window.TravelBooking.calculateAgeAtDate = calculateAgeAtDate;
    window.TravelBooking.updatePriceDisplay = updatePriceDisplay;
    window.TravelBooking.showRoomChangeWarning = showRoomChangeWarning;
    window.TravelBooking.acceptRoomChange = acceptRoomChange;
    window.TravelBooking.goBackToSearch = goBackToSearch;
    window.TravelBooking.triggerPriceRecalculation = triggerPriceRecalculation;
    window.TravelBooking.updateFormWithNewPricing = updateFormWithNewPricing;
    window.TravelBooking.showPriceRecalculationNotice = showPriceRecalculationNotice;

    // Backwards compatibility: expose on window for existing onblur/onclick handlers in TPL
    window.handleDobKeydown = handleDobKeydown;
    window.applyDobMask = applyDobMask;
    window.parseDobMasked = parseDobMasked;
    window.calculateAgeAtDate = calculateAgeAtDate;
    window.updatePriceDisplay = updatePriceDisplay;
    window.showRoomChangeWarning = showRoomChangeWarning;
    window.acceptRoomChange = acceptRoomChange;
    window.goBackToSearch = goBackToSearch;
    window.triggerPriceRecalculation = triggerPriceRecalculation;
    window.updateFormWithNewPricing = updateFormWithNewPricing;
    window.showPriceRecalculationNotice = showPriceRecalculationNotice;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    log('Travel booking form validation loaded');

})();
