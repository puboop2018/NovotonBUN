/**
 * Novoton Booking Engine JavaScript
 * Path: js/addons/novoton_holidays/booking_engine.js
 */

(function(_, $) {
    
    $.ceEvent('on', 'ce.commoninit', function(context) {
        
        var $context = $(context);
        
        // Initialize booking engine
        var $bookingEngine = $context.find('.novoton-booking-engine');
        
        if ($bookingEngine.length) {
            initBookingEngine($bookingEngine);
        }
        
        // Initialize guest picker
        var $guestPicker = $context.find('.novoton-guest-picker');
        
        if ($guestPicker.length) {
            initGuestPicker($guestPicker);
        }
        
    });
    
    /**
     * Initialize booking engine form
     */
    function initBookingEngine($engine) {
        
        var $form = $engine.find('form');
        var $childrenSelect = $form.find('#children');
        var $childrenAgesContainer = $form.find('#children_ages_container');
        var $childrenAgesFields = $form.find('#children_ages_fields');
        var hotelId = $engine.data('hotel-id');
        
        // Load destinations if not on product page (no hotel_id)
        if (!hotelId) {
            loadDestinations($form);
        }
        
        // Handle children count change
        $childrenSelect.on('change', function() {
            var childrenCount = parseInt($(this).val());
            
            $childrenAgesFields.empty();
            
            if (childrenCount > 0) {
                $childrenAgesContainer.show();
                
                for (var i = 1; i <= childrenCount; i++) {
                    var $ageSelect = $('<select></select>')
                        .attr('name', 'child_age_' + i)
                        .attr('id', 'child_age_' + i)
                        .addClass('input-mini cm-hint')
                        .attr('required', 'required');
                    
                    // Add age options 0-17
                    for (var age = 0; age <= 17; age++) {
                        $ageSelect.append($('<option></option>').val(age).text(age + ' years'));
                    }
                    
                    var $label = $('<label></label>')
                        .addClass('control-label-inline')
                        .attr('for', 'child_age_' + i)
                        .text('Child ' + i + ':');
                    
                    var $wrapper = $('<div></div>')
                        .addClass('child-age-field')
                        .css({
                            'display': 'inline-block',
                            'margin-right': '15px',
                            'margin-bottom': '10px'
                        })
                        .append($label)
                        .append(' ')
                        .append($ageSelect);
                    
                    $childrenAgesFields.append($wrapper);
                }
            } else {
                $childrenAgesContainer.hide();
            }
        });
        
        // Validate dates on form submit
        $form.on('submit', function(e) {
            var checkIn = $form.find('[name="check_in"]').val();
            var nights = parseInt($form.find('#nights').val());
            
            if (!checkIn || !nights) {
                return true;
            }
            
            var checkInDate = new Date(checkIn);
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (checkInDate < today) {
                e.preventDefault();
                alert('Check-in date cannot be in the past');
                return false;
            }
        });
    }
    
    /**
     * Load destinations via AJAX
     */
    function loadDestinations($form) {
        var $destinationSelect = $form.find('#destination');
        
        if (!$destinationSelect.length) {
            return;
        }
        
        $.ceAjax('request', fn_url('novoton_booking.get_destinations'), {
            method: 'get',
            result_ids: '',
            callback: function(data) {
                if (data.destinations && data.destinations.length > 0) {
                    $.each(data.destinations, function(index, destination) {
                        $destinationSelect.append($('<option></option>').val(destination).text(destination));
                    });
                }
            }
        });
    }
    
    /**
     * Initialize Booking.com-style guest picker
     */
    function initGuestPicker($picker) {
        
        var $button = $picker.find('.guest-picker-button');
        var $dropdown = $picker.find('.guest-picker-dropdown');
        var $adultsInput = $dropdown.find('.adults-count');
        var $childrenInput = $dropdown.find('.children-count');
        var $childrenAgesContainer = $dropdown.find('.children-ages');
        
        // Toggle dropdown
        $button.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dropdown.toggle();
        });
        
        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$picker.is(e.target) && $picker.has(e.target).length === 0) {
                $dropdown.hide();
            }
        });
        
        // Adults increment/decrement
        $dropdown.find('.adults-minus').on('click', function() {
            var current = parseInt($adultsInput.val());
            if (current > 1) {
                $adultsInput.val(current - 1);
                updateGuestSummary();
            }
        });
        
        $dropdown.find('.adults-plus').on('click', function() {
            var current = parseInt($adultsInput.val());
            if (current < 10) {
                $adultsInput.val(current + 1);
                updateGuestSummary();
            }
        });
        
        // Children increment/decrement
        $dropdown.find('.children-minus').on('click', function() {
            var current = parseInt($childrenInput.val());
            if (current > 0) {
                $childrenInput.val(current - 1);
                updateChildrenAges();
                updateGuestSummary();
            }
        });
        
        $dropdown.find('.children-plus').on('click', function() {
            var current = parseInt($childrenInput.val());
            if (current < 6) {
                $childrenInput.val(current + 1);
                updateChildrenAges();
                updateGuestSummary();
            }
        });
        
        // Update children ages
        function updateChildrenAges() {
            var childrenCount = parseInt($childrenInput.val());
            
            $childrenAgesContainer.empty();
            
            if (childrenCount > 0) {
                $childrenAgesContainer.show();
                
                for (var i = 1; i <= childrenCount; i++) {
                    var $ageSelect = $('<select></select>')
                        .attr('name', 'child_age_' + i)
                        .addClass('form-control');
                    
                    for (var age = 0; age <= 17; age++) {
                        $ageSelect.append($('<option></option>').val(age).text(age + ' years old'));
                    }
                    
                    var $wrapper = $('<div></div>')
                        .addClass('child-age-selector')
                        .html('<label>Age of child ' + i + ':</label>')
                        .append($ageSelect);
                    
                    $childrenAgesContainer.append($wrapper);
                }
            } else {
                $childrenAgesContainer.hide();
            }
        }
        
        // Update guest summary
        function updateGuestSummary() {
            var adults = parseInt($adultsInput.val());
            var children = parseInt($childrenInput.val());
            
            var summary = adults + ' adult' + (adults !== 1 ? 's' : '');
            
            if (children > 0) {
                summary += ', ' + children + ' child' + (children !== 1 ? 'ren' : '');
            }
            
            $button.find('.guest-summary').text(summary);
        }
        
        // Initialize
        updateGuestSummary();
    }
    
    /**
     * Format price with currency
     */
    function formatPrice(amount, currency) {
        currency = currency || 'EUR';
        
        var formatted = parseFloat(amount).toFixed(2);
        
        // Get currency settings from page
        var currencyFormat = _.currency || {};
        
        if (currencyFormat.after === 'Y') {
            return formatted + ' ' + currency;
        } else {
            return currency + ' ' + formatted;
        }
    }
    
    /**
     * Calculate nights between dates
     */
    function calculateNights(checkIn, checkOut) {
        var date1 = new Date(checkIn);
        var date2 = new Date(checkOut);
        var diffTime = Math.abs(date2 - date1);
        var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
    }
    
}(Tygh, Tygh.$));