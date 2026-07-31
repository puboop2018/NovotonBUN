# `dev/sphinx/` — Sphinx API probes

Standalone probes for the Sphinx REST/JSON API (mirrors
`addon-sphinx-holidays`'s `SphinxHttpClient` / `SphinxApi`). Each sends one
request with `Authorization: Bearer <token>` and pretty-prints the JSON.

Shared client: `_sphinx_client.php` (required by every probe). Config comes
from `SPHINX_API_URL` / `SPHINX_API_TOKEN`, defaulting to the committed dev
creds.

| Probe | Endpoint | What it shows | Key args |
| --- | --- | --- | --- |
| `ping.php` | `GET /ping` | Connectivity (no auth) | — |
| `me.php` | `GET /me` | Authenticated profile (token check) | — |
| `destinations.php` | `GET /static/destinations` | Destination catalog | `--page --per_page --updated_since --limit` |
| `hotels.php` | `GET /static/hotels` | Hotel catalog | `--page --per_page --destination_ids --updated_since --limit` |
| `hotel.php` | `GET /static/hotels/{id}` | One hotel's full record | `<id>` |
| `circuits.php` | `GET /static/circuits` | Circuit catalog | `--page --per_page --updated_since --limit` |
| `experiences.php` | `GET /static/experiences` | Experience catalog | `--page --per_page --limit` |
| `package_routes.php` | `GET /static/package-routes` | Package-route catalog | `--page --per_page --limit` |
| `hotel_search.php` | `POST /hotels/search` → `GET /hotels/results` | Async availability search + poll (raw JSON) | `--destination_id --check_in --check_out --adults --children --polls --poll_delay --no_poll --limit` |
| `hotel_availability.php` | `GET /static/destinations` → search → results | **Bookable hotels in a named place** — resolves the destination by name, drains the whole cursor, filters `confirmation`, one row per hotel | `--destination --destination_id --check_in --check_out --adults --children --confirmation --offers --raw --limit --polls` |
| `hotel_verify.php` | `GET /hotels/verify` | Re-verify an offer before booking | `<offer_id>` |
| `hotel_book.php` | `POST /hotels/book` | **Book an offer** (dry-run by default) | `--offer_id --guests --email --phone --send` |
| `orders.php` | `GET /orders` (or `/orders/{id}`) | Orders list, or one order | `--page --per_page --reference_code --id --limit` |

All endpoints are under `/api/v1`.

## Typical flow

```bash
# 0. sanity
php ping.php
php me.php

# 1. browse static data
php destinations.php --per_page=50 --limit=20
php hotels.php --destination_ids=101 --per_page=50 --limit=10
php hotel.php 3612

# 2. live availability search (async: POST search, then poll results)
php hotel_search.php --destination_id=101 \
    --check_in=2026-08-02 --check_out=2026-08-09 --adults=2 --limit=10

# 2b. …or ask the question directly: what can I book in <place>?
php hotel_availability.php                       # Antalya, 01–07 Sep 2026, 2 adults + a 5yo
php hotel_availability.php --destination="Antalya City" --limit=20
php hotel_availability.php --confirmation=any --offers --raw=1

# 3. verify an offer_id from the results
php hotel_verify.php <offer_id>

# 4. preview a booking (nothing sent)
php hotel_book.php --offer_id=<offer_id> \
    --guests="POPESCU ION,POPESCU ANA" --email=ion@example.com
#   add --send to actually book.
```

## Pagination vs. `limit`

Sphinx pages **server-side** with `--page` / `--per_page` (the API requires
`per_page >= 10`). `--limit=N` is a **separate** client-side print-trim: fetch
a full page but print only the first N entries of the biggest list in the
response. Run any probe with `--help` for its full argument list.
