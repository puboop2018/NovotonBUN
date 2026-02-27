<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Helpers\DebugLogger;

class ReservationApiClient extends ApiClientBase
{
    /**
     * 7. hotel_res_RQ - Reservation request
     *
     * @return \SimpleXMLElement|false
     */
    public function createReservation(array $bookingData): \SimpleXMLElement
    {
        $isTestMode = ConfigProvider::isTestBooking();

        $remark = $bookingData['remark'] ?? '';
        $comment = $bookingData['comment'] ?? '';

        if ($isTestMode) {
            $remark = 'test reservation, do not proceed';
            $comment = 'test reservation, do not proceed';
        }

        $guestsXml = $this->buildGuestsXml($bookingData['guests'] ?? []);

        // Check if this is multi-room booking
        $rooms = $bookingData['rooms'] ?? [];
        $hotelAccXml = '';

        if (!empty($rooms) && count($rooms) > 1) {
            $guestIdCounter = 1;
            foreach ($rooms as $roomIdx => $roomData) {
                $roomGuests = $roomData['guests'] ?? [];
                $roomAccXml = $this->buildRoomAccXml($roomGuests, $guestIdCounter);
                $guestIdCounter += count($roomGuests);

                $hotelAccXml .= '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($roomData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($roomData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '') . '</IdStar>
        <Holder>' . htmlspecialchars($roomGuests[0]['name'] ?? $bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL) . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment . ' [Room ' . ($roomIdx + 1) . ']') . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
            }
        } else {
            $roomAccXml = $this->buildRoomAccXml($bookingData['guests'] ?? []);

            $hotelAccXml = '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($bookingData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($bookingData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '') . '</IdStar>
        <Holder>' . htmlspecialchars($bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL) . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment) . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
        }

        $discountType = $bookingData['discount_type'] ?? '';

        $xml = $this->xmlHeader() . '
<hotel_res_RQ>
    ' . $this->xmlCredentials() . '
    <IdHotel>' . htmlspecialchars($bookingData['hotel_id']) . '</IdHotel>
    <CreatedBy>' . htmlspecialchars(Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
    <PackageName>' . htmlspecialchars($bookingData['package_name'] ?? '') . '</PackageName>
    <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
    <DiscountType>' . htmlspecialchars($discountType) . '</DiscountType>' . $guestsXml . $hotelAccXml . '
    <OrderNum>' . htmlspecialchars($bookingData['order_num'] ?? '') . '</OrderNum>
</hotel_res_RQ>';

        $this->lastRequest = $xml;

        DebugLogger::log('Novoton hotel_res_RQ Request (Test Mode: ' . ($isTestMode ? 'YES' : 'NO') . ')', ['xml' => $xml]);

        $response = $this->callApi(Constants::API_FUNCTION_RESERVATION, $xml, $bookingData['lang'] ?? 'UK');

        DebugLogger::log('Novoton hotel_res_RQ Response', ['response' => $response]);

        return $this->xmlParser->parse($response);
    }

    /**
     * 15. resinfo - Reservations Info
     *
     * @return \SimpleXMLElement|false
     */
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $searchXml = $idNum ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>' :
                              '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';

        $xml = $this->xmlHeader() . '
        <resinfo>
            ' . $this->xmlCredentials() . '
            ' . $searchXml . '
        </resinfo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_RES_INFO, $xml, $lang);
    }

    /**
     * 22. hotel_request - Request alternatives when no prices available
     *
     * @return \SimpleXMLElement|array|false
     */
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array
    {
        $xml = $this->buildHotelRequestXml($requestData);

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton hotel_request Request',
            'xml' => $xml
        ]);

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_REQUEST, $xml, $lang);
        $parsed = $this->xmlParser->parse($response);

        if ($returnXml) {
            return [
                'xml_sent' => $this->maskCredentials($xml),
                'xml_response' => $response,
                'parsed' => $parsed,
                'id_num' => isset($parsed->IdNum) ? (string)$parsed->IdNum : null
            ];
        }

        return $parsed;
    }

    /**
     * Generate hotel_request XML without sending (for preview/testing)
     */
    public function generateHotelRequestXml(array $requestData): string
    {
        return $this->maskCredentials($this->buildHotelRequestXml($requestData));
    }

    /**
     * 23. alternative_RS - Check for available requested alternatives
     *
     * @return \SimpleXMLElement|false
     */
    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
<alternative_RS>
  ' . $this->xmlCredentials() . '
  <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
</alternative_RS>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton alternative_RS Request',
            'xml' => $xml
        ]);

        return $this->callApiAndParse(Constants::API_FUNCTION_ALTERNATIVE_RS, $xml, $lang);
    }

    /**
     * 8. hotel_acc_RQ_html - Invoice as HTML
     *
     * @return string|false
     */
    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string
    {
        $xml = $this->xmlHeader() . '
        <hotel_acc_RQ_html>
            ' . $this->xmlCredentials() . '
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ_html>';

        return $this->callApi(Constants::API_FUNCTION_INVOICE_HTML, $xml, $lang);
    }

    /**
     * 9. hotel_acc_RQ - Invoice as XML
     *
     * @return \SimpleXMLElement|false
     */
    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <hotel_acc_RQ>
            ' . $this->xmlCredentials() . '
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ>';

        return $this->callApiAndParse(Constants::API_FUNCTION_INVOICE_XML, $xml, $lang);
    }

    /**
     * 14. list_invoices - List Invoices
     *
     * @return \SimpleXMLElement|false
     */
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $arrFromXml = $arrFrom ? '<ArrFrom>' . htmlspecialchars($arrFrom) . '</ArrFrom>' : '';
        $arrToXml = $arrTo ? '<ArrTo>' . htmlspecialchars($arrTo) . '</ArrTo>' : '';

        $xml = $this->xmlHeader() . '
        <list_invoices>
            ' . $this->xmlCredentials() . '
            ' . $arrFromXml . '
            ' . $arrToXml . '
        </list_invoices>';

        return $this->callApiAndParse(Constants::API_FUNCTION_LIST_INVOICES, $xml, $lang);
    }

    // ---- Private XML builders ----

    /**
     * Build <Guests> XML elements from a guest array.
     */
    private function buildGuestsXml(array $guests, int $startId = 1, int $defaultAge = 0): string
    {
        $xml = '';
        $id = $startId;
        foreach ($guests as $guest) {
            $xml .= '
    <Guests>
        <IdGuest>' . ($guest['id'] ?? $id) . '</IdGuest>
        <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
        <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
        <Age>' . (int) ($guest['age'] ?? $defaultAge) . '</Age>
    </Guests>';
            $id++;
        }
        return $xml;
    }

    /**
     * Build <room_acc> XML elements from a guest array.
     */
    private function buildRoomAccXml(array $guests, int $startId = 1): string
    {
        $xml = '';
        $id = $startId;
        foreach ($guests as $guest) {
            $xml .= '
            <room_acc>
                <IdGuest>' . ($guest['id'] ?? $id) . '</IdGuest>
                <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
            </room_acc>';
            $id++;
        }
        return $xml;
    }

    /**
     * Build the full hotel_request XML (shared by createHotelRequest and generateHotelRequestXml).
     */
    private function buildHotelRequestXml(array $requestData): string
    {
        $guestsXml = $this->buildGuestsXml($requestData['guests'] ?? [], 1, 30);

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            $roomAccXml = $this->buildRoomAccXml($requestData['room_guests']);
        }

        return $this->xmlHeader() . '
<hotel_request>
  ' . $this->xmlCredentials() . '
  <IdHotel>' . htmlspecialchars($requestData['hotel_id']) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars($requestData['created_by'] ?? Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
  <PackageName>' . htmlspecialchars($requestData['package_name'] ?? '') . '</PackageName>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
  <IdRoom>' . htmlspecialchars($requestData['room_id'] ?? '') . '</IdRoom>
  <IdBoard>' . htmlspecialchars($requestData['board_id'] ?? '') . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars($requestData['ext_board_id'] ?? '') . '</IdExtBoard>
  <IdStar>' . htmlspecialchars($requestData['star_rating'] ?? '') . '</IdStar>
  <Holder>' . htmlspecialchars($requestData['holder'] ?? '') . '</Holder>
  <Remark>' . htmlspecialchars($requestData['remark'] ?? '') . '</Remark>
  <Comment>' . htmlspecialchars($requestData['comment'] ?? '') . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';
    }

    /**
     * Mask API credentials in XML for logging/display.
     */
    private function maskCredentials(string $xml): string
    {
        $xml = preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
        $xml = preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xml);
        return $xml;
    }
}
