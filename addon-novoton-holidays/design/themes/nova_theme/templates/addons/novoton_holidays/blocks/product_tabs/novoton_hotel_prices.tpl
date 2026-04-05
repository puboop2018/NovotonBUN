{* block-description:Hotel Prices **}
{*
 * Novoton Hotel Prices Tab Template
 *
 * DIAGNOSTIC MODE: modifier calls replaced with raw values.
 * This avoids |novoton_format_board which may fail in Smarty 5
 * compilation if the modifier isn't found in plugin directories.
 *}

{* Load large data from PHP registry — NOT from $product (see Data.php:265 fix) *}
{$_nvt_data = $product.nvt.hotel_id|nvt_hotel_tab_data}
{$prices = $_nvt_data.prices}
{$rooms_data = $_nvt_data.rooms_data}
{$board_data = $_nvt_data.board_data}
{$packages_data = $_nvt_data.packages_data}
{$hotel_full_data = $_nvt_data.hotel_full_data}
{$active_package = $_nvt_data.active_package}
{$season_dates = $_nvt_data.season_dates}
{$early_booking = $_nvt_data.early_booking}
{$room_age_bands = $_nvt_data.room_age_bands}
{$last_update = $_nvt_data.last_update}
{$hotel_id = $product.nvt.hotel_id}

{style src="css/addons/novoton_holidays/styles.css"}

<div class="novoton-hotel-prices" id="novoton_prices_tab">

    {* Header: Last update + Package info *}
    <div class="prices-header">
        {if $last_update}
        <div class="prices-last-update">
            <small>
                <i class="ty-icon-time"></i> 
                {__("novoton_holidays.prices_last_updated")}: {$last_update|date_format:"`$settings.Appearance.date_format` `$settings.Appearance.time_format`"}
            </small>
        </div>
        {/if}
        
        {* Package name with season period *}
        {if $active_package}
        <div class="package-info">
            <strong>{$active_package}</strong>
            {if $season_dates}
                {* Find first and last season dates *}
                {$first_date = ''}
                {$last_date = ''}
                {foreach from=$season_dates key=num item=season}
                    {if $num == 1 || empty($first_date)}
                        {$first_date = $season.date_from}
                    {/if}
                    {$last_date = $season.date_to}
                {/foreach}
                {if $first_date && $last_date}
                    <span class="season-period">
                        {$first_date|date_format:$settings.Appearance.date_format} - {$last_date|date_format:$settings.Appearance.date_format}
                    </span>
                {/if}
            {/if}
        </div>
        {/if}
    </div>
    
    {* Early Booking Banner *}
    {$current_date = $smarty.now|date_format:"%Y-%m-%d"}
    {$active_eb = null}
    {if $early_booking}
        {foreach from=$early_booking item=eb}
            {if $current_date >= $eb.booking_from && $current_date <= $eb.booking_to}
                {$active_eb = $eb}
                {break}
            {/if}
        {/foreach}
    {/if}
    
    {if $active_eb}
    <div class="early-booking-banner">
        <div class="eb-badge">
            <span class="eb-discount">-{$active_eb.reduction|floatval}%</span>
            <span class="eb-label">{__("novoton_holidays.early_booking_discounts")}</span>
        </div>
        <div class="eb-info">
            <p>{__("novoton_holidays.book_by")}: <strong>{$active_eb.booking_to|date_format:$settings.Appearance.date_format}</strong></p>
            <p>{__("novoton_holidays.travel_period")}: {$active_eb.stay_from|date_format:$settings.Appearance.date_format} - {$active_eb.stay_to|date_format:$settings.Appearance.date_format}</p>
        </div>
    </div>
    {/if}
    
    {if $prices && $prices|count > 0}
        
        {* Get age groups from hotel_full_data for child ages *}
        {$child_age_1_max = '1.99'}
        {$child_age_2_min = '2'}
        {$child_age_2_max = '11.99'}
        {$child_age_3_min = '12'}
        {$child_age_3_max = '17.99'}
        {if $hotel_full_data && $hotel_full_data.age_groups}
            {foreach from=$hotel_full_data.age_groups item=ag}
                {if $ag.IdAge == 2 || strpos($ag.fAge|default:'', 'CHD') !== false}
                    {$child_age_1_max = $ag.ToYear|default:'1.99'}
                {elseif $ag.IdAge == 3}
                    {$child_age_2_min = $ag.FromYear|default:'2'}
                    {$child_age_2_max = $ag.ToYear|default:'11.99'}
                {elseif $ag.IdAge == 4}
                    {$child_age_3_min = $ag.FromYear|default:'12'}
                    {$child_age_3_max = $ag.ToYear|default:'17.99'}
                {/if}
            {/foreach}
        {/if}
        
        {* Build season headers - each period on new line *}
        {$season_headers = []}
        {if $season_dates}
            {$season_headers.off = []}
            {$season_headers.mid = []}
            {$season_headers.high = []}
            {$season_headers.peak = []}
            
            {foreach from=$season_dates key=num item=s}
                {if $num == 1 || $num == 7}
                    {$season_headers.off[] = $s}
                {elseif $num == 2 || $num == 6}
                    {$season_headers.mid[] = $s}
                {elseif $num == 3 || $num == 5}
                    {$season_headers.high[] = $s}
                {elseif $num == 4}
                    {$season_headers.peak[] = $s}
                {/if}
            {/foreach}
        {/if}
        
        {* Get commission for price calculations *}
        {$commission = 8}
        {if $addon_settings && $addon_settings.commission}
            {$commission = $addon_settings.commission|floatval}
        {/if}
        
        {* Group prices by room_id + board_id *}
        {$rooms_grouped = []}
        {foreach from=$prices item=price}
            {$room_key = "`$price.room_id`_`$price.board_id`"}
            {if !isset($rooms_grouped[$room_key])}
                {$rooms_grouped[$room_key] = [
                    'room_id' => $price.room_id,
                    'room_type' => $price.room_type,
                    'star_rating' => $price.star_rating,
                    'board_id' => $price.board_id,
                    'prices' => []
                ]}
            {/if}
            {$rooms_grouped[$room_key]['prices'][] = $price}
        {/foreach}
        
        {* Get room capacities from rooms_data *}
        {$room_capacities = []}
        {if $rooms_data}
            {foreach from=$rooms_data key=rid item=room}
                {$room_capacities[$rid] = $room}
            {/foreach}
        {/if}
        
        {* Sort: DBL first, then others *}
        {$dbl_rooms = []}
        {$other_rooms = []}
        {foreach from=$rooms_grouped key=k item=room}
            {if strpos($room.room_id|default:'', 'DBL') !== false}
                {$dbl_rooms[$k] = $room}
            {else}
                {$other_rooms[$k] = $room}
            {/if}
        {/foreach}
        {$sorted_rooms = array_merge($dbl_rooms, $other_rooms)}
        
        {* Room Sections with space between cards *}
        <div class="rooms-accordion">
            {foreach from=$sorted_rooms key=room_key item=room_data name=rooms_loop}
                {$room_id = $room_data.room_id}
                {$room_display = $room_id|replace:'%2b':'+'|replace:'%2B':'+'}
                {$capacity = $room_capacities[$room_id]|default:[]}
                {$maxADT = $capacity.maxADT|default:2}
                {$maxCHD = $capacity.maxCHD|default:2}
                {$minPAX = $capacity.minPAX|default:1}
                {$board_display = $room_data.board_id}
                {$is_sgl = strpos($room_id|default:'', 'SGL') !== false}
                
                <div class="room-section-card expanded" data-room="{$room_id}">
                    <div class="room-header-card">
                        <div class="room-title">
                            <span class="room-name">{$room_display}</span>
                            <span class="room-board">{$board_display}</span>
                        </div>
                        <div class="room-capacity">
                            <span>{__("novoton_holidays.capacity")}: {$minPAX}-{$maxADT} {__("novoton_holidays.adults_short")}</span>
                            {if $maxCHD > 0}
                            <span>| {__("novoton_holidays.max")} {$maxCHD} {__("novoton_holidays.children_short")}</span>
                            {/if}
                        </div>
                    </div>
                    
                    <div class="room-content">
                        {* Get base price for adults *}
                        {$adult_price = null}
                        {foreach from=$room_data.prices item=p}
                            {if ($p.age_type == 'ADULT' || $p.age_type == 'ADULT ') && $p.acc_type == 'REGULAR'}
                                {$adult_price = $p}
                            {/if}
                        {/foreach}

                        {* Get room age bands — dynamically detected from price data *}
                        {$r_bands = $room_age_bands[$room_id]|default:[]}
                        {$r_child_bands = $r_bands.child_bands|default:[]}

                        {* Build child price map keyed by band label (e.g., "0-1.99" => price_entry) *}
                        {$child_price_map = []}
                        {foreach from=$room_data.prices item=p}
                            {if strpos($p.age_type|default:'', 'CHD') !== false || strpos($p.age_type|default:'', 'CHILD') !== false}
                                {foreach from=$r_child_bands item=cb}
                                    {* Match price entry to band by checking if the age range appears in the age_type string *}
                                    {$dash_label = "{$cb.from}-{$cb.to}"}
                                    {$comma_label = $cb.from|replace:'.':','|cat:'-'|cat:$cb.to|replace:'.':','}
                                    {if strpos($p.age_type|default:'', $dash_label) !== false || strpos($p.age_type|default:'', $comma_label) !== false}
                                        {if !isset($child_price_map[$cb.key])}
                                            {$child_price_map[$cb.key] = $p}
                                        {/if}
                                    {/if}
                                {/foreach}
                            {/if}
                        {/foreach}
                        
                        <table class="prices-table-new">
                            <thead>
                                <tr>
                                    <th class="col-occupancy">{__("novoton_holidays.occupancy")}</th>
                                    <th class="col-nights">{__("novoton_holidays.nights")}</th>
                                    <th class="col-season">
                                        <div class="season-name">{__("novoton_holidays.off_season")}</div>
                                        {if $season_headers.off}
                                            <div class="season-dates-list">
                                            {foreach from=$season_headers.off item=s}
                                                <div class="season-date-line">{$s.date_from|date_format:$settings.Appearance.date_format} - {$s.date_to|date_format:$settings.Appearance.date_format}</div>
                                            {/foreach}
                                            </div>
                                        {/if}
                                    </th>
                                    <th class="col-season">
                                        <div class="season-name">{__("novoton_holidays.mid_season")}</div>
                                        {if $season_headers.mid}
                                            <div class="season-dates-list">
                                            {foreach from=$season_headers.mid item=s}
                                                <div class="season-date-line">{$s.date_from|date_format:$settings.Appearance.date_format} - {$s.date_to|date_format:$settings.Appearance.date_format}</div>
                                            {/foreach}
                                            </div>
                                        {/if}
                                    </th>
                                    <th class="col-season">
                                        <div class="season-name">{__("novoton_holidays.high_season")}</div>
                                        {if $season_headers.high}
                                            <div class="season-dates-list">
                                            {foreach from=$season_headers.high item=s}
                                                <div class="season-date-line">{$s.date_from|date_format:$settings.Appearance.date_format} - {$s.date_to|date_format:$settings.Appearance.date_format}</div>
                                            {/foreach}
                                            </div>
                                        {/if}
                                    </th>
                                    <th class="col-season">
                                        <div class="season-name">{__("novoton_holidays.peak_season")}</div>
                                        {if $season_headers.peak}
                                            <div class="season-dates-list">
                                            {foreach from=$season_headers.peak item=s}
                                                <div class="season-date-line">{$s.date_from|date_format:$settings.Appearance.date_format} - {$s.date_to|date_format:$settings.Appearance.date_format}</div>
                                            {/foreach}
                                            </div>
                                        {/if}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {* Determine occupancy rows based on room type and available age bands *}
                                {$total_capacity = $capacity.RB|default:2 + $capacity.EB|default:1}
                                {if $is_sgl}
                                    {* Single room: 1 Adult only *}
                                    {$occ_list = []}
                                    {$occ_list[] = ['label' => "1 {__('novoton_holidays.adult')}", 'adults' => 1, 'children' => 0, 'child_band_key' => '']}
                                {else}
                                    {* Build occupancy rows dynamically from detected child age bands *}
                                    {$occ_list = []}
                                    {* Always show 2 Adults *}
                                    {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')}", 'adults' => 2, 'children' => 0, 'child_band_key' => '']}

                                    {* Show 2A+1C for each detected child age band in this room *}
                                    {if $r_child_bands|count > 0 && $total_capacity >= 3}
                                        {foreach from=$r_child_bands item=cb}
                                            {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')} + 1 {__('novoton_holidays.child')} ({$cb.from}-{$cb.to})", 'adults' => 2, 'children' => 1, 'child_band_key' => $cb.key]}
                                        {/foreach}
                                    {/if}

                                    {* Show 2A+2C only if room has child pricing and capacity >= 4 *}
                                    {if $r_child_bands|count > 0 && $total_capacity >= 4}
                                        {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')} + 2 {__('novoton_holidays.children_short')}", 'adults' => 2, 'children' => 2, 'child_band_key' => 'mixed']}
                                    {/if}

                                    {* Fallback: if no age bands detected, show default rows *}
                                    {if $r_child_bands|count == 0 && $total_capacity >= 3}
                                        {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')} + 1 {__('novoton_holidays.child')} (0-{$child_age_1_max})", 'adults' => 2, 'children' => 1, 'child_band_key' => 'fallback_infant']}
                                        {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')} + 1 {__('novoton_holidays.child')} ({$child_age_2_min}-{$child_age_2_max})", 'adults' => 2, 'children' => 1, 'child_band_key' => 'fallback_child']}
                                        {if $total_capacity >= 4}
                                            {$occ_list[] = ['label' => "2 {__('novoton_holidays.adults')} + 2 {__('novoton_holidays.children_short')}", 'adults' => 2, 'children' => 2, 'child_band_key' => 'mixed']}
                                        {/if}
                                    {/if}
                                {/if}
                                
                                {foreach from=$occ_list item=occ_config name=occ_loop}
                                    {* Row for 5 nights *}
                                    <tr class="occupancy-row">
                                        <td class="col-occupancy" rowspan="3">
                                            <strong>{$occ_config.label}</strong>
                                        </td>
                                        <td class="col-nights">5</td>
                                        {* Calculate prices for each season *}
                                        {foreach from=['off', 'mid', 'high', 'peak'] item=season_type}
                                            <td class="col-price">
                                                {* Get season price key *}
                                                {if $season_type == 'off'}
                                                    {$price_key = 'price_1'}
                                                    {$price_key_alt = 'price_7'}
                                                {elseif $season_type == 'mid'}
                                                    {$price_key = 'price_2'}
                                                    {$price_key_alt = 'price_6'}
                                                {elseif $season_type == 'high'}
                                                    {$price_key = 'price_3'}
                                                    {$price_key_alt = 'price_5'}
                                                {else}
                                                    {$price_key = 'price_4'}
                                                    {$price_key_alt = 'price_4'}
                                                {/if}
                                                
                                                {* Calculate total price *}
                                                {$base = 0}
                                                {if $adult_price}
                                                    {$adult_7n = $adult_price.$price_key|default:0}
                                                    {if $adult_7n == 0}
                                                        {$adult_7n = $adult_price.$price_key_alt|default:0}
                                                    {/if}
                                                    {$adult_per_night = $adult_7n / 7}
                                                    {$base = $adult_per_night * $occ_config.adults * 5}
                                                {/if}
                                                
                                                {* Add children — use dynamic child_band_key to look up price *}
                                                {if $occ_config.children > 0}
                                                    {if $occ_config.child_band_key != 'mixed' && isset($child_price_map[$occ_config.child_band_key])}
                                                        {$cp = $child_price_map[$occ_config.child_band_key]}
                                                        {$child_7n = $cp.$price_key|default:0}
                                                        {if $child_7n == 0}
                                                            {$child_7n = $cp.$price_key_alt|default:0}
                                                        {/if}
                                                        {$child_per_night = $child_7n / 7}
                                                        {$base = $base + ($child_per_night * $occ_config.children * 5)}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count >= 2}
                                                        {* 2 children - use first two detected age bands *}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1_7n = $cp1.$price_key|default:0}
                                                            {if $c1_7n == 0}
                                                                {$c1_7n = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c1_7n / 7) * 5)}
                                                        {/if}
                                                        {$cb2_key = $r_child_bands[1].key}
                                                        {if isset($child_price_map[$cb2_key])}
                                                            {$cp2 = $child_price_map[$cb2_key]}
                                                            {$c2_7n = $cp2.$price_key|default:0}
                                                            {if $c2_7n == 0}
                                                                {$c2_7n = $cp2.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c2_7n / 7) * 5)}
                                                        {/if}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count == 1}
                                                        {* 2 children but only 1 band - use same band for both *}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1_7n = $cp1.$price_key|default:0}
                                                            {if $c1_7n == 0}
                                                                {$c1_7n = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c1_7n / 7) * 5 * 2)}
                                                        {/if}
                                                    {/if}
                                                {/if}

                                                {* Apply commission *}
                                                {$total = $base * (1 + $commission / 100)}

                                                {if $total > 0}
                                                    <span class="price">{fn_novoton_holidays_format_price($total, 1, $smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                                                {else}
                                                    <span class="na">-</span>
                                                {/if}
                                            </td>
                                        {/foreach}
                                    </tr>

                                    {* Row for 7 nights *}
                                    <tr class="occupancy-row nights-row">
                                        <td class="col-nights highlight-nights">7</td>
                                        {foreach from=['off', 'mid', 'high', 'peak'] item=season_type}
                                            <td class="col-price highlight-price">
                                                {if $season_type == 'off'}
                                                    {$price_key = 'price_1'}
                                                    {$price_key_alt = 'price_7'}
                                                {elseif $season_type == 'mid'}
                                                    {$price_key = 'price_2'}
                                                    {$price_key_alt = 'price_6'}
                                                {elseif $season_type == 'high'}
                                                    {$price_key = 'price_3'}
                                                    {$price_key_alt = 'price_5'}
                                                {else}
                                                    {$price_key = 'price_4'}
                                                    {$price_key_alt = 'price_4'}
                                                {/if}

                                                {$base = 0}
                                                {if $adult_price}
                                                    {$adult_total = $adult_price.$price_key|default:0}
                                                    {if $adult_total == 0}
                                                        {$adult_total = $adult_price.$price_key_alt|default:0}
                                                    {/if}
                                                    {$base = $adult_total * $occ_config.adults}
                                                {/if}

                                                {if $occ_config.children > 0}
                                                    {if $occ_config.child_band_key != 'mixed' && isset($child_price_map[$occ_config.child_band_key])}
                                                        {$cp = $child_price_map[$occ_config.child_band_key]}
                                                        {$child_total = $cp.$price_key|default:0}
                                                        {if $child_total == 0}
                                                            {$child_total = $cp.$price_key_alt|default:0}
                                                        {/if}
                                                        {$base = $base + ($child_total * $occ_config.children)}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count >= 2}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1 = $cp1.$price_key|default:0}
                                                            {if $c1 == 0}
                                                                {$c1 = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + $c1}
                                                        {/if}
                                                        {$cb2_key = $r_child_bands[1].key}
                                                        {if isset($child_price_map[$cb2_key])}
                                                            {$cp2 = $child_price_map[$cb2_key]}
                                                            {$c2 = $cp2.$price_key|default:0}
                                                            {if $c2 == 0}
                                                                {$c2 = $cp2.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + $c2}
                                                        {/if}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count == 1}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1 = $cp1.$price_key|default:0}
                                                            {if $c1 == 0}
                                                                {$c1 = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + ($c1 * 2)}
                                                        {/if}
                                                    {/if}
                                                {/if}

                                                {$total = $base * (1 + $commission / 100)}

                                                {if $total > 0}
                                                    <span class="price price-highlight">{fn_novoton_holidays_format_price($total, 1, $smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                                                {else}
                                                    <span class="na">-</span>
                                                {/if}
                                            </td>
                                        {/foreach}
                                    </tr>

                                    {* Row for 10 nights *}
                                    <tr class="occupancy-row nights-row last-nights-row">
                                        <td class="col-nights">10</td>
                                        {foreach from=['off', 'mid', 'high', 'peak'] item=season_type}
                                            <td class="col-price">
                                                {if $season_type == 'off'}
                                                    {$price_key = 'price_1'}
                                                    {$price_key_alt = 'price_7'}
                                                {elseif $season_type == 'mid'}
                                                    {$price_key = 'price_2'}
                                                    {$price_key_alt = 'price_6'}
                                                {elseif $season_type == 'high'}
                                                    {$price_key = 'price_3'}
                                                    {$price_key_alt = 'price_5'}
                                                {else}
                                                    {$price_key = 'price_4'}
                                                    {$price_key_alt = 'price_4'}
                                                {/if}

                                                {$base = 0}
                                                {if $adult_price}
                                                    {$adult_7n = $adult_price.$price_key|default:0}
                                                    {if $adult_7n == 0}
                                                        {$adult_7n = $adult_price.$price_key_alt|default:0}
                                                    {/if}
                                                    {$adult_per_night = $adult_7n / 7}
                                                    {$base = $adult_per_night * $occ_config.adults * 10}
                                                {/if}

                                                {if $occ_config.children > 0}
                                                    {if $occ_config.child_band_key != 'mixed' && isset($child_price_map[$occ_config.child_band_key])}
                                                        {$cp = $child_price_map[$occ_config.child_band_key]}
                                                        {$child_7n = $cp.$price_key|default:0}
                                                        {if $child_7n == 0}
                                                            {$child_7n = $cp.$price_key_alt|default:0}
                                                        {/if}
                                                        {$child_per_night = $child_7n / 7}
                                                        {$base = $base + ($child_per_night * $occ_config.children * 10)}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count >= 2}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1_7n = $cp1.$price_key|default:0}
                                                            {if $c1_7n == 0}
                                                                {$c1_7n = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c1_7n / 7) * 10)}
                                                        {/if}
                                                        {$cb2_key = $r_child_bands[1].key}
                                                        {if isset($child_price_map[$cb2_key])}
                                                            {$cp2 = $child_price_map[$cb2_key]}
                                                            {$c2_7n = $cp2.$price_key|default:0}
                                                            {if $c2_7n == 0}
                                                                {$c2_7n = $cp2.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c2_7n / 7) * 10)}
                                                        {/if}
                                                    {elseif $occ_config.child_band_key == 'mixed' && $r_child_bands|count == 1}
                                                        {$cb1_key = $r_child_bands[0].key}
                                                        {if isset($child_price_map[$cb1_key])}
                                                            {$cp1 = $child_price_map[$cb1_key]}
                                                            {$c1_7n = $cp1.$price_key|default:0}
                                                            {if $c1_7n == 0}
                                                                {$c1_7n = $cp1.$price_key_alt|default:0}
                                                            {/if}
                                                            {$base = $base + (($c1_7n / 7) * 10 * 2)}
                                                        {/if}
                                                    {/if}
                                                {/if}
                                                
                                                {$total = $base * (1 + $commission / 100)}
                                                
                                                {if $total > 0}
                                                    <span class="price">{fn_novoton_holidays_format_price($total, 1, $smarty.const.CART_PRIMARY_CURRENCY) nofilter}</span>
                                                {else}
                                                    <span class="na">-</span>
                                                {/if}
                                            </td>
                                        {/foreach}
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                </div>
            {/foreach}
        </div>
        
        {* Early Booking & Extra Discounts - Collapsible, expanded by default *}
        {if $early_booking && $early_booking|count > 0}
        <div class="collapsible-section expanded" id="early-booking-section">
            <div class="section-header" onclick="this.parentElement.classList.toggle('expanded')">
                <h4>{__("novoton_holidays.early_booking_extra_discounts")}</h4>
                <span class="expand-icon">&#9662;</span>
            </div>
            <div class="section-content">
                <div class="early-booking-list">
                    {foreach from=$early_booking item=eb}
                    <div class="eb-item">
                        <span class="eb-discount-badge">-{$eb.reduction|floatval}%</span>
                        <span class="eb-details">
                            {__("novoton_holidays.if_booked_by")} <strong>{$eb.booking_to|date_format:$settings.Appearance.date_format}</strong>
                            {__("novoton_holidays.for_stays")} {$eb.stay_from|date_format:$settings.Appearance.date_format} - {$eb.stay_to|date_format:$settings.Appearance.date_format}
                        </span>
                    </div>
                    {/foreach}
                </div>
                
                {* Extras (nights free promotions) from hotel_full_data *}
                {if $hotel_full_data && $hotel_full_data.price_info.extras}
                <div class="extras-promotions">
                    <h5>{__("novoton_holidays.free_nights_promotions")}</h5>
                    <table class="extras-table">
                        <thead>
                            <tr>
                                <th>{__("novoton_holidays.nights_booked")}</th>
                                <th>{__("novoton_holidays.nights_to_pay")}</th>
                                <th>{__("novoton_holidays.valid_period")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$hotel_full_data.price_info.extras item=extra}
                            <tr>
                                <td>{$extra.Nights|default:$extra.nights|default:'-'}</td>
                                <td>{$extra.ToBePaid|default:$extra.to_pay|default:'-'}</td>
                                <td>
                                    {if $extra.FromDate && $extra.ToDate}
                                        {$extra.FromDate|date_format:$settings.Appearance.date_format} - {$extra.ToDate|date_format:$settings.Appearance.date_format}
                                    {else}
                                        -
                                    {/if}
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                {/if}
            </div>
        </div>
        {/if}
        
        {* Payment & Cancellation Terms - Collapsible, collapsed by default *}
        {if $hotel_full_data && ($hotel_full_data.price_info.payment_terms || $hotel_full_data.price_info.cancellation)}
        <div class="collapsible-section" id="payment-terms-section">
            <div class="section-header" onclick="this.parentElement.classList.toggle('expanded')">
                <h4>{__("novoton_holidays.payment_cancellation_terms")}</h4>
                <span class="expand-icon">&#9662;</span>
            </div>
            <div class="section-content">
                {if $hotel_full_data.price_info.payment_terms}
                <div class="payment-terms">
                    <h5>{__("novoton_holidays.terms_of_payment")}</h5>
                    <p>{__("novoton_holidays.for_all_room_types")}:</p>
                    <ul>
                        {foreach from=$hotel_full_data.price_info.payment_terms item=term}
                        <li>
                            {if $term.Date || $term.date}
                                {__("novoton_holidays.till")} {$term.Date|default:$term.date|date_format:$settings.Appearance.date_format} - {$term.Percent|default:$term.percent|default:0}%
                            {else}
                                {$term.Description|default:$term.description|default:''}
                            {/if}
                        </li>
                        {/foreach}
                    </ul>
                </div>
                {/if}
                
                {if $hotel_full_data.price_info.cancellation}
                <div class="cancellation-terms">
                    <h5>{__("novoton_holidays.cancellation_terms")}</h5>
                    <ul>
                        {foreach from=$hotel_full_data.price_info.cancellation item=cancel}
                        <li>
                            {if $cancel.Days || $cancel.days}
                                {if $cancel.Days == 0 || $cancel.days == 0}
                                    {__("novoton_holidays.no_show")} - {$cancel.Penalty|default:$cancel.penalty|default:'100'}% {__("novoton_holidays.penalty")}
                                {else}
                                    {__("novoton_holidays.up_to")} {$cancel.Days|default:$cancel.days} {__("novoton_holidays.days_before_arrival")} - 
                                    {if $cancel.Penalty == 0 || $cancel.penalty == 0}
                                        {__("novoton_holidays.no_penalty")}
                                    {else}
                                        {$cancel.Penalty|default:$cancel.penalty} {__("novoton_holidays.overnights_penalty")}
                                    {/if}
                                {/if}
                            {else}
                                {$cancel.Description|default:$cancel.description|default:''}
                            {/if}
                        </li>
                        {/foreach}
                    </ul>
                </div>
                {/if}
            </div>
        </div>
        {/if}
        
        <div class="price-notes">
            <small>{__("novoton_holidays.prices_include_commission")}</small>
        </div>
        
    {else}
        <div class="no-prices-message">
            <p>{__("novoton_holidays.no_prices_available")}</p>
            <p><small>{__("novoton_holidays.prices_coming_soon")}</small></p>
        </div>
    {/if}
    
</div>

