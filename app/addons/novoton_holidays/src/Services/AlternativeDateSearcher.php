<?php
declare(strict_types=1);
/**
 * Searches nearby dates for availability when the primary search returns nothing.
 *
 * Iterates ±N days around the requested check-in, calling the room_price API
 * for each room × board combination until availability is found.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class AlternativeDateSearcher
{
    /** @var bool */
    private bool $debug;

    /** @var string[] */
    private array $debugLog = [];

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Search alternative dates for a hotel.
     *
     * @param string $hotelId   Hotel identifier
     * @param string $checkIn   Original check-in date
     * @param int    $nights    Stay duration
     * @param int    $adults    Total adults
     * @param array  $children  Children ages
     * @param int    $flexDays  Days to search before/after (0 = use default 10)
     * @param array  $rooms     Room XML nodes from HotelAvailabilitySearcher::getRooms()
     * @param array  $boardTypes Board type IDs from HotelAvailabilitySearcher::getBoardTypes()
     * @return array{
     *   results: array,
     *   check_in: string,
     *   check_out: string
     * }
     */
    public function search(
        string $hotelId,
        string $checkIn,
        int    $nights,
        int    $adults,
        array  $children,
        int    $flexDays,
        array  $rooms,
        array  $boardTypes
    ): array {
        $searchRange = ($flexDays > 0) ? $flexDays : 10;
        $baseDate    = strtotime($checkIn);

        // Build date list: after first, then before
        $altDates = [];
        for ($i = 1; $i <= $searchRange; $i++) {
            $tryDate = date('Y-m-d', strtotime("+{$i} days", $baseDate));
            if (strtotime($tryDate) >= strtotime('today')) {
                $altDates[] = $tryDate;
            }
        }
        for ($i = 1; $i <= $searchRange; $i++) {
            $tryDate = date('Y-m-d', strtotime("-{$i} days", $baseDate));
            if (strtotime($tryDate) >= strtotime('today')) {
                array_unshift($altDates, $tryDate);
            }
        }

        $this->log("No results for {$checkIn}. Searching alternative dates (±{$searchRange} days)...");
        $this->log("Alternative dates to try: " . implode(', ', array_slice($altDates, 0, 5)) . "...");

        $api = fn_novoton_holidays_get_api();
        if (!$api) {
            return ['results' => [], 'check_in' => '', 'check_out' => ''];
        }

        $altResults  = [];
        $altCheckIn  = '';
        $altCheckOut = '';

        foreach ($altDates as $tryCheckIn) {
            $tryCheckOut = date('Y-m-d', strtotime($tryCheckIn . ' +' . $nights . ' days'));

            foreach ($rooms as $room) {
                if (!is_object($room) && !is_array($room)) {
                    continue;
                }
                $roomId   = is_object($room) ? (string) $room->IdRoom : ($room['IdRoom'] ?? '');
                $roomName = is_object($room) ? (string) $room->Room   : ($room['Room'] ?? '');
                if (empty($roomId)) {
                    continue;
                }

                foreach ($boardTypes as $tryBoard) {
                    $priceParams = [
                        'hotel_id'    => $hotelId,
                        'room_id'     => $roomId,
                        'board_id'    => $tryBoard,
                        'star_rating' => '',
                        'check_in'    => $tryCheckIn,
                        'check_out'   => $tryCheckOut,
                        'adults'      => $adults,
                        'children'    => $children,
                    ];

                    $priceData = $api->getRoomPrice($priceParams);

                    if ($priceData && isset($priceData->Price)) {
                        $rawPrice = (float) ((string) $priceData->Price);
                        if ($rawPrice > 0) {
                            $altCheckIn  = $tryCheckIn;
                            $altCheckOut = $tryCheckOut;

                            $altPrice     = $api->applyCommission($rawPrice);
                            $altResults[] = [
                                'room'            => $room,
                                'room_id'         => $roomId,
                                'room_name'       => $roomName ?: str_replace(['%2b', '%2B'], '+', $roomId),
                                'board_id'        => $tryBoard,
                                'board_name'      => fn_novoton_holidays_format_board_name($tryBoard),
                                'price_data'      => $priceData,
                                'nights'          => $nights,
                                'total_price'     => $altPrice,
                                'price_per_night' => round($altPrice / $nights, 2),
                                'check_in'        => $tryCheckIn,
                                'check_out'       => $tryCheckOut,
                            ];
                            break; // Found for this room, move to next room
                        }
                    }
                }
            }

            if (!empty($altResults)) {
                break; // Found results, stop searching dates
            }
        }

        return [
            'results'   => $altResults,
            'check_in'  => $altCheckIn,
            'check_out' => $altCheckOut,
        ];
    }

    /**
     * @return string[]
     */
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
    }
}
