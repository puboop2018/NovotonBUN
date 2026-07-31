# sphinx_api_dev — standalone Sphinx API probes

Throwaway, **addon-independent** scripts for poking the Sphinx REST API
directly during investigation. No CS-Cart bootstrap, no DB, no addon autoload —
just `curl` + a Bearer token. Nothing here is loaded by the store; it's a dev
scratch pad, deliberately outside every lint/analysis gate.

## Reaching them in the browser (docker sandbox)

The full-store sandbox **bind-mounts this folder into the store's web root**, so the
scripts are served directly as real files — no manual copy, and host edits show up live:

```
http://localhost:8080/sphinx_api_dev/GetHotelbyId.php?id=3612
http://localhost:8080/sphinx_api_dev/HotelSearchResults.php
http://localhost:8080/sphinx_api_dev/VerifyOffer.php
```

(New files in the folder appear immediately. If a script 404s, the mount isn't in your
container yet — run `docker compose up -d` once to recreate it and pick up the mount.)
They also run from the CLI — see below.

## GetHotelbyId.php — inspect a hotel's raw payload (esp. coordinates)

Fetches `GET /api/v1/static/hotels/{id}` and prints the full JSON plus a
**location summary** and **map links**, so you can judge how precise Sphinx's
own latitude/longitude are before we change the storefront "show on map" pin.

```bash
php GetHotelbyId.php                 # defaults to hotel 3612
php GetHotelbyId.php 3612            # one hotel
php GetHotelbyId.php 3612 234 999    # several — compare precision side by side
```

Browser: `GetHotelbyId.php?id=3612`

## HotelSearchResults.php — run a live hotel search + list the offers

Drives the two-step search in one call: **POST** `/api/v1/hotels/search`
(returns a cursor, not offers), then **GET** `/api/v1/hotels/results?cursor=…`
repeatedly, following the cursor until it's null, then prints the **distinct
hotels** found (a `DISTINCT HOTELS (N unique)` block — count, name, hotel_id and
how many offers each has) followed by every offer (hotel, room, board, price,
confirmation, offer_id).

```bash
php HotelSearchResults.php                                    # defaults: destination 3713, 2 rooms
php HotelSearchResults.php destination_id=168566 check_in=2026-08-11 check_out=2026-08-18
php HotelSearchResults.php immediate=1                        # only instantly-confirmable offers
php HotelSearchResults.php raw=1                              # also dump the full JSON offers
```

Browser: `HotelSearchResults.php?destination_id=3713&immediate=1`
(Overridable: `destination_id`, `check_in`, `check_out`, `currency`;
`immediate=1` lists only `confirmation=immediate` offers, `raw=1` dumps JSON;
the occupancy keeps the default 2-room shape. The summary line always reports
how many of the total are immediate.)

### Credentials

Defaults to the Sphinx **dev/staging** values already committed in the
`sphinx_holidays` addon.xml. Override when you get production keys:

```bash
SPHINX_API_URL=https://api.sphinx…            \
SPHINX_API_TOKEN=<prod token>                 \
php GetHotelbyId.php 3612

SPHINX_API_INSECURE=1 php GetHotelbyId.php 3612   # skip TLS verify (dev boxes only)
```

## What it revealed for hotel 3612 (Rixos Downtown, Antalya)

- The API returns coordinates as **strings** (`"36.887069"`, `"30.674622"`),
  not numbers. Our mapper coerces to float, so storage is fine.
- `address.city` and `address.country` come back **empty**; the whole location
  is crammed into `address.street` as a messy multi-line string
  (`"Sakip Sabancibulvari\r\nTURKEY, ANTALYA\r\n"`).
- **Precision (measured, not assumed):** reverse-geocoding the API coordinate
  via OSM lands on *"Sakıp Sabancı Bulvarı, Meltem Mahallesi, Antalya"* — the
  exact boulevard the address names — and it's only **~135 m** from OSM's own
  "Rixos Downtown Antalya Hotel" point (36.8859, 30.6743). So for **this**
  hotel the provider coordinate is actually good; it just snaps to the road
  segment rather than the building, which can *look* slightly off.

Takeaway: whatever the pin renders, it's **the provider's own coordinate** —
we don't mangle it. 3612 is not a good example of "imprecise", so the real
next step is to **survey more hotel IDs** with this tool (pass several at once)
and find the ones that are genuinely wrong or `null`, before deciding on a fix
(name-search link vs. coordinate repair). The map-links block the script
prints lets you eyeball each one.

## VerifyOffer.php — is the VERIFY endpoint down? (search vs verify, side by side)

Search and verify are **separate endpoints**, and the storefront fails
asymmetrically when only verify is broken: the results list renders perfectly,
but **both** the "Condiții de Plată și Anulare" modal **and** the "Rezervă acum"
button fail — because each of them re-verifies the offer. The search payload
carries no terms and no booking guarantee (`must_verify=true`), so terms exist
only on the verify response. From inside the store those look like two unrelated
bugs; they are one.

This probe settles it in a single run: it searches to obtain a **live** offer id
(they expire), then calls verify and prints the HTTP status plus the provider's
**raw error body** — the part worth forwarding to the Sphinx developers.

```bash
php VerifyOffer.php                    # search, then verify the first offer
php VerifyOffer.php count=3            # verify the first three
php VerifyOffer.php offer_id=abc123    # verify one specific id, skip the search
php VerifyOffer.php destination_id=3713 check_in=2026-09-07 check_out=2026-09-13
```

Browser: `VerifyOffer.php?count=3`

Exit code is **1** when any verify fails, so it doubles as a smoke check. It
classifies exactly as the storefront does — HTTP 0 or >= 500 is an OUTAGE
(`sphinx_holidays.booking_system_unavailable` / `terms_outage`), 4xx is a
genuine expired offer (`offer_no_longer_available`). If verify comes back
healthy while the storefront still shows the outage messages, the fault is on
our side: check Administration ▸ Logs for `Sphinx booking_form: verify` and
`Sphinx offer_terms: verify`.
