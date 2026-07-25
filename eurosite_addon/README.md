# `eurosite_addon/` — Eurosite Holidays provider addon (MVP)

A new CS-Cart provider addon (id **`eurosite`**) for the **Eurosite** XML web
service — a distinct Touroperator platform, a sibling to the existing
`novoton_holidays` and `sphinx_holidays` providers. Built from
`Documentation/Specificatii_API_Eurosite.pdf`.

> **Status: MVP / work-in-progress.** The API protocol layer is complete and
> unit-tested; the storefront/cart/admin surfaces and CI-gate wiring are the
> documented next steps (see *Graduation* below). The addon is not yet wired
> into the repo's PHPStan/cs-fixer/CI paths — it lives in its own top-level
> folder on purpose so it can mature without destabilizing the green branch.

```
eurosite_addon/app/addons/eurosite/
├── addon.xml            id=eurosite, deps=travel_core, settings + eurosite_bookings table
├── init.php             PSR-4 autoload + TravelProviderRegistry registration
├── func.php             thin CS-Cart procedural boundary (hooks are a follow-up)
├── composer.json        PSR-4 (Tygh\Addons\Eurosite\)
├── lang_keys.php        settings labels (en/ro)
├── phpunit.xml
├── src/
│   ├── EurositeXmlBuilder.php        builds the <Request>/<AuditInfo> envelope
│   ├── EurositeHttpClient.php        curl POST + retry/circuit-breaker (travel_core ResiliencePolicy)
│   ├── EurositeTransportInterface.php  transport seam (fakeable in tests)
│   ├── EurositeXmlParser.php         cleans + unwraps <ResponseDetails>
│   ├── Api/
│   │   ├── EurositeApiClient.php     the endpoint facade (static data → search → booking)
│   │   └── EurositeNormalizer.php    Eurosite → canonical codes (ProviderNormalizerInterface)
│   ├── Dto/HotelOffer.php            a normalized search-result offer
│   └── Services/
│       ├── ConfigProvider.php        settings getters (extends travel_core AbstractConfigProvider)
│       └── Container.php             composition root (wires the API stack)
└── tests/Unit/                       13 tests, 68 assertions (envelope, search, booking, normalizer)
```

## The Eurosite protocol

XML over HTTP. Every request is the same envelope (spec: *Autentificarea*):

```xml
<Request RequestType="getHotelPriceRequest">
  <AuditInfo>
    <RequestId>001</RequestId>
    <RequestUser>YourUser</RequestUser>
    <RequestPass>YourPassword</RequestPass>
    <RequestTime>2012-09-04T18:00:46</RequestTime>
    <RequestLang>RO</RequestLang>
  </AuditInfo>
  <RequestDetails> … endpoint payload … </RequestDetails>
</Request>
```

Responses mirror it: `<Response ResponseType="…Response"><AuditInfo/><ResponseDetails>…`.
This is genuinely different from Novoton (form-POST `fn=…`) and Sphinx
(REST/JSON Bearer) — hence a separate addon.

## MVP scope — the "individual accommodations" (Cazari individuale) vertical

`EurositeApiClient` covers the endpoints that make a coherent hotel
search-to-booking flow, mapped 1:1 to the spec:

| Method | Eurosite RequestType | Purpose |
| --- | --- | --- |
| `getCountries()` | getCountryRequest | static: countries |
| `getCities($country)` | getCityRequest | static: cities |
| `getOwnHotels($city)` | getOwnHotelsRequest | static: own hotels + rooms |
| `getRoomTypes()` | getRoomRequest | static: room-type catalog |
| `searchHotels($params)` | getHotelPriceRequest | availability + price search → `HotelOffer[]` |
| `getProductInfo(...)` | getProductInfoRequest | hotel details (description, images, coords) |
| `addBooking($booking)` | AddBookingRequest | create a booking → api/client references |
| `getBooking($ref)` | getBookingRequest | booking status + details |
| `cancelBooking($ref)` | CancelBookingRequest | request cancellation |

**Deferred (spec covers them; not in the MVP):** supplementary services,
item fees / cancellation penalties, packages, transport (charter), circuits,
excursions, and pax modification. The envelope + parser already generalize to
these — they're additional `EurositeApiClient` methods.

## Configuration

Addon settings (Admin → Add-ons → Eurosite), read via `ConfigProvider`:

- `api_url` — the XML web-service endpoint (default:
  `https://laguna.touringit.ro/server_xml/server.php` — the single URL every
  RequestType is POSTed to)
- `api_user` / `api_password` — the credentials embedded in `<AuditInfo>`
- `tourop_code` — the operator code in payloads (default `EU`)
- `default_currency` (EUR) / `default_language` (RO)
- `allow_insecure_api` — permit http:// transport (dev)
- retry / timeout / circuit-breaker tuning

There are **no default credentials** — the spec ships placeholders
(`YourUser`/`YourPassword`), so nothing here authenticates until you enter
the real account keys. The endpoint URL ships as the default above
(HTTPS, on the touringit.ro platform Eurosite runs on); the spec's sample
payloads reference the same platform (`EU.touringit.ro` asset links).

## Testing

```bash
cd eurosite_addon/app/addons/eurosite
composer install                 # once, for phpunit
vendor/bin/phpunit               # 13 tests, 68 assertions
```

The tests inject a fake transport (`EurositeTransportInterface`) that captures
the request the client builds and returns canned responses copied from the
spec, so both request-building and response-mapping are verified without
network. The code is PHPStan level-10 clean and PSR-12 formatted.

## Graduation (next steps, in order)

1. **Install & smoke-test** against a real Eurosite endpoint (a `dev/eurosite/`
   standalone probe like `dev/sphinx` would exercise it without CS-Cart).
2. **Storefront**: a `TravelProviderRegistry` hotel-product provider + a search
   controller/template, reusing the shared travel_core search UI.
3. **Cart + order pipeline**: `BookingRepository` (the `eurosite_bookings`
   table is already in `addon.xml`), a `place_order_post` hook body in
   `src/Hooks` delegating from `func.php`, and the shared `TravelBookingMirror`.
4. **Admin**: bookings grid via the shared travel_core admin surfaces.
5. **Wire into the CI gates**: add the addon's `src`/`controllers`/`func.php`
   to `phpstan.neon`, `.php-cs-fixer.dist.php`, the CI `php -l` step, and a
   PHPUnit coverage job — the same treatment novoton/sphinx get.
