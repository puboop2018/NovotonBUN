/**
 * Novoton guest booking form — DOB validation, AJAX price recalculation and
 * the room-change decision flow (accept/decline modal).
 *
 * Extracted from booking_form.tpl's inline <script> so vitest can import the
 * REAL file (tests/js/novoton-booking-form.test.mjs). The template emits the
 * Smarty-fed config before loading this module:
 *   window.bookingData        — searched stay/party + ajaxRecalculateUrl
 *   window.NovotonBookingI18n — translated UI labels (read via nvtLabel())
 * plus the shared TravelBooking.* DOB API (booking-form-validation.js).
 */

// Label lookup with the raw-lang-key guard: CS-Cart returns the literal
// "_key.name" (truthy!) for a missing language var, so treat empty or
// "_"-prefixed values as missing and use the baked-in fallback.
function nvtLabel(key, fallback) {
    var labels = window.NovotonBookingI18n || {};
    var v = labels[key];
    v = (v == null) ? '' : String(v);
    return (v === '' || v.charAt(0) === '_') ? fallback : v;
}


// HTML escape utility to prevent XSS
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Debug logging - enabled via NovotonConfig.debug or ?novoton_debug=1 in URL
var novotonDebug = (window.NovotonConfig && window.NovotonConfig.debug) || (window.location.search.indexOf('novoton_debug') !== -1);
function novotonLog(message, data) {
    if (novotonDebug && console && console.log) {
        if (data !== undefined) {
            console.log('[Novoton] ' + message, data);
        } else {
            console.log('[Novoton] ' + message);
        }
    }
}

// Form submit validation. Inline, this ran right after the form markup; as
// an external module the binding waits for DOM-ready.
function novotonBindSubmitValidation() {
    var bookingForm = document.getElementById('novoton-booking-form');
    if (!bookingForm) return;
    bookingForm.addEventListener('submit', function(e) {
    var allFilled = true;
    this.querySelectorAll('input[required], select[required]').forEach(function(el) {
        if (!el.value.trim()) {
            allFilled = false;
            el.style.borderColor = '#dc3545';
        } else {
            el.style.borderColor = '#ccc';
        }
    });

    if (!allFilled) {
        e.preventDefault();
        alert(nvtLabel('fillAllFields', 'Vă rugăm completați toate câmpurile obligatorii'));
        return;
    }

    // Check for DOB validation errors
    var dobErrors = document.querySelectorAll('.dob-validation-error');
    var hasError = false;
    dobErrors.forEach(function(el) {
        if (el.style.display !== 'none' && el.textContent !== '') {
            hasError = true;
        }
    });

    if (hasError) {
        e.preventDefault();
        alert(nvtLabel('dobValidationError', 'Verificati datele de nastere introduse.'));
    }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', novotonBindSubmitValidation);
} else {
    novotonBindSubmitValidation();
}

// window.bookingData is emitted by the template (the Smarty-fed config).


// DOB masking is wired directly to the shared TravelBooking.* API
// (booking-form-validation.js), matching the sphinx booking form.

// Main validation function for DOB fields
function validateAndCheckAge(id, originalAge) {
    var dobInput = document.getElementById('child_dob_' + id);
    var errorDiv = document.getElementById('dob_error_' + id);
    var infoDiv = document.getElementById('dob_info_' + id);
    var calcAgeInput = document.getElementById('child_age_' + id);
    var ageDisplay = document.getElementById('child_age_display_' + id);

    novotonLog('validateAndCheckAge called', { id: id, originalAge: originalAge });

    if (!dobInput) { novotonLog('DOB input not found: child_dob_' + id); return; }

    var dobValue = dobInput.value;
    novotonLog('DOB value', dobValue);

    // Clear previous states
    dobInput.style.borderColor = '';
    dobInput.style.backgroundColor = '';
    if (errorDiv) { errorDiv.style.display = 'none'; errorDiv.textContent = ''; }
    if (infoDiv) { infoDiv.style.display = 'none'; infoDiv.textContent = ''; }
    // Clear previous price error when user re-enters DOB
    hidePriceError();

    // Skip if empty or incomplete
    if (!dobValue || dobValue.length < 10) { novotonLog('DOB incomplete, skipping'); return; }

    // Parse DOB - requires booking-form-validation.js
    if (typeof parseDobMasked !== 'function') {
        novotonLog('parseDobMasked not loaded yet');
        return;
    }
    var parsed = parseDobMasked(dobValue);
    if (!parsed) {
        novotonLog('DOB parse failed');
        showDobError(dobInput, errorDiv, 'Format invalid');
        return;
    }
    novotonLog('DOB parsed', parsed);

    // Validate ranges
    if (parsed.day < 1 || parsed.day > 31) {
        showDobError(dobInput, errorDiv, 'Ziua invalida (1-31)');
        return;
    }
    if (parsed.month < 1 || parsed.month > 12) {
        showDobError(dobInput, errorDiv, 'Luna invalida (1-12)');
        return;
    }
    var currentYear = new Date().getFullYear();
    if (parsed.year < 1925 || parsed.year > currentYear) {
        showDobError(dobInput, errorDiv, 'Anul invalid');
        return;
    }

    // Check if DOB is in the future
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var birthDate = new Date(parsed.year, parsed.month - 1, parsed.day);
    if (birthDate > today) {
        showDobError(dobInput, errorDiv, 'Data nasterii nu poate fi in viitor');
        return;
    }

    // Calculate age at check-in - requires booking-form-validation.js
    if (typeof calculateAgeAtDate !== 'function') {
        novotonLog('calculateAgeAtDate not loaded yet');
        return;
    }
    var checkInDate = new Date(window.bookingData.checkIn);
    var calculatedAge = calculateAgeAtDate(birthDate, checkInDate);

    novotonLog('Age calculation', {
        dob: dobValue,
        checkIn: window.bookingData.checkIn,
        calculatedAge: calculatedAge
    });

    // Update hidden field
    if (calcAgeInput) calcAgeInput.value = calculatedAge;

    // Update age display - use translation with singular/plural (Romanian: "1 an", "2 ani")
    if (ageDisplay) {
        var ageLabel;
        if (calculatedAge === 1) {
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabelSingular ? window.NovotonTranslations.ageLabelSingular : 'an';
        } else {
            ageLabel = window.NovotonTranslations && window.NovotonTranslations.ageLabel ? window.NovotonTranslations.ageLabel : 'ani';
        }
        ageDisplay.textContent = '(' + calculatedAge + ' ' + ageLabel + ')';
    }

    if (calculatedAge >= 18) {
        var t = window.NovotonTranslations || {};
        var notChildMsg = t.notChild || 'La check-in, copilul va avea';
        var yearsLabel = t.ageLabel || 'ani';
        var mustBeUnder18 = t.mustBeUnder18 || 'Trebuie sa fie sub 18 ani.';
        showDobError(dobInput, errorDiv, notChildMsg + ' ' + calculatedAge + ' ' + yearsLabel + '. ' + mustBeUnder18);
        showPriceError(t.childAgeNotAllowed || 'Vârsta copilului depășește limita');
        return;
    }

    // Valid child age — show green, let API determine price
    dobInput.style.borderColor = '#28a745';
    dobInput.style.backgroundColor = '#f0fff0';

    // Extract room number from id (format: rX_cY where X=room, Y=child)
    var roomMatch = id.match(/r(\d+)_c\d+/);
    var roomNum = roomMatch ? parseInt(roomMatch[1], 10) : 1;

    // Trigger price recalculation for this specific room
    novotonLog('Triggering price recalculation for room ' + roomNum);
    collectAndRecalculate(roomNum);
}

function showDobError(input, errorDiv, message) {
    input.style.borderColor = '#dc3545';
    input.style.backgroundColor = '#fff5f5';
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }
}

// Per-room debounce timers for price recalculation
var priceRecalcDebouncers = {};

function collectAndRecalculate(roomNum) {
    roomNum = roomNum || 1;

    // Debounce per room: wait 600ms after last DOB change before recalculating
    // This prevents multiple API calls when user enters DOBs for multiple children
    // Using per-room timers so room 1 and room 2 don't overwrite each other
    if (priceRecalcDebouncers[roomNum]) {
        clearTimeout(priceRecalcDebouncers[roomNum]);
    }

    priceRecalcDebouncers[roomNum] = setTimeout(function() {
        doCollectAndRecalculate(roomNum);
    }, 600);
}

// Collect the per-child ages from the hidden child_age_* inputs.
// For multi-room: only this room's children; for single room: all children.
// The inputs are pre-seeded from the search occupancy and updated on DOB
// entry, so they always reflect the party this offer was priced for.
function collectChildrenAges(roomNum) {
    var childrenAges = [];
    var isMultiRoom = window.bookingData && window.bookingData.numRooms > 1;
    var selector = isMultiRoom
        ? '[id^="child_age_r' + roomNum + '_c"]'
        : '[id^="child_age_"]';

    document.querySelectorAll(selector).forEach(function(input) {
        var age = parseInt(input.value, 10);
        if (!isNaN(age) && age >= 0 && age < 18) {
            childrenAges.push(age);
        }
    });
    novotonLog('Collected children ages' + (isMultiRoom ? ' for room ' + roomNum : ''), childrenAges);
    return childrenAges;
}

function doCollectAndRecalculate(roomNum) {
    var childrenAges = collectChildrenAges(roomNum);

    if (childrenAges.length > 0) {
        triggerPriceRecalculationInline(childrenAges, roomNum);
    }
}

// A74e: Inline price recalculation to avoid external JS loading issues
// A74y: Updated to handle per-room recalculation for multi-room bookings
function triggerPriceRecalculationInline(childrenAges, roomNum, isInitialLoad) {
    roomNum = roomNum || 1;
    isInitialLoad = isInitialLoad || false;
    novotonLog('triggerPriceRecalculationInline called for room ' + roomNum, childrenAges);
    
    if (!window.bookingData) {
        novotonLog('bookingData not defined');
        return;
    }
    
    var isMultiRoom = window.bookingData.numRooms > 1 && window.bookingData.roomsData && window.bookingData.roomsData.length > 0;
    var roomIdx = roomNum - 1;
    
    // Get room-specific data for multi-room, or use single room data
    var roomData = {};
    if (isMultiRoom && window.bookingData.roomsData[roomIdx]) {
        roomData = window.bookingData.roomsData[roomIdx];
        novotonLog('Using room-specific data for room ' + roomNum, roomData);
    } else {
        roomData = {
            room_id: window.bookingData.roomId,
            board_id: window.bookingData.boardId,
            adults: window.bookingData.adults,
            price: window.bookingData.currentPrice
        };
    }
    
    // Show loading state for the specific room
    var priceEl = isMultiRoom ? 
        document.querySelector('.room-card[data-room-num="' + roomNum + '"] .room-price') || document.querySelector('.price-total') :
        document.querySelector('.price-total');
    var loadingIndicator = document.getElementById('price-loading-indicator');
    
    if (loadingIndicator) loadingIndicator.style.display = 'inline-block';
    if (priceEl) priceEl.style.opacity = '0.5';
    
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
    
    novotonLog('AJAX request', requestData);

    // Build a clean AJAX URL with only dispatch — all data goes in the JSON body.
    // Do NOT inherit parent page URL params (children_ages[], hotel_id, etc.)
    // as CS-Cart's init processes them through __() causing PHP warnings.
    var ajaxUrl = (window.bookingData && window.bookingData.ajaxRecalculateUrl) || 'index.php?dispatch=novoton_booking.ajax_recalculate_price';
    novotonLog('AJAX URL', ajaxUrl);
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(requestData)
    })
    .then(function(response) { 
        novotonLog('Response status: ' + response.status);
        return response.text(); // Get raw text first
    })
    .then(function(text) {
        novotonLog('Raw response', text.substring(0, 200));
        // Try to parse JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            novotonLog('JSON parse error: ' + e.message);
            throw e;
        }
    })
    .then(function(data) {
        novotonLog('AJAX response', data);

        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';

        if (data.success) {
            // Hide any previous error message
            hidePriceError();

            try {

            if (data.room_changed) {
                // The quote is for a DIFFERENT room — do NOT commit the price
                // yet. acceptRoomChangeInline applies price + room together
                // once the guest accepts; declining keeps the original state.
                window._roomChangeContext = { roomNum: roomNum, isMultiRoom: isMultiRoom, isInitialLoad: isInitialLoad };
                showRoomChangeModal(data);
            } else {
                applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad);
            }

            // Hide any previous notice
            var notice = document.getElementById('price-recalc-notice');
            if (notice) notice.style.display = 'none';

            } catch (uiError) {
                // JS error in UI update must NOT propagate to .catch() which disables submit
                novotonLog('UI update error (non-fatal): ' + uiError.message);
            }

        } else {
            novotonLog('Recalculation failed: ' + (data.message || ''));
            // API returned success:false — show info notice, keep form submittable
            showInfoNotice(nvtLabel('priceVerifiedAtCheckout', 'Prețul va fi verificat la finalizare'));
            if (priceEl) priceEl.style.opacity = '1';
        }
    })
    .catch(function(error) {
        novotonLog('AJAX error (no API response): ' + error);
        if (loadingIndicator) loadingIndicator.style.display = 'none';
        if (priceEl) priceEl.style.opacity = '1';
        // Network/JSON parse error — show warning but keep form submittable
        // Server-side will verify the price at checkout anyway
        showInfoNotice(nvtLabel('priceVerifiedAtCheckout', 'Prețul va fi verificat la finalizare'));
    });
}

// Commit a successful re-quote to the price displays, the hidden total_price
// input and bookingData. Called directly when the room is unchanged; when the
// server proposed a DIFFERENT room it runs only from acceptRoomChangeInline,
// so an undecided (or declined) room change never overwrites the form price.
function applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad) {
    var roomIdx = roomNum - 1;
    var newPrice = parseFloat(data.new_price) || 0;
    var coeff = window.NovotonTranslations.currencyCoeff || 1;
    var currSym = window.NovotonTranslations.currency || 'EUR';
    novotonLog('New price for room ' + roomNum + ': ' + newPrice + ' (coeff=' + coeff + ')');

    if (isMultiRoom && window.bookingData.roomsData && window.bookingData.roomsData[roomIdx]) {
        // Multi-room: Update only this room's price (EUR for form submission)
        window.bookingData.roomsData[roomIdx].price = newPrice;

        // Update the room card price display (converted to display currency)
        var roomPriceEl = document.querySelector('.room-card[data-room-num="' + roomNum + '"] .room-price');
        if (roomPriceEl) {
            roomPriceEl.textContent = Math.round(newPrice * coeff) + ' ' + currSym;
        }

        // Recalculate total from all rooms (in EUR)
        var totalPrice = 0;
        for (var i = 0; i < window.bookingData.roomsData.length; i++) {
            totalPrice += parseFloat(window.bookingData.roomsData[i].price) || 0;
        }

        novotonLog('New total price: ' + totalPrice);

        // Update total price display (converted to display currency)
        var displayTotal = (totalPrice * coeff).toFixed(2);
        document.querySelectorAll('.price-total').forEach(function(el) {
            el.textContent = displayTotal;
        });

        // A76i: Update hidden total_price input for form submission (EUR)
        var hiddenPriceInput = document.querySelector('input[name="total_price"]');
        if (hiddenPriceInput) {
            hiddenPriceInput.value = totalPrice.toFixed(2);
            novotonLog('Updated hidden total_price to: ' + totalPrice.toFixed(2));
        }

        // Update bookingData total
        var priceDiff = totalPrice - window.bookingData.currentPrice;
        window.bookingData.currentPrice = totalPrice;

        // Show price change notification (skip on initial load — wording is child-age specific)
        if (!isInitialLoad && Math.abs(priceDiff) > 0.01) {
            showPriceNotification(priceDiff * coeff);
        }
    } else {
        // Single room: Update total price display (converted to display currency)
        var displayPrice = (newPrice * coeff).toFixed(2);
        document.querySelectorAll('.price-total').forEach(function(el) {
            el.textContent = displayPrice;
        });

        // A76i: Update hidden total_price input for form submission (EUR)
        var hiddenPriceInput = document.querySelector('input[name="total_price"]');
        if (hiddenPriceInput) {
            hiddenPriceInput.value = newPrice.toFixed(2);
            novotonLog('Updated hidden total_price to: ' + newPrice.toFixed(2));
        }

        // Show price change notification (skip on initial load — wording is child-age specific)
        if (!isInitialLoad && data.price_difference && data.price_difference !== 0) {
            showPriceNotification(data.price_difference * coeff);
        }

        // Update bookingData (EUR)
        window.bookingData.currentPrice = newPrice;
    }
}

// Show price error, refresh link, unverified badge, and disable submit
function showPriceError(message) {
    var errorEl = document.getElementById('price-error-message');
    var refreshLink = document.getElementById('refresh-price-link');
    var unverifiedBadge = document.getElementById('price-unverified-badge');
    var submitBtn = document.getElementById('booking-submit-btn');
    var availBadge = document.getElementById('availability-badge');

    if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }
    if (refreshLink) {
        refreshLink.style.display = 'block';
    }
    if (unverifiedBadge) {
        unverifiedBadge.style.display = 'inline-block';
    }
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
        submitBtn.title = nvtLabel('priceMustBeVerified', 'Prețul trebuie verificat înainte de a continua');
    }
    if (availBadge) {
        availBadge.style.setProperty('background', '#F59E0B', 'important');
        availBadge.innerHTML = '<strong>' + nvtLabel('unavailableForChildAge', 'Indisponibil') + '</strong><br><span style="font-size:11px;">' + nvtLabel('unavailableForChildAgeSub', 'pentru vârsta copilului') + '</span>';
    }
}

// Hide price error, refresh link, unverified badge, and re-enable submit
function hidePriceError() {
    var errorEl = document.getElementById('price-error-message');
    var refreshLink = document.getElementById('refresh-price-link');
    var unverifiedBadge = document.getElementById('price-unverified-badge');
    var submitBtn = document.getElementById('booking-submit-btn');
    var availBadge = document.getElementById('availability-badge');

    if (errorEl) errorEl.style.display = 'none';
    if (refreshLink) refreshLink.style.display = 'none';
    if (unverifiedBadge) unverifiedBadge.style.display = 'none';
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
        submitBtn.title = '';
    }
    if (availBadge) {
        availBadge.style.setProperty('background', '#28a745', 'important');
        availBadge.innerHTML = '✓ ' + nvtLabel('available', 'Disponibil');
    }
}

// Refresh price manually
function refreshPrice() {
    novotonLog('Manual price refresh triggered');
    hidePriceError();

    // Collect all children ages from the form
    var childrenAges = [];
    document.querySelectorAll('[id^="child_age_"]').forEach(function(input) {
        var age = parseInt(input.value, 10);
        if (!isNaN(age) && age >= 0 && age < 18) {
            childrenAges.push(age);
        }
    });

    novotonLog('Refreshing with children ages', childrenAges);
    triggerPriceRecalculationInline(childrenAges, 1);
}

function showPriceNotification(difference) {
    // Show single notification above guest details heading
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
    notif.innerHTML = nvtLabel('priceUpdatedChildAge', 'Prețul a fost actualizat în funcție de vârsta copilului') + ': <strong style="color:' + changeColor + '">' + changeText + ' ' + (window.NovotonTranslations.currency || 'EUR') + '</strong>';
    // Note: difference is already in display currency (multiplied by coefficient before calling this function)
    notif.style.display = 'block';
}

function showInfoNotice(message) {
    var notif = document.getElementById('price-recalc-notice');
    if (!notif) {
        notif = document.createElement('div');
        notif.id = 'price-recalc-notice';
        notif.style.cssText = 'background:#e7f3ff;border-left:4px solid #0071c2;color:#004085;padding:10px 15px;margin:10px 0;border-radius:4px;font-size:13px;';
        var priceBox = document.querySelector('.booking-price-box');
        if (priceBox && priceBox.parentNode) {
            priceBox.parentNode.insertBefore(notif, priceBox.nextSibling);
        }
    }
    notif.innerHTML = ' ' + message;
    notif.style.display = 'block';
}

function showRoomChangeModal(data) {
    novotonLog('Showing room change modal', data);
    
    var existing = document.getElementById('room-change-warning');
    if (existing) existing.remove();
    
    var coeff = window.NovotonTranslations.currencyCoeff || 1;
    var currSym = window.NovotonTranslations.currency || 'EUR';
    var priceDiff = (parseFloat(data.price_difference) || 0) * coeff;
    var newPrice = (parseFloat(data.new_price) || 0) * coeff;
    var originalPrice = (parseFloat(data.original_price) || 0) * coeff;

    var priceDiffText = '', priceDiffStyle = '';
    if (priceDiff > 0) {
        priceDiffText = '+' + priceDiff.toFixed(2) + ' ' + currSym;
        priceDiffStyle = 'color:#dc3545;font-weight:bold;';
    } else if (priceDiff < 0) {
        priceDiffText = priceDiff.toFixed(2) + ' ' + currSym;
        priceDiffStyle = 'color:#28a745;font-weight:bold;';
    }
    
    var html = '<div id="room-change-warning" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;">' +
        '<div style="background:#fff;border-radius:12px;padding:25px;max-width:450px;margin:20px;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
        '<div style="text-align:center;margin-bottom:20px;">' +
            '<div style="font-size:40px;margin-bottom:10px;"></div>' +
            '<h3 style="margin:0;color:#856404;font-size:18px;">' + nvtLabel('roomChangedTitle', 'Camera s-a modificat') + '</h3>' +
        '</div>' +
        '<p style="text-align:center;color:#666;margin-bottom:20px;font-size:14px;">' + nvtLabel('roomChangedDueToAge', 'Camera selectata nu este disponibila pentru varsta copilului introdusa.') + '</p>' +
        '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:15px;margin-bottom:20px;">' +
            '<div style="display:flex;align-items:center;justify-content:center;gap:15px;flex-wrap:wrap;">' +
                '<div style="text-align:center;">' +
                    '<div style="font-size:11px;color:#666;text-transform:uppercase;">' + nvtLabel('originalRoom', 'Camera selectata') + '</div>' +
                    '<div style="font-weight:600;color:#856404;text-decoration:line-through;">' + escapeHtml(data.original_room || '') + '</div>' +
                '</div>' +
                '<div style="font-size:24px;color:#856404;">-></div>' +
                '<div style="text-align:center;">' +
                    '<div style="font-size:11px;color:#666;text-transform:uppercase;">' + nvtLabel('newRoom', 'Camera noua') + '</div>' +
                    '<div style="font-weight:600;color:#155724;">' + escapeHtml(data.new_room || '') + '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div style="background:#f8f9fa;border-radius:8px;padding:15px;margin-bottom:20px;text-align:center;">' +
            '<div style="font-size:12px;color:#666;margin-bottom:5px;">' + nvtLabel('priceChange', 'Modificare pret') + '</div>' +
            '<div style="font-size:20px;">' +
                '<span style="text-decoration:line-through;color:#999;">' + originalPrice.toFixed(2) + ' ' + currSym + '</span> ' +
                '<span style="' + priceDiffStyle + '">(' + priceDiffText + ')</span> ' +
                '<span style="font-weight:bold;color:#003580;">' + newPrice.toFixed(2) + ' ' + currSym + '</span>' +
            '</div>' +
        '</div>' +
        '<div style="display:flex;gap:10px;justify-content:center;">' +
            '<button type="button" onclick="closeRoomModal();window.history.back();" style="padding:12px 20px;border:2px solid #003580;background:#fff;color:#003580;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;"><- ' + nvtLabel('goBackToSearch', 'Inapoi la cautare') + '</button>' +
            '<button type="button" onclick="acceptRoomChangeInline()" style="padding:12px 20px;border:none;background:#003580;color:#fff;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">' + nvtLabel('continueWithNewRoom', 'Continua cu noua camera') + ' -></button>' +
        '</div>' +
        '</div></div>';
    
    window._roomChangeData = data;
    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper.firstChild);
}

function closeRoomModal() {
    var modal = document.getElementById('room-change-warning');
    if (modal) modal.remove();
}

function acceptRoomChangeInline() {
    var data = window._roomChangeData || {};
    var ctx = window._roomChangeContext || {};
    closeRoomModal();

    // Commit the accepted quote: price first, then the room identity below,
    // so the form never holds the new price with the old room or vice versa.
    applyRecalculatedPrice(data, ctx.roomNum || 1, !!ctx.isMultiRoom, !!ctx.isInitialLoad);

    // Format room name for display using translated room type prefix
    var displayRoom = data.new_room || '';
    if (displayRoom && !displayRoom.toLowerCase().includes('camer')) {
        var roomTypeLabel = nvtLabel('roomTypeDouble', 'Double Room');
        displayRoom = roomTypeLabel + ' (' + displayRoom + ')';
    }
    
    // Build display text with board and price if available
    var fullDisplayText = displayRoom;
    if (data.board_name) {
        fullDisplayText += ' - ' + data.board_name;
    }
    if (data.new_price) {
        var displayNewPrice = parseFloat(data.new_price) * (window.NovotonTranslations.currencyCoeff || 1);
        fullDisplayText += ' (' + displayNewPrice.toFixed(0) + ' ' + (window.NovotonTranslations.currency || 'EUR') + ')';
    }
    
    // Check if this is for a specific room in multi-room booking
    // (the recalc response carries no room_num — fall back to the request context)
    var roomNum = data.room_num || data.roomNum || (ctx.isMultiRoom ? ctx.roomNum : null);
    
    if (roomNum) {
        // Multi-room: Update only the specific room's header
        var specificRoomEl = document.querySelector('.room-type-full[data-room-num="' + roomNum + '"]');
        if (specificRoomEl) {
            specificRoomEl.textContent = fullDisplayText;
        }
        
        // Also update room-name elements with matching data-room-num
        document.querySelectorAll('.room-name[data-room-num="' + roomNum + '"], [data-room-name][data-room-num="' + roomNum + '"]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });
    } else {
        // Single room or fallback: Update all room displays
        document.querySelectorAll('.room-name, [data-room-name]').forEach(function(el) {
            el.textContent = data.new_room || '';
        });
        
        // Update room type in header (Tip Camera)
        document.querySelectorAll('.room-type-full').forEach(function(el) {
            // For multi-room displays with board/price info
            if (el.hasAttribute('data-room-num')) {
                el.textContent = fullDisplayText;
            } else {
                el.textContent = displayRoom;
            }
        });
    }
    
    // Update hidden field
    var roomInput = document.querySelector('input[name="room_id"]');
    if (roomInput) roomInput.value = data.new_room || '';
    
    // Update bookingData
    if (window.bookingData) {
        window.bookingData.roomId = data.new_room || '';
        window.bookingData.roomName = displayRoom;
        
        // If multi-room, also update the rooms_data array
        if (roomNum && window.bookingData.roomsData) {
            var idx = parseInt(roomNum) - 1;
            if (window.bookingData.roomsData[idx]) {
                window.bookingData.roomsData[idx].room_id = data.new_room || '';
                window.bookingData.roomsData[idx].room_name = displayRoom;
                if (data.board_id) window.bookingData.roomsData[idx].board_id = data.board_id;
                if (data.board_name) window.bookingData.roomsData[idx].board_name = data.board_name;
                if (data.new_price) window.bookingData.roomsData[idx].price = parseFloat(data.new_price);
            }
        }
    }
    
    // Show confirmation
    var notif = document.createElement('div');
    notif.style.cssText = 'background:#d4edda;border-left:4px solid #28a745;color:#155724;padding:15px;margin:15px 0;border-radius:4px;font-size:14px;';
    var roomLabel = roomNum ? nvtLabel('roomNumber', 'Camera') + ' ' + roomNum + ': ' : '';
    var confirmPrice = ((parseFloat(data.new_price) || 0) * (window.NovotonTranslations.currencyCoeff || 1)).toFixed(2);
    notif.innerHTML = '✓ <strong>' + nvtLabel('roomUpdated', 'Camera a fost actualizata:') + '</strong> ' + escapeHtml(roomLabel) + escapeHtml(data.new_room || '') + ' - ' + confirmPrice + ' ' + (window.NovotonTranslations.currency || 'EUR');
    
    var section = document.querySelector('.guest-names-section h3');
    if (section && section.parentNode) {
        section.parentNode.insertBefore(notif, section.nextSibling);
    }
    
    setTimeout(function() { if (notif.parentNode) notif.remove(); }, 10000);
}

// Verify price on initial booking-form load using the actual room/board IDs.
// The search page uses blank room_id/board_id (one API call for all rooms); the Novoton
// API can return a different price for that query than for a room-specific query.
// Calling ajax_recalculate_price here populates the cache with the binding price and
// updates the hidden total_price field so add_to_cart sees no change.
// MUST send the real searched children ages (pre-seeded in the hidden
// child_age_* inputs): an empty list re-quotes for adults-only occupancy, so
// the operator omits the child-capacity room and the endpoint substitutes an
// arbitrary 2-adult room — popping a bogus "room changed" modal before any
// DOB is typed (QUAD 3+1 SEA -> DBL 2+0 regression).
document.addEventListener('DOMContentLoaded', function() {
    if (typeof triggerPriceRecalculationInline !== 'function') return;
    if (!window.bookingData || !window.bookingData.hotelId) return;

    var isMultiRoom = window.bookingData.numRooms > 1 &&
                      window.bookingData.roomsData &&
                      window.bookingData.roomsData.length > 1;

    if (isMultiRoom) {
        for (var r = 1; r <= window.bookingData.numRooms; r++) {
            (function(roomNum) {
                setTimeout(function() {
                    triggerPriceRecalculationInline(collectChildrenAges(roomNum), roomNum, true);
                }, (roomNum - 1) * 400);
            })(r);
        }
    } else {
        triggerPriceRecalculationInline(collectChildrenAges(1), 1, true);
    }
});

// Explicit window bridge. Loaded classically ({script}) these declarations
// are already globals, but the template's inline on*= handlers and the
// module-built modal markup depend on that — and vitest imports this file as
// an ES module, where top-level declarations stay module-scoped. Being
// explicit makes both loaders behave identically.
window.nvtLabel = nvtLabel;
window.escapeHtml = escapeHtml;
window.validateAndCheckAge = validateAndCheckAge;
window.collectChildrenAges = collectChildrenAges;
window.triggerPriceRecalculationInline = triggerPriceRecalculationInline;
window.applyRecalculatedPrice = applyRecalculatedPrice;
window.refreshPrice = refreshPrice;
window.showRoomChangeModal = showRoomChangeModal;
window.closeRoomModal = closeRoomModal;
window.acceptRoomChangeInline = acceptRoomChangeInline;
