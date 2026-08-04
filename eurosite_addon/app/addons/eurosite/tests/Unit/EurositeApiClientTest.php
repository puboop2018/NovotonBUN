<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeTransportInterface;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;

/**
 * End-to-end (no network) tests for the API facade: a fake transport captures
 * the request the client BUILDS and returns canned responses copied from the
 * spec, so both the request payloads and the response mapping are pinned.
 */
final class EurositeApiClientTest extends TestCase
{
    private function client(FakeTransport $t): EurositeApiClient
    {
        return new EurositeApiClient($t, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'EU');
    }

    public function testGetCountriesParsesTheResponseList(): void
    {
        $t = new FakeTransport(self::wrap('getCountryResponse',
            '<Country><CountryCode>AL</CountryCode><CountryName>Albania</CountryName></Country>'
            . '<Country><CountryCode>AD</CountryCode><CountryName>Andorra</CountryName></Country>'));

        $countries = $this->client($t)->getCountries();

        self::assertCount(2, $countries);
        self::assertSame(['code' => 'AL', 'name' => 'Albania'], $countries[0]);
        self::assertSame('Andorra', $countries[1]['name']);
        // The request the client SENT is a proper getCountryRequest envelope.
        self::assertStringContainsString('RequestType="getCountryRequest"', $t->lastRequest);
    }

    public function testGetCitiesSendsTheCountryAttributeAndParsesRows(): void
    {
        // Canned rows mirror the live shape (2026-08-04): CityId/CountryId
        // extras plus Romanian diacritics must pass through cleanly.
        $t = new FakeTransport(self::wrap('getCityResponse',
            '<City><CountryCode>RO</CountryCode><CountryId>1</CountryId>'
            . '<CityCode>ROBH</CityCode><CityId>101</CityId><CityName>Băile Herculane</CityName></City>'));

        $cities = $this->client($t)->getCities('RO');

        self::assertSame([['code' => 'ROBH', 'name' => 'Băile Herculane']], $cities);
        // CountryCode must ride as an ATTRIBUTE — sent as a child element the
        // live server ignores the filter and returns every country's cities.
        self::assertStringContainsString('<getCityRequest CountryCode="RO"/>', $t->lastRequest);
    }

    public function testGetOwnHotelsParsesRoomsAndToleratesRoomlessHotels(): void
    {
        // Live shape (Albena, 2026-08-04): Touropcode rides along ("LA" on the
        // Laguna platform), room codes use a lowercase `code` attribute, and a
        // hotel may carry an empty <Rooms/>.
        $t = new FakeTransport(self::wrap('getOwnHotelsResponse',
            '<Hotel><Touropcode>LA</Touropcode><HotelCode>BG0005</HotelCode><HotelName>MALIBU ALBENA</HotelName>'
            . '<CountryCode>BG</CountryCode><CityCode>BGALB</CityCode><CityName>Albena</CityName>'
            . '<Rooms><Room code="1">Single</Room><Room code="2892">Dubla</Room></Rooms></Hotel>'
            . '<Hotel><Touropcode>LA</Touropcode><HotelCode>BG0006</HotelCode><HotelName>KALIAKRA BEACH</HotelName>'
            . '<CountryCode>BG</CountryCode><CityCode>BGALB</CityCode><CityName>Albena</CityName>'
            . '<Rooms/></Hotel>'));

        $hotels = $this->client($t)->getOwnHotels('BGALB');

        self::assertCount(2, $hotels);
        self::assertSame('BG0005', $hotels[0]['code']);
        self::assertSame('LA', $hotels[0]['tourop']);
        self::assertSame(['1' => 'Single', '2892' => 'Dubla'], $hotels[0]['rooms']);
        self::assertSame([], $hotels[1]['rooms']);
        self::assertStringContainsString('RequestType="getOwnHotelsRequest"', $t->lastRequest);
        self::assertStringContainsString('<CityCode>BGALB</CityCode>', $t->lastRequest);
    }

    public function testGetRoomTypesReadsTheSpecAttributeCase(): void
    {
        // The spec's getRoomResponse uses a capital-C Code attribute (live
        // matches, 2026-08-04); the mapper hedges Code/code.
        $t = new FakeTransport(self::wrap('getRoomResponse',
            '<Room Code="AP">apartament</Room><Room Code="DB">double</Room>'));

        $rooms = $this->client($t)->getRoomTypes();

        self::assertSame([['code' => 'AP', 'name' => 'apartament'], ['code' => 'DB', 'name' => 'double']], $rooms);
        self::assertStringContainsString('RequestType="getRoomRequest"', $t->lastRequest);
    }

    public function testStaticCallThrowsOnTheLiveAuthRefusalEnvelope(): void
    {
        // The REAL error envelope every caller with invalid credentials gets
        // (captured live): it must surface as an exception, never as an empty
        // catalog a sync job would happily persist.
        $t = new FakeTransport('<?xml version="1.0" encoding="utf-8"?>'
            . '<Response ResponseType="Error">'
            . '<AuditInfo><ResponseId>266456772</ResponseId><RequestId>81184</RequestId>'
            . '<ResponseTime>2026-08-04T17:08:12</ResponseTime></AuditInfo>'
            . '<ResponseDetails><Errors><Error><ErrorId>-1000</ErrorId>'
            . '<ErrorText>You are not authorised to access this server!</ErrorText>'
            . '</Error></Errors></ResponseDetails></Response>');

        $this->expectException(\Tygh\Addons\Eurosite\Exception\EurositeApiException::class);
        $this->expectExceptionMessage('-1000: You are not authorised to access this server!');
        $this->client($t)->getCountries();
    }

    public function testGetOwnCitiesParsesTheCrossCountryCatalog(): void
    {
        // Canned rows mirror the live catalog (verified 2026-08-04): one list
        // across all countries, CountryCode per row.
        $t = new FakeTransport(self::wrap('getOwnCityResponse',
            '<City><CountryCode>BG</CountryCode><CityCode>BGALB</CityCode><CityName>Albena</CityName></City>'
            . '<City><CountryCode>RO</CountryCode><CityCode>ROMM</CityCode><CityName>Mamaia</CityName></City>'));

        $cities = $this->client($t)->getOwnCities();

        self::assertSame([
            ['code' => 'BGALB', 'name' => 'Albena', 'country_code' => 'BG'],
            ['code' => 'ROMM', 'name' => 'Mamaia', 'country_code' => 'RO'],
        ], $cities);
        self::assertStringContainsString('RequestType="getOwnCityRequest"', $t->lastRequest);
    }

    public function testGetTagOffersParsesTagsAndToleratesAnEmptyCatalog(): void
    {
        $t = new FakeTransport(self::wrap('getTagOffersResponse',
            '<Tag><TagCode>2</TagCode><TagName>1 Mai</TagName></Tag>'
            . '<Tag><TagCode>5</TagCode><TagName>Craciun</TagName></Tag>'));

        $tags = $this->client($t)->getTagOffers();

        self::assertSame([['code' => '2', 'name' => '1 Mai'], ['code' => '5', 'name' => 'Craciun']], $tags);
        self::assertStringContainsString('RequestType="getTagOffersRequest"', $t->lastRequest);

        // Live 2026-08-04: this account's tag catalog is empty —
        // <getTagOffersResponse/> is a valid, non-error response.
        $empty = new FakeTransport(self::wrap('getTagOffersResponse', ''));
        self::assertSame([], $this->client($empty)->getTagOffers());
    }

    public function testStaticParsersTolerateExtraLiveOnlyFields(): void
    {
        // The live service returns more fields than the spec examples show
        // (CountryId, DirectivaEuropeana, CityId — seen 2026-08-04); mapping
        // must pass them by without breaking.
        $t = new FakeTransport(self::wrap('getCountryResponse',
            '<Country><CountryCode>AL</CountryCode><CountryId>4</CountryId>'
            . '<CountryName>Albania</CountryName><DirectivaEuropeana/></Country>'));

        self::assertSame([['code' => 'AL', 'name' => 'Albania']], $this->client($t)->getCountries());
    }

    public function testSearchHotelsBuildsThePayloadAndMapsAnOffer(): void
    {
        $t = new FakeTransport(self::wrap('getHotelPriceResponse',
            '<Hotel><Product>'
            . '<TourOpCode>EU</TourOpCode><CountryCode>RO</CountryCode><CityCode>ROMM</CityCode>'
            . '<CityName>Mamaia</CityName><ProductCode>RO0099</ProductCode><ProductName>Condor</ProductName>'
            . '<ProductCategory>4</ProductCategory><Class>Hotel</Class><Latitude>0</Latitude><Longitude>0</Longitude>'
            . '</Product><Offers><Offer CurrencyCode="EUR">'
            . '<OfferType>Normal</OfferType><Availability Code="OR">OnRequest</Availability>'
            . '<PeriodOfStay><CheckIn>2012-09-14</CheckIn><CheckOut>2012-09-21</CheckOut></PeriodOfStay>'
            . '<ProductPrice>481.99</ProductPrice><Gross>524</Gross><NET>453.15</NET><Commission>70.85</Commission>'
            . '<GrilaName>Standard</GrilaName><PackageVariantId>0|1065487_1874_2</PackageVariantId>'
            . '<BookingRoomTypes><Room Code="40" GCode="DB" Quantity="1" ServicePrice="125.795">Double Room</Room></BookingRoomTypes>'
            . '<Meals><Meal Type="2" Code="11" ServicePrice="230.4">Mic dejun</Meal></Meals>'
            . '</Offer></Offers></Hotel>'));

        $offers = $this->client($t)->searchHotels([
            'country_code' => 'RO',
            'city_code'    => 'ROMM',
            'check_in'     => '2012-09-14',
            'check_out'    => '2012-09-21',
            'rooms'        => [['code' => 'DB', 'adults' => 2, 'children' => [8]]],
        ]);

        self::assertCount(1, $offers);
        $o = $offers[0];
        self::assertSame('RO0099', $o->productCode);
        self::assertSame('Condor', $o->productName);
        self::assertSame(4, $o->category);
        self::assertSame(481.99, $o->price);
        self::assertSame('0|1065487_1874_2', $o->variantId);
        self::assertSame('Double Room', $o->rooms[0]['name']);
        self::assertSame('Mic dejun', $o->meals[0]['name']);

        // The request carried the search criteria + occupancy.
        $req = $t->lastRequest;
        self::assertStringContainsString('RequestType="getHotelPriceRequest"', $req);
        self::assertStringContainsString('<CityCode>ROMM</CityCode>', $req);
        self::assertStringContainsString('<Room Code="DB" NoAdults="2" NoChildren="1">', $req);
        self::assertStringContainsString('<Age>8</Age>', $req);
    }

    public function testAddBookingExtractsTheApiReference(): void
    {
        $t = new FakeTransport(self::wrap('AddBookingResponse',
            '<BookingReferences>'
            . '<BookingReference Source="api">EU_XML_12898</BookingReference>'
            . '<BookingReference Source="client">int1234</BookingReference>'
            . '</BookingReferences>'));

        $res = $this->client($t)->addBooking([
            'booking_name' => 'int1234',
            'client_id'    => 'int1234',
            'country_code' => 'BG',
            'city_code'    => 'BGNSPDAR',
            'product_code' => 'BG0069',
            'variant_id'   => '0|1067879_1453_1',
            'check_in'     => '2012-09-22',
            'check_out'    => '2012-09-29',
            'rooms'        => [[
                'code' => '817', 'adults' => 2,
                'pax'  => [
                    ['type' => 'adult', 'name' => 'TEST / TEST', 'gender' => 'B', 'dob' => '1980-08-24'],
                    ['type' => 'child', 'name' => 'kid / kid', 'gender' => 'C', 'child_age' => '5', 'dob' => '2019-07-05'],
                ],
            ]],
        ]);

        self::assertTrue($res['ok']);
        self::assertSame('EU_XML_12898', $res['api_ref']);
        self::assertSame('int1234', $res['client_ref']);

        $req = $t->lastRequest;
        self::assertStringContainsString('RequestType="AddBookingRequest"', $req);
        self::assertStringContainsString('<ProductCode>BG0069</ProductCode>', $req);
        self::assertStringContainsString('<VariantId>0|1067879_1453_1</VariantId>', $req);
        self::assertStringContainsString('PaxType="child"', $req);
        self::assertStringContainsString('ChildAge="5"', $req);
    }

    public function testCancelBookingReportsSuccessWhenNoErrorNode(): void
    {
        $t = new FakeTransport(self::wrap('CancelBookingResponse', '<Status>Cancelled</Status>'));
        $res = $this->client($t)->cancelBooking('int1234');

        self::assertTrue($res['ok']);
        self::assertSame('', $res['error']);
        self::assertStringContainsString('RequestType="CancelBookingRequest"', $t->lastRequest);
    }

    private static function wrap(string $responseType, string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<Response ResponseType="' . $responseType . '">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><' . $responseType . '>' . $inner . '</' . $responseType . '></ResponseDetails>'
            . '</Response>';
    }
}

/**
 * Captures the request XML and returns a pre-set response.
 */
final class FakeTransport implements EurositeTransportInterface
{
    public string $lastRequest = '';

    public function __construct(private readonly string $response)
    {
    }

    #[\Override]
    public function post(string $xml): string
    {
        $this->lastRequest = $xml;

        return $this->response;
    }
}
