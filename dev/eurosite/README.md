# `dev/eurosite/` — standalone Eurosite API probes

Companion to `dev/novoton/` and `dev/sphinx/`: self-contained PHP scripts
(no CS-Cart, no autoload, no DB) that exercise one Eurosite RequestType each
and print the RAW request (credentials masked) + response. Usable from the
CLI or a browser; the shared docker bind mount (`../../dev`) serves them at
`/dev/eurosite/...` inside the fullstore container.

## The one endpoint

Everything POSTs to a single URL — RequestTypes are envelope attributes,
not paths:

```
https://laguna.touringit.ro/server_xml/server.php
```

**IP allowlist:** the server refuses unknown hosts outright — every request
from a non-whitelisted IP answers `ErrorId -1000 "You are not authorised to
access this server!"` regardless of credentials (verified live 2026-07-25).
Run these probes from the store server or another whitelisted machine. Per
the operator, the static-data services (countries/cities/rooms/own hotels)
then answer without a real account; search/booking need credentials.

## Configuration (env vars)

| Var | Default |
| --- | --- |
| `EUROSITE_API_URL` | `https://laguna.touringit.ro/server_xml/server.php` |
| `EUROSITE_API_USER` | `YourUser` (spec placeholder) |
| `EUROSITE_API_PASSWORD` | `YourPassword` (spec placeholder) |
| `EUROSITE_API_LANG` | `RO` |
| `EUROSITE_API_INSECURE` | unset (set `1` to skip TLS verify — dev only) |

## Probes

| Script | RequestType | Args |
| --- | --- | --- |
| `countries.php` | getCountryRequest | `--limit=N` |
| `cities.php` | getCityRequest | `--country=RO --limit=N` |
| `rooms.php` | getRoomRequest | `--limit=N` |
| `own_hotels.php` | getOwnHotelsRequest | `--city=CODE --limit=N` |
| `hotel_search.php` | getHotelPriceRequest | `--country --city --check-in --nights --adults [--children=8,5] [--room=DB] --limit=N` |
| `product_info.php` | getProductInfoRequest | `--country --city --product [--type=hotel] --limit=N` |
| `booking_info.php` | getBookingRequest | `--ref=REF [--source=client|api]` |

Every probe accepts `--help` (CLI) / `?help` (browser). `--limit=N` trims the
most-repeated element in the response to its first N occurrences so big
catalogs stay readable.

Booking **creation/cancellation** probes are deliberately absent until the
account is live — use the addon's `EurositeApiClient::addBooking()` /
`cancelBooking()` once credentials are configured, or ask for dry-run probes
like `dev/novoton/reservation.php` when you need them.

## Examples

```bash
php countries.php --limit=20
php cities.php --country=RO --limit=30
php hotel_search.php --city=ROMM --check-in=2026-08-10 --nights=7 --adults=2 --children=8
EUROSITE_API_USER=... EUROSITE_API_PASSWORD=... php booking_info.php --ref=int1234
```
