/**
 * Novoton Holidays - DOB Validation + Price Recalculation + Desktop/Mobile Fix
 * Version: A73
 * 
 * Features:
 * 1. Prevents Date of Birth from being set in the future
 * 2. Recalculates price when child age changes based on DOB
 * 3. Enforces desktop/mobile visibility as failsafe
 */

(function() {
    'use strict';
    
    // Debounce timer for price recalculation
    var priceRecalcTimer = null;
    var isRecalculating = false;
    
    // Run on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[Novoton A73] Initializing DOB validation, price recalc, and visibility fix');
        initDOBValidation();
        fixDesktopMobileVisibility();
        observeGuestChanges();
    });
    
    // Also run on window load
    window.addEventListener('load', function() {
        fixDesktopMobileVisibility();
    });
    
    /**
     * Initialize Date of Birth validation
     * Prevents dates in the future from being selected
     */
    function initDOBValidation() {
        // Find all DOB input fields
        var dobFields = document.querySelectorAll(
            'input[name*="dob"], input[name*="date_of_birth"], input[name*="birth_date"], ' +
            'input.guest-dob-input, input[data-field="dob"], input.dob-masked-input'
        );
        
        var today = new Date().toISOString().split('T')[0];
        
        dobFields.forEach(function(field) {
            // Set max date to today
            if (field.type === 'date') {
                field.setAttribute('max', today);
            }
            
            // Add validation on change
            field.addEventListener('change', function() {
                validateDOBField(this);
            });
            
            // Add validation on blur
            field.addEventListener('blur', function() {
                validateDOBField(this);
            });
        });
        
        console.log('[Novoton A73] DOB validation initialized for', dobFields.length, 'fields');
    }
    
    /**
     * Validate a single DOB field
     */
    function validateDOBField(field) {
        var value = field.value;
        
        // Skip if empty
        if (!value) {
            clearDOBError(field);
            return true;
        }
        
        // Parse date - handle both YYYY-MM-DD and DD/MM/YYYY formats
        var selectedDate;
        if (value.includes('/')) {
            var parts = value.split('/');
            if (parts.length === 3) {
                selectedDate = new Date(parts[2], parts[1] - 1, parts[0]);
            }
        } else {
            selectedDate = new Date(value);
        }
        
        var today = new Date();
        
        // Set time to midnight for accurate comparison
        today.setHours(0, 0, 0, 0);
        if (selectedDate) {
            selectedDate.setHours(0, 0, 0, 0);
        }
        
        // Check if date is in the future
        if (selectedDate && selectedDate > today) {
            showDOBError(field);
            return false;
        }
        
        // Valid date - clear any error
        clearDOBError(field);
        
        // A73: Trigger price recalculation if this is a child field on booking form
        if (isChildDOBField(field)) {
            triggerPriceRecalculation(field);
        }
        
        return true;
    }
    
    /**
     * Show DOB error message
     */
    function showDOBError(field) {
        // Get error message from translations or use default
        var errorMessage = (window.NovotonTranslations && window.NovotonTranslations.dobCannotBeFuture) 
            ? window.NovotonTranslations.dobCannotBeFuture 
            : 'Data nașterii nu poate fi în viitor';
        
        // Mark field as invalid
        field.classList.add('novoton-dob-error');
        field.style.borderColor = '#dc3545';
        field.style.backgroundColor = '#fff5f5';
        
        // Find or create error message element
        var fieldId = field.id || field.name || ('dob-field-' + Math.random().toString(36).substr(2, 9));
        var errorId = fieldId + '-dob-error';
        var existingError = document.getElementById(errorId);
        
        if (!existingError && field.parentNode) {
            var errorElement = document.createElement('div');
            errorElement.id = errorId;
            errorElement.className = 'novoton-dob-error-message';
            errorElement.style.cssText = 'color: #dc3545; font-size: 12px; margin-top: 4px; display: flex; align-items: center; gap: 4px;';
            errorElement.innerHTML = '<span style="font-size: 14px;">⚠️</span> ' + errorMessage;
            
            // Insert after the field
            field.parentNode.insertBefore(errorElement, field.nextSibling);
        } else if (existingError) {
            existingError.style.display = 'flex';
        }
        
        // Set custom validity for form validation
        if (field.setCustomValidity) {
            field.setCustomValidity(errorMessage);
        }
        
        // Clear field and refocus
        field.value = '';
        field.focus();
        
        console.log('[Novoton A73] DOB validation error: Date is in the future');
    }
    
    /**
     * Clear DOB error message
     */
    function clearDOBError(field) {
        field.classList.remove('novoton-dob-error');
        field.style.borderColor = '';
        field.style.backgroundColor = '';
        
        // Hide error message if exists
        var errorId = (field.id || field.name);
        if (errorId) {
            var errorElement = document.getElementById(errorId + '-dob-error');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        } else {
            // Fallback: find error element by adjacency (matches random ID from showDOBError)
            var nextEl = field.nextSibling;
            if (nextEl && nextEl.className === 'novoton-dob-error-message') {
                nextEl.style.display = 'none';
            }
        }
        
        // Clear custom validity
        if (field.setCustomValidity) {
            field.setCustomValidity('');
        }
    }
    
    /**
     * A73: Check if field is a child DOB field on booking form
     */
    function isChildDOBField(field) {
        var name = field.name || '';
        var id = field.id || '';
        
        // Check if it's a child DOB field (matches pattern like guests[room1_child_1][dob])
        return (name.includes('child') && name.includes('dob')) ||
               (id.includes('child') && id.includes('dob'));
    }
    
    /**
     * A73: Trigger price recalculation (debounced)
     */
    function triggerPriceRecalculation(changedField) {
        // Clear any pending recalculation
        if (priceRecalcTimer) {
            clearTimeout(priceRecalcTimer);
        }
        
        // Debounce - wait 500ms after last change before recalculating
        priceRecalcTimer = setTimeout(function() {
            recalculatePrice(changedField);
        }, 500);
    }
    
    /**
     * A73: Recalculate price based on current DOB values
     */
    function recalculatePrice(changedField) {
        if (isRecalculating) {
            console.log('[Novoton A73] Price recalculation already in progress');
            return;
        }
        
        // Find the booking form
        var form = document.querySelector('.novoton-reservation-form form, .novoton-booking-form form, #novoton-booking-form');
        if (!form) {
            console.log('[Novoton A73] Booking form not found');
            return;
        }
        
        // Get booking data from form hidden fields
        var hotelId = form.querySelector('input[name="hotel_id"]')?.value || '';
        var roomId = form.querySelector('input[name="room_id"]')?.value || '';
        var boardId = form.querySelector('input[name="board_id"]')?.value || '';
        var checkIn = form.querySelector('input[name="check_in"]')?.value || '';
        var nights = parseInt(form.querySelector('input[name="nights"]')?.value || '7', 10);
        var adults = parseInt(form.querySelector('input[name="adults"]')?.value || '2', 10);
        var originalPrice = parseFloat(form.querySelector('input[name="total_price"]')?.value || '0');
        var packageName = form.querySelector('input[name="package_name"]')?.value || '';
        
        if (!hotelId || !checkIn) {
            console.log('[Novoton A73] Missing hotel_id or check_in for price recalculation');
            return;
        }
        
        // Collect all children ages from DOB fields
        var childrenAges = collectChildrenAges(form, checkIn);
        
        // Update the hidden children_ages field
        var childrenAgesField = form.querySelector('input[name="children_ages"]');
        if (childrenAgesField) {
            childrenAgesField.value = childrenAges.join(',');
        }
        
        // Update the age display for the changed field
        updateAgeDisplay(changedField, checkIn);
        
        console.log('[Novoton A73] Recalculating price with ages:', childrenAges);
        
        // Show loading indicator
        showPriceLoading();
        isRecalculating = true;
        
        // Make AJAX request
        var requestData = {
            hotel_id: hotelId,
            room_id: roomId,
            board_id: boardId,
            check_in: checkIn,
            nights: nights,
            adults: adults,
            children_ages: childrenAges,
            package_name: packageName,
            original_price: originalPrice
        };
        
        // Build AJAX URL - try to use global config or relative path
        var ajaxUrl = '';
        if (typeof window.fn_url === 'function') {
            ajaxUrl = window.fn_url('novoton_booking.ajax_recalculate_price');
        } else if (window.Tygh && window.Tygh.current_url) {
            ajaxUrl = window.Tygh.current_url.replace(/dispatch=[^&]+/, 'dispatch=novoton_booking.ajax_recalculate_price');
        } else {
            // Fallback - use relative URL that works from any page
            ajaxUrl = window.location.pathname.replace(/\/[^\/]*$/, '/') + 'index.php?dispatch=novoton_booking.ajax_recalculate_price';
        }
        
        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            isRecalculating = false;
            hidePriceLoading();
            
            if (data.success) {
                updatePriceDisplay(data.new_price, data.price_difference);
                
                // Update the hidden total_price field
                var totalPriceField = form.querySelector('input[name="total_price"]');
                if (totalPriceField) {
                    totalPriceField.value = data.new_price;
                }
                
                console.log('[Novoton A73] Price updated:', data.new_price, 'EUR');
            } else {
                console.warn('[Novoton A73] Price recalculation failed:', data.message);
                showPriceError(data.message || 'Price calculation error');
            }
        })
        .catch(function(error) {
            isRecalculating = false;
            hidePriceLoading();
            console.error('[Novoton A73] Price recalculation error:', error);
        });
    }
    
    /**
     * A73: Collect all children ages from DOB fields
     */
    function collectChildrenAges(form, checkIn) {
        var ages = [];
        var checkInDate = new Date(checkIn);
        
        // Find all child DOB fields
        var childDobFields = form.querySelectorAll(
            'input[name*="child"][name*="dob"], input[id*="child"][id*="dob"]'
        );
        
        childDobFields.forEach(function(field) {
            var dobValue = field.value;
            if (!dobValue) {
                // If no DOB, try to get the original age from data attribute
                var guestEntry = field.closest('.guest-entry-child, .guest-entry');
                var originalAge = guestEntry ? parseInt(guestEntry.dataset.originalAge || '0', 10) : 0;
                if (originalAge > 0 && originalAge <= 17) {
                    ages.push(originalAge);
                }
                return;
            }
            
            // Parse DOB
            var dob;
            if (dobValue.includes('/')) {
                var parts = dobValue.split('/');
                if (parts.length === 3) {
                    dob = new Date(parts[2], parts[1] - 1, parts[0]);
                }
            } else {
                dob = new Date(dobValue);
            }
            
            if (!dob || isNaN(dob.getTime())) return;
            
            // Calculate age at check-in
            var age = checkInDate.getFullYear() - dob.getFullYear();
            var monthDiff = checkInDate.getMonth() - dob.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && checkInDate.getDate() < dob.getDate())) {
                age--;
            }
            
            // Only include valid child ages (0-17)
            if (age >= 0 && age <= 17) {
                ages.push(age);
            }
        });
        
        return ages;
    }
    
    /**
     * A73: Update the age display next to the child DOB field
     */
    function updateAgeDisplay(field, checkIn) {
        var dobValue = field.value;
        if (!dobValue) return;
        
        // Parse DOB
        var dob;
        if (dobValue.includes('/')) {
            var parts = dobValue.split('/');
            if (parts.length === 3) {
                dob = new Date(parts[2], parts[1] - 1, parts[0]);
            }
        } else {
            dob = new Date(dobValue);
        }
        
        if (!dob || isNaN(dob.getTime())) return;
        
        var checkInDate = new Date(checkIn);
        var age = checkInDate.getFullYear() - dob.getFullYear();
        var monthDiff = checkInDate.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && checkInDate.getDate() < dob.getDate())) {
            age--;
        }
        
        // Find the age display element - try different selectors
        var fieldId = field.id || '';
        var roomMatch = fieldId.match(/r(\d+)/);
        var childMatch = fieldId.match(/c(\d+)/);
        
        if (roomMatch && childMatch) {
            var ageDisplayId = 'child_age_display_r' + roomMatch[1] + '_c' + childMatch[1];
            var ageDisplay = document.getElementById(ageDisplayId);

            if (ageDisplay) {
                // Use singular/plural form (Romanian: "1 an", "2 ani")
                var ageLabel;
                if (age === 1) {
                    ageLabel = (window.NovotonTranslations && window.NovotonTranslations.ageLabelSingular) || 'an';
                } else {
                    ageLabel = (window.NovotonTranslations && window.NovotonTranslations.ageLabel) || 'ani';
                }
                ageDisplay.textContent = '(' + age + ' ' + ageLabel + ')';

                // Show warning if age is 18+
                if (age >= 18) {
                    ageDisplay.style.color = '#dc3545';
                    ageDisplay.textContent += ' ⚠️ ' + ((window.NovotonTranslations && window.NovotonTranslations.childMustBeUnder18) || 'Must be under 18');
                } else {
                    ageDisplay.style.color = '';
                }
            }
        }
        
        // Update hidden age field
        var guestEntry = field.closest('.guest-entry-child, .guest-entry');
        if (guestEntry) {
            var ageInput = guestEntry.querySelector('input[name*="[age]"]');
            if (ageInput) {
                ageInput.value = age;
            }
        }
    }
    
    /**
     * A73: Show loading indicator on price
     */
    function showPriceLoading() {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );
        
        priceElements.forEach(function(el) {
            el.dataset.originalText = el.textContent;
            el.innerHTML = '<span style="opacity: 0.5;">⟳</span>';
            el.style.opacity = '0.7';
        });
    }
    
    /**
     * A73: Hide loading indicator and update price
     */
    function hidePriceLoading() {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );
        
        priceElements.forEach(function(el) {
            el.style.opacity = '1';
        });
    }
    
    /**
     * A73: Update the displayed price
     */
    function updatePriceDisplay(newPrice, priceDifference) {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total, .novoton-total-price'
        );
        
        var formattedPrice = newPrice.toFixed(2);
        
        priceElements.forEach(function(el) {
            // Check if it's a price-only element or includes currency
            var hasChildren = el.querySelector('.price-currency, .currency');
            if (hasChildren) {
                // Just update the number part
                var textNode = el.firstChild;
                if (textNode && textNode.nodeType === 3) {
                    textNode.textContent = formattedPrice;
                }
            } else {
                el.textContent = formattedPrice;
            }
            
            // Show price change indicator
            if (priceDifference !== 0) {
                el.style.transition = 'color 0.3s';
                if (priceDifference > 0) {
                    el.style.color = '#dc3545'; // Red for price increase
                } else {
                    el.style.color = '#28a745'; // Green for price decrease
                }
                
                // Reset color after 3 seconds
                setTimeout(function() {
                    el.style.color = '';
                }, 3000);
            }
        });
        
        // Show notification about price change - DISABLED: Using inline notification in booking_form.tpl instead
        // if (priceDifference !== 0) {
        //     var diffText = priceDifference > 0 
        //         ? '+' + priceDifference.toFixed(2) + ' EUR' 
        //         : priceDifference.toFixed(2) + ' EUR';
        //     
        //     showPriceChangeNotification(diffText, priceDifference > 0 ? 'increase' : 'decrease');
        // }
    }
    
    /**
     * A73: Show price change notification
     */
    function showPriceChangeNotification(text, type) {
        // Remove existing notification
        var existing = document.getElementById('novoton-price-notification');
        if (existing) {
            existing.remove();
        }
        
        var notification = document.createElement('div');
        notification.id = 'novoton-price-notification';
        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 15px 25px; ' +
            'border-radius: 8px; font-weight: 600; z-index: 10000; animation: slideIn 0.3s ease; ' +
            'box-shadow: 0 4px 15px rgba(0,0,0,0.2);';
        
        if (type === 'increase') {
            notification.style.backgroundColor = '#fff5f5';
            notification.style.color = '#dc3545';
            notification.style.border = '1px solid #dc3545';
            notification.innerHTML = '📈 ' + ((window.NovotonTranslations && window.NovotonTranslations.priceIncreased) || 'Prețul a crescut') + ': ' + text;
        } else {
            notification.style.backgroundColor = '#f0fff4';
            notification.style.color = '#28a745';
            notification.style.border = '1px solid #28a745';
            notification.innerHTML = '📉 ' + ((window.NovotonTranslations && window.NovotonTranslations.priceDecreased) || 'Prețul a scăzut') + ': ' + text;
        }
        
        document.body.appendChild(notification);
        
        // Remove after 5 seconds
        setTimeout(function() {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.3s';
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 5000);
    }
    
    /**
     * A73: Show price calculation error
     */
    function showPriceError(message) {
        var priceElements = document.querySelectorAll(
            '.price-total, .booking-price-box .price-total'
        );
        
        priceElements.forEach(function(el) {
            if (el.dataset.originalText) {
                el.textContent = el.dataset.originalText;
            }
        });
        
        console.warn('[Novoton A73] Price error:', message);
    }
    
    /**
     * Observe for dynamically added guest fields
     */
    function observeGuestChanges() {
        var containers = document.querySelectorAll(
            '.novoton-guests-container, .guest-details-container, #guest-details, ' +
            '.booking-guests, .novoton-booking-form, #booking-form'
        );
        
        if (containers.length === 0) {
            // Try again later if not found yet
            setTimeout(observeGuestChanges, 2000);
            return;
        }
        
        containers.forEach(function(container) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        setTimeout(initDOBValidation, 100);
                    }
                });
            });
            
            observer.observe(container, { childList: true, subtree: true });
        });
        
        console.log('[Novoton A73] Guest observer initialized for', containers.length, 'containers');
    }
    
    /**
     * Fix desktop/mobile visibility as JavaScript failsafe
     */
    function fixDesktopMobileVisibility() {
        var isMobile = window.innerWidth <= 768;
        
        // Find mobile-only elements
        var mobileElements = document.querySelectorAll(
            '.novoton-mobile-only, .novoton-mobile-only.novoton-room-card'
        );
        
        // Find desktop-only elements
        var desktopElements = document.querySelectorAll(
            '.novoton-desktop-only, .novoton-table-header.novoton-desktop-only, ' +
            '.result-row.novoton-desktop-only'
        );
        
        if (isMobile) {
            // MOBILE: Show mobile, hide desktop
            mobileElements.forEach(function(el) {
                el.style.setProperty('display', 'block', 'important');
                el.style.setProperty('visibility', 'visible', 'important');
                el.style.setProperty('height', 'auto', 'important');
                el.style.setProperty('position', 'relative', 'important');
                el.style.setProperty('left', 'auto', 'important');
            });
            desktopElements.forEach(function(el) {
                el.style.setProperty('display', 'none', 'important');
                el.style.setProperty('visibility', 'hidden', 'important');
            });
        } else {
            // DESKTOP: Show desktop, hide mobile
            mobileElements.forEach(function(el) {
                el.style.setProperty('display', 'none', 'important');
                el.style.setProperty('visibility', 'hidden', 'important');
                el.style.setProperty('height', '0', 'important');
                el.style.setProperty('overflow', 'hidden', 'important');
                el.style.setProperty('position', 'absolute', 'important');
                el.style.setProperty('left', '-9999px', 'important');
            });
            desktopElements.forEach(function(el) {
                el.style.setProperty('display', 'grid', 'important');
                el.style.setProperty('visibility', 'visible', 'important');
                el.style.setProperty('position', 'relative', 'important');
                el.style.setProperty('left', 'auto', 'important');
            });
        }
        
        console.log('[Novoton A73] Visibility fix applied. Mobile mode:', isMobile, 
                    '| Mobile elements:', mobileElements.length, 
                    '| Desktop elements:', desktopElements.length);
    }
    
    // Re-run visibility fix on resize (debounced)
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fixDesktopMobileVisibility, 250);
    });
    
    /**
     * Global function to validate all DOB fields before form submission
     */
    window.validateAllDOBFields = function() {
        var dobFields = document.querySelectorAll(
            'input[name*="dob"], input[name*="date_of_birth"], input[name*="birth_date"], ' +
            'input.guest-dob-input, input[data-field="dob"], input.dob-masked-input'
        );
        var allValid = true;
        
        dobFields.forEach(function(field) {
            if (!validateDOBField(field)) {
                allValid = false;
            }
        });
        
        if (!allValid) {
            var firstError = document.querySelector('.novoton-dob-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        
        return allValid;
    };
    
    /**
     * Validate DOB for check-in age calculation
     */
    window.validateDOBForCheckIn = function(dobValue, checkInDate) {
        var result = { valid: true, age: 0, error: null };
        
        if (!dobValue) return result;
        
        var dob = new Date(dobValue);
        var today = new Date();
        var checkIn = checkInDate ? new Date(checkInDate) : today;
        
        today.setHours(0, 0, 0, 0);
        dob.setHours(0, 0, 0, 0);
        
        if (dob > today) {
            result.valid = false;
            result.error = (window.NovotonTranslations && window.NovotonTranslations.dobCannotBeFuture) 
                ? window.NovotonTranslations.dobCannotBeFuture 
                : 'Data nașterii nu poate fi în viitor';
            return result;
        }
        
        // Calculate age at check-in
        var age = checkIn.getFullYear() - dob.getFullYear();
        var monthDiff = checkIn.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && checkIn.getDate() < dob.getDate())) {
            age--;
        }
        
        result.age = age;
        return result;
    };
    
    // Expose functions globally
    window.initNovotonDOBValidation = initDOBValidation;
    window.novotonFixVisibility = fixDesktopMobileVisibility;
    
})();
