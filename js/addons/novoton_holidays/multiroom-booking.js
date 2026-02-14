/**
 * Novoton Multi-Room Booking JavaScript
 * Handles room selection, price calculation, and form submission
 */

(function() {
    'use strict';
    
    // Storage for room selections
    var roomSelections = {};
    var numRooms = 0;
    var roomsData = [];
    
    // Initialize when DOM is ready
    function init() {
        // Get configuration from data attributes on the container
        var container = document.getElementById('multi-room-selection');
        if (!container) {
            if (window.NovotonConfig && window.NovotonConfig.debug) {
                console.log('[Novoton] Multi-room container not found');
            }
            return;
        }

        numRooms = parseInt(container.dataset.numRooms || '0', 10);
        try {
            roomsData = JSON.parse(container.dataset.roomsData || '[]');
        } catch(e) {
            roomsData = [];
        }

        if (window.NovotonConfig && window.NovotonConfig.debug) {
            console.log('[Novoton] Multi-room JS initialized. Rooms:', numRooms);
        }
        
        // Attach event listeners to all radio buttons
        var radios = container.querySelectorAll('input[type="radio"]');
        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                var roomNum = parseInt(this.name.replace('room_', '').replace('_selection', ''), 10);
                handleRoomSelection(roomNum, this);
            });
        });
    }
    
    // Handle room selection
    function handleRoomSelection(roomNum, input) {
        var price = parseFloat(input.dataset.price) || 0;
        var roomId = input.dataset.roomId;
        var boardId = input.dataset.boardId;
        var roomName = input.dataset.roomName;
        var boardName = input.dataset.boardName;
        var packageName = input.dataset.packageName || '';
        var isOnRequest = input.dataset.isOnRequest === '1';
        
        // Store selection
        roomSelections[roomNum] = {
            room_id: roomId,
            board_id: boardId,
            price: price,
            room_name: roomName,
            board_name: boardName,
            package_name: packageName,
            is_on_request: isOnRequest
        };
        
        // Update room price display
        var priceEl = document.getElementById('room-' + roomNum + '-price');
        if (priceEl) {
            priceEl.textContent = price.toLocaleString() + ' €';
        }
        
        // Highlight selected option
        var container = input.closest('.room-options');
        if (container) {
            container.querySelectorAll('.room-option').forEach(function(opt) {
                var radio = opt.querySelector('input');
                if (radio && radio.checked) {
                    opt.style.borderColor = '#003580';
                    opt.style.background = '#e8f4fc';
                } else {
                    opt.style.borderColor = '#e0e0e0';
                    opt.style.background = '#fff';
                }
            });
        }
        
        updateTotalPrice();
    }
    
    // Update total price and form
    function updateTotalPrice() {
        var total = 0;
        var selectedCount = 0;
        var hasOnRequest = false;
        var summaryParts = [];
        var firstRoomId = '';
        var firstBoardId = '';
        
        for (var i = 1; i <= numRooms; i++) {
            if (roomSelections[i]) {
                total += roomSelections[i].price;
                selectedCount++;
                if (roomSelections[i].is_on_request) {
                    hasOnRequest = true;
                }
                summaryParts.push('R' + i + ': ' + roomSelections[i].room_name);
                
                // Store first room's room_id and board_id for form
                if (!firstRoomId) {
                    firstRoomId = roomSelections[i].room_id;
                    firstBoardId = roomSelections[i].board_id;
                }
            }
        }
        
        // Update total display
        var totalEl = document.getElementById('total-combined-price');
        if (totalEl) {
            totalEl.textContent = total.toLocaleString() + ' €';
        }
        
        // Update summary
        var summaryEl = document.getElementById('rooms-selected-summary');
        if (summaryEl) {
            var t = window.NovotonTranslations || {};
            if (selectedCount === numRooms) {
                summaryEl.textContent = summaryParts.join(' | ');
                if (hasOnRequest) {
                    summaryEl.textContent += ' ' + (t.includesOnRequest || '(includes on-request)');
                }
            } else {
                var roomsLabel = numRooms === 1 ? (t.room || 'room') : (t.rooms || 'rooms');
                summaryEl.textContent = selectedCount + ' ' + (t.of || 'of') + ' ' + numRooms + ' ' + roomsLabel + ' ' + (t.selected || 'selected');
            }
        }
        
        // Enable/disable book button with improved styling
        var bookBtn = document.getElementById('btn-book-multi-room');
        if (bookBtn) {
            if (selectedCount === numRooms) {
                bookBtn.disabled = false;
                bookBtn.style.opacity = '1';
                bookBtn.style.cursor = 'pointer';
                bookBtn.style.transform = 'scale(1)';
                bookBtn.onmouseover = function() { this.style.transform = 'scale(1.05)'; this.style.boxShadow = '0 6px 20px rgba(40,167,69,0.5)'; };
                bookBtn.onmouseout = function() { this.style.transform = 'scale(1)'; this.style.boxShadow = '0 4px 15px rgba(40,167,69,0.4)'; };
            } else {
                bookBtn.disabled = true;
                bookBtn.style.opacity = '0.5';
                bookBtn.style.cursor = 'not-allowed';
                bookBtn.style.transform = 'scale(1)';
                bookBtn.onmouseover = null;
                bookBtn.onmouseout = null;
            }
        }
        
        // Update hidden fields
        var hiddenTotal = document.getElementById('hidden_total_price');
        if (hiddenTotal) {
            hiddenTotal.value = total;
        }
        
        // Set room_id and board_id from first selection (required by controller)
        var hiddenRoomId = document.getElementById('hidden_room_id');
        if (hiddenRoomId) {
            hiddenRoomId.value = firstRoomId;
        }
        
        var hiddenBoardId = document.getElementById('hidden_board_id');
        if (hiddenBoardId) {
            hiddenBoardId.value = firstBoardId;
        }
        
        // Build rooms_data with selections
        var updatedRoomsData = [];
        var allPackageNames = [];
        for (var i = 0; i < roomsData.length; i++) {
            var roomData = Object.assign({}, roomsData[i]);
            var sel = roomSelections[i + 1];
            if (sel) {
                roomData.room_id = sel.room_id;
                roomData.board_id = sel.board_id;
                roomData.room_name = sel.room_name;
                roomData.board_name = sel.board_name;
                roomData.package_name = sel.package_name;
                roomData.price = sel.price;
                roomData.is_on_request = sel.is_on_request;
                if (sel.package_name && allPackageNames.indexOf(sel.package_name) === -1) {
                    allPackageNames.push(sel.package_name);
                }
            }
            updatedRoomsData.push(roomData);
        }
        
        // Update package_name hidden field with first package (or all unique if different)
        var hiddenPackageName = document.getElementById('hidden_package_name');
        if (hiddenPackageName && allPackageNames.length > 0) {
            hiddenPackageName.value = allPackageNames[0]; // Use first package for API
        }
        
        var hiddenRoomsData = document.getElementById('hidden_rooms_data');
        if (hiddenRoomsData) {
            hiddenRoomsData.value = JSON.stringify(updatedRoomsData);
        }
        
        var hiddenSelections = document.getElementById('hidden_room_selections');
        if (hiddenSelections) {
            hiddenSelections.value = JSON.stringify(roomSelections);
        }
    }
    
    // Submit booking form
    function submitMultiRoomBooking() {
        if (Object.keys(roomSelections).length < numRooms) {
            var t = window.NovotonTranslations || {};
            alert(t.pleaseSelectAllRooms || 'Please select a room type for each room');
            return;
        }
        var form = document.getElementById('multi-room-booking-form');
        if (form) {
            form.submit();
        }
    }
    
    // Expose functions globally for inline handlers (backup)
    window.updateRoomSelection = handleRoomSelection;
    window.updateTotalPrice = updateTotalPrice;
    window.submitMultiRoomBooking = submitMultiRoomBooking;
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Also try on window load as backup
    window.addEventListener('load', function() {
        if (numRooms === 0) {
            init();
        }
    });
})();
