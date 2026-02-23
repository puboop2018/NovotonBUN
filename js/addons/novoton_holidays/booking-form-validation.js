/**
 * Novoton Holidays - Booking Form Validation & Price Recalculation
 * Version: 3.0.0-A86
 * 
 * Features:
 * - DOB input masking (DD/MM/YYYY)
 * - Age validation at check-in date
 * - Price recalculation via AJAX
 * - Room change warning modal
 */

(function() {
    'use strict';

    // =========================================================================
    // CONFIGURATION
    // =========================================================================
    
    var CONFIG = {
        debug: (window.NovotonConfig && window.NovotonConfig.debug) || (window.location.search.indexOf('novoton_debug') !== -1),
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
                console.log('[Novoton] ' + message, data);
            } else {
                console.log('[Novoton] ' + message);
            }
        }
    }

    function logError(message, error) {
        if (console && console.error) {
            console.error('[Novoton ERROR] ' + message, error);
        }
    }

    // =========================================================================
    // DOB MASKING (DD/MM/YYYY)
    // =========================================================================
    
    var dobLastKeyWasBackspace = false;

    window.handleDobKeydown = function(e) {
        dobLastKeyWasBackspace = (e.key === 'Backspace' || e.key === 'Delete');
    };

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

    window.applyDobMask = function(input) {
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
    };

    window.parseDobMasked = function(dobString) {
        if (!dobString || dobString.length !== 10) return null;
        var parts = dobString.split('/');
        if (parts.length !== 3) return null;
        
        var day = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var year = parseInt(parts[2], 10);
        
        if (isNaN(day) || isNaN(month) || isNaN(year)) return null;
        return { day: day, month: month, year: year };
    };

    window.calculateAgeAtDate = function(birthDate, targetDate) {
        var age = targetDate.getFullYear() - birthDate.getFullYear();
        var m = targetDate.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && targetDate.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    };

    // =========================================================================
    // PRICE DISPLAY UPDATES
    // =========================================================================
    
    window.updatePriceDisplay = function(newPrice, formattedPrice, difference) {
        log('updatePriceDisplay', { newPrice: newPrice, formattedPrice: formattedPrice, difference: difference });
        
        var priceElements = document.querySelectorAll(CONFIG.selectors.priceDisplay);
        log('Found price elements: ' + priceElements.length);
        
        if (priceElements.length === 0) {
            logError('No price elements found! Selector: ' + CONFIG.selectors.priceDisplay);
            return;
        }
        
        var displayValue = newPrice ? parseFloat(newPrice).toFixed(2) : (formattedPrice || '0.00');
        
        priceElements.forEach(function(el) {
            log('Updating: ' + el.className + ' from "' + el.textContent + '" to "' + displayValue + '"');
            el.textContent = displayValue;
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
                notif.style.cssText = 'background:#fff3cd;border-left:4px solid #ffc107;color:#856404;padding:8px 15px;margin:0 0 10px 0;border-radius:4px;font-size:14px;';
                var heading = document.querySelector('.guest-names-section h3');
                if (heading && heading.parentNode) {
                    heading.parentNode.insertBefore(notif, heading);
                }
            }
            var changeText = difference > 0 ? '+' + difference.toFixed(2) : difference.toFixed(2);
            var changeColor = difference > 0 ? '#dc3545' : '#28a745';
            var t = window.NovotonTranslations || {};
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
    };

    window.showPriceRecalculationNotice = function(message) {
        var notif = document.getElementById('price-recalc-notice');
        if (!notif) {
            notif = document.createElement('div');
            notif.id = 'price-recalc-notice';
            notif.style.cssText = 'background:#e7f3ff;border-left:4px solid #0071c2;color:#004085;padding:10px 15px;margin:10px 0;border-radius:4px;font-size:13px;';
            var priceSection = document.querySelector(CONFIG.selectors.priceSection);
            if (priceSection && priceSection.parentNode) {
                priceSection.parentNode.insertBefore(notif, priceSection.nextSibling);
            }
        }
        notif.textContent = 'ℹ️ ' + message;
        notif.style.display = 'block';
    };

    // =========================================================================
    // HTML ESCAPING UTILITY
    // =========================================================================

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // =========================================================================
    // ROOM CHANGE WARNING MODAL
    // =========================================================================

    window.showRoomChangeWarning = function(data) {
        log('showRoomChangeWarning', data);

        var existing = document.getElementById('room-change-warning');
        if (existing) existing.remove();

        var priceDiff = parseFloat(data.price_difference) || 0;
        var newPrice = parseFloat(data.new_price) || 0;
        var originalPrice = parseFloat(data.original_price) || 0;
        var originalRoom = escapeHtml(data.original_room || '');
        var newRoom = escapeHtml(data.new_room || '');
        
        var priceDiffText = '', priceDiffStyle = '';
        if (priceDiff > 0) {
            priceDiffText = '+' + priceDiff.toFixed(2) + ' €';
            priceDiffStyle = 'color:#dc3545;font-weight:bold;';
        } else if (priceDiff < 0) {
            priceDiffText = priceDiff.toFixed(2) + ' €';
            priceDiffStyle = 'color:#28a745;font-weight:bold;';
        }
        
        var t = window.NovotonTranslations || {};
        
        var html = '<div id="room-change-warning" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;">' +
            '<div style="background:#fff;border-radius:12px;padding:25px;max-width:450px;margin:20px;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
            '<div style="text-align:center;margin-bottom:20px;">' +
                '<div style="font-size:40px;margin-bottom:10px;"><i class="icon-warning-sign" style="color:#856404;"></i></div>' +
                '<h3 style="margin:0;color:#856404;font-size:18px;">' + t.roomChangedTitle + '</h3>' +
            '</div>' +
            '<p style="text-align:center;color:#666;margin-bottom:20px;font-size:14px;">' + t.roomChangedDueToAge + '</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:15px;margin-bottom:20px;">' +
                '<div style="display:flex;align-items:center;justify-content:center;gap:15px;flex-wrap:wrap;">' +
                    '<div style="text-align:center;">' +
                        '<div style="font-size:11px;color:#666;text-transform:uppercase;">' + t.originalRoom + '</div>' +
                        '<div style="font-weight:600;color:#856404;text-decoration:line-through;">' + originalRoom + '</div>' +
                    '</div>' +
                    '<div style="font-size:24px;color:#856404;">→</div>' +
                    '<div style="text-align:center;">' +
                        '<div style="font-size:11px;color:#666;text-transform:uppercase;">' + t.newRoom + '</div>' +
                        '<div style="font-weight:600;color:#155724;">' + newRoom + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div style="background:#f8f9fa;border-radius:8px;padding:15px;margin-bottom:20px;text-align:center;">' +
                '<div style="font-size:12px;color:#666;margin-bottom:5px;">' + t.priceChange + '</div>' +
                '<div style="font-size:20px;">' +
                    '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' €</span> ' +
                    '<span style="' + priceDiffStyle + '">(' + priceDiffText + ')</span> ' +
                    '<span style="font-weight:bold;color:#003580;">' + newPrice.toFixed(2) + ' €</span>' +
                '</div>' +
            '</div>' +
            '<div style="display:flex;gap:10px;justify-content:center;">' +
                '<button type="button" onclick="goBackToSearch()" style="padding:12px 20px;border:2px solid #003580;background:#fff;color:#003580;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;"><i class="icon-arrow-left"></i> ' + t.goBackToSearch + '</button>' +
                '<button type="button" onclick="acceptRoomChange()" style="padding:12px 20px;border:none;background:#003580;color:#fff;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">' + t.continueWithNewRoom + ' <i class="icon-arrow-right"></i></button>' +
            '</div>' +
            '</div></div>';
        
        window._roomChangeData = data;
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        document.body.appendChild(wrapper.firstChild);
        log('Modal displayed');
    };

    window.acceptRoomChange = function() {
        var data = window._roomChangeData || {};
        log('acceptRoomChange', data);
        
        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        
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
        var t = window.NovotonTranslations || {};
        var notif = document.createElement('div');
        notif.id = 'room-change-confirmation';
        notif.style.cssText = 'background:#d4edda;border-left:4px solid #28a745;color:#155724;padding:15px;margin:15px 0;border-radius:4px;font-size:14px;';
        notif.innerHTML = '<i class="icon-ok"></i> <strong>' + escapeHtml(t.roomUpdated) + '</strong> ' +
            escapeHtml(data.new_room || '') + ' - ' + (parseFloat(data.new_price) || 0).toFixed(2) + ' €';
        
        var section = document.querySelector('.guest-names-section h3, .booking-form-header');
        if (section && section.parentNode) {
            section.parentNode.insertBefore(notif, section.nextSibling);
        }
        
        setTimeout(function() {
            if (notif.parentNode) {
                notif.style.opacity = '0';
                notif.style.transition = 'opacity 0.3s';
                setTimeout(function() { notif.remove(); }, 300);
            }
        }, 10000);
    };

    window.goBackToSearch = function() {
        log('goBackToSearch');
        var warning = document.getElementById('room-change-warning');
        if (warning) warning.remove();
        
        var backBtn = document.querySelector('.btn-back, a[href*="novoton_booking.search"]');
        if (backBtn) backBtn.click();
        else window.history.back();
    };

    // =========================================================================
    // PRICE RECALCULATION (AJAX)
    // =========================================================================
    
    window.triggerPriceRecalculation = function(childrenAges, roomNum) {
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
        var baseUrl = (window.Tygh && window.Tygh.current_location) || window.location.origin;
        var ajaxUrl = baseUrl + '/index.php?dispatch=novoton_booking.ajax_recalculate_price';
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
                        var t2 = window.NovotonTranslations || {};
                        alert((t2.roomChangedTitle || 'Room changed') + ': ' + (data.original_room || '') + ' → ' + (data.new_room || '') +
                              '\n' + (t2.priceChange || 'Price') + ': ' + (data.new_price || 0).toFixed(2) + ' €');
                    }
                }

                updateFormWithNewPricing(data);
            } else {
                var t3 = window.NovotonTranslations || {};
                showPriceRecalculationNotice(data.message || t3.priceWillBeVerified || 'Price will be verified at checkout.');
            }
        })
        .catch(function(error) {
            logError('AJAX error', error);
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (priceDisplay) priceDisplay.style.opacity = '1';
            var t3 = window.NovotonTranslations || {};
            showPriceRecalculationNotice(t3.priceWillBeVerified || 'Price will be verified at checkout.');
        });
    };

    window.updateFormWithNewPricing = function(data) {
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
    };

    // =========================================================================
    // INITIALIZATION
    // =========================================================================
    
    log('Booking form validation loaded');

})();
