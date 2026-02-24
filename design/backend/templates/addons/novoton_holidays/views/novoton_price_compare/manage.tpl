{** Novoton Price Comparison Tool **}

{capture name="mainbox"}

<div class="novoton-price-compare">

    <div class="info-box">
        <strong>Price Comparison Tool</strong><br>
        This tool compares prices calculated from <code>priceinfo</code> data (using PriceInfoCalculation service)
        with prices from the <code>room_price</code> API.
        <br><br>
        Use this to verify the calculation algorithm accuracy.
    </div>

    <form action="{"novoton_price_compare.compare"|fn_url}" method="get">
        <input type="hidden" name="dispatch" value="novoton_price_compare.compare">

        <div class="form-group">
            <label for="hotel_id">Hotel</label>
            <select name="hotel_id" id="hotel_id" required>
                <option value="">-- Select Hotel --</option>
                {foreach from=$hotels item=hotel}
                    <option value="{$hotel.hotel_id}">{$hotel.hotel_name} ({$hotel.hotel_id})</option>
                {/foreach}
            </select>
        </div>

        <div class="form-group">
            <label for="package_name">Package Name</label>
            <select name="package_name" id="package_name" required>
                <option value="">-- Select Hotel First --</option>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="room_id">Room Type</label>
                <select id="room_id_select" style="display:none;" disabled>
                    <option value="">-- Select Hotel First --</option>
                </select>
                <input type="text" name="room_id" id="room_id_text" placeholder="e.g., DBL, DBL 2+1, SGL">
                <small id="room_id_hint" class="field-hint">Select a hotel to load available room types</small>
            </div>
            <div class="form-group">
                <label for="board_id">Board Type</label>
                <select name="board_id" id="board_id">
                    <option value="">Any</option>
                    <option value="AI">All Inclusive (AI)</option>
                    <option value="FB">Full Board (FB)</option>
                    <option value="HB">Half Board (HB)</option>
                    <option value="BB">Bed & Breakfast (BB)</option>
                    <option value="RO">Room Only (RO)</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="check_in">Check-in Date</label>
                <input type="date" name="check_in" id="check_in" value="{$default_check_in}" required>
            </div>
            <div class="form-group">
                <label for="nights">Nights</label>
                <select name="nights" id="nights">
                    <option value="5">5 nights</option>
                    <option value="7" selected>7 nights</option>
                    <option value="10">10 nights</option>
                    <option value="14">14 nights</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="adults">Adults</label>
                <select name="adults" id="adults">
                    <option value="1">1 Adult</option>
                    <option value="2" selected>2 Adults</option>
                    <option value="3">3 Adults</option>
                    <option value="4">4 Adults</option>
                </select>
            </div>
            <div class="form-group">
                <label for="children_ages">Children Ages (comma-separated)</label>
                <input type="text" name="children_ages" id="children_ages" placeholder="e.g., 1.5,7">
                <small class="field-hint">Leave empty for no children. Example: 1.5,7 for 2 children aged 1.5 and 7 years</small>
            </div>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="debug" value="1"> Show debug information
            </label>
        </div>

        <div class="form-group form-actions">
            <button type="submit" class="btn-compare">Compare Prices</button>
            <button type="button" class="btn-compare btn-compare-verify" id="btn-verify-seasons">
                Verify Season-Price Mapping
            </button>
        </div>
    </form>
</div>

<script>
// Bind events
document.addEventListener('DOMContentLoaded', function() {
    var hotelSelect = document.getElementById('hotel_id');
    if (hotelSelect) {
        hotelSelect.addEventListener('change', function() {
            loadPackages(this.value);
            loadRooms(this.value);
        });
    }
    var verifyBtn = document.getElementById('btn-verify-seasons');
    if (verifyBtn) {
        verifyBtn.addEventListener('click', verifySeasons);
    }
});

function loadPackages(hotelId) {
    var packageSelect = document.getElementById('package_name');
    packageSelect.innerHTML = '<option value="">Loading...</option>';

    if (!hotelId) {
        packageSelect.innerHTML = '<option value="">-- Select Hotel First --</option>';
        return;
    }

    // AJAX call to get packages for this hotel
    fetch('{"novoton_price_compare.get_packages"|fn_url}' + '&hotel_id=' + hotelId)
        .then(response => response.json())
        .then(data => {
            packageSelect.innerHTML = '<option value="">-- Select Package --</option>';
            if (data.packages) {
                data.packages.forEach(function(pkg) {
                    var option = document.createElement('option');
                    option.value = pkg.package_name;
                    option.textContent = pkg.package_name;
                    packageSelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            packageSelect.innerHTML = '<option value="">Error loading packages</option>';
        });
}

function loadRooms(hotelId) {
    var roomSelect = document.getElementById('room_id_select');
    var roomText = document.getElementById('room_id_text');
    var roomHint = document.getElementById('room_id_hint');

    if (!hotelId) {
        roomSelect.style.display = 'none';
        roomSelect.disabled = true;
        roomText.style.display = '';
        roomText.disabled = false;
        roomHint.textContent = 'Select a hotel to load available room types';
        return;
    }

    roomHint.textContent = 'Loading rooms...';

    fetch('{"novoton_price_compare.get_rooms"|fn_url}' + '&hotel_id=' + hotelId)
        .then(response => response.json())
        .then(data => {
            if (data.rooms && data.rooms.length > 0) {
                // Show select, hide text input
                roomSelect.innerHTML = '<option value="">Any (all rooms)</option>';
                data.rooms.forEach(function(room) {
                    var option = document.createElement('option');
                    option.value = room.id_room;
                    option.textContent = room.label;
                    roomSelect.appendChild(option);
                });
                roomSelect.style.display = '';
                roomSelect.disabled = false;
                roomText.style.display = 'none';
                roomText.disabled = true;
                roomText.name = '';
                roomSelect.name = 'room_id';
                roomHint.textContent = data.rooms.length + ' room type(s) from hotelinfo';
            } else {
                // No rooms found - show text input
                roomSelect.style.display = 'none';
                roomSelect.disabled = true;
                roomSelect.name = '';
                roomText.style.display = '';
                roomText.disabled = false;
                roomText.name = 'room_id';
                roomHint.textContent = 'No room data in hotelinfo — type manually';
            }
        })
        .catch(error => {
            roomSelect.style.display = 'none';
            roomSelect.disabled = true;
            roomSelect.name = '';
            roomText.style.display = '';
            roomText.disabled = false;
            roomText.name = 'room_id';
            roomHint.textContent = 'Error loading rooms — type manually';
        });
}

function onRoomSelectChange(sel) {
    // If user picks "custom..." option we could add, but for now just let them use the select
}

function verifySeasons() {
    var hotelId = document.getElementById('hotel_id').value;
    var packageName = document.getElementById('package_name').value;
    // Get room_id from whichever field is active
    var roomSelect = document.getElementById('room_id_select');
    var roomText = document.getElementById('room_id_text');
    var roomId = roomSelect.style.display !== 'none' ? roomSelect.value : roomText.value;
    var boardId = document.getElementById('board_id').value;
    var checkIn = document.getElementById('check_in').value;
    var nights = document.getElementById('nights').value;

    if (!hotelId || !packageName) {
        alert('Please select a hotel and package first.');
        return;
    }

    var url = '{""|fn_url}' + '&dispatch=novoton_price_compare.verify' +
              '&hotel_id=' + encodeURIComponent(hotelId) +
              '&package_name=' + encodeURIComponent(packageName) +
              '&room_id=' + encodeURIComponent(roomId) +
              '&board_id=' + encodeURIComponent(boardId) +
              '&check_in=' + encodeURIComponent(checkIn) +
              '&nights=' + encodeURIComponent(nights);

    window.location.href = url;
}
</script>

{/capture}

{include file="common/mainbox.tpl"
    title="Price Comparison Tool"
    content=$smarty.capture.mainbox
    buttons=""
}
