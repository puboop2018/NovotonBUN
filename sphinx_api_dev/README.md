# sphinx_api_dev — standalone Sphinx API probes

Throwaway, **addon-independent** scripts for poking the Sphinx REST API
directly during investigation. No CS-Cart bootstrap, no DB, no addon autoload —
just `curl` + a Bearer token. Nothing here is loaded by the store; it's a dev
scratch pad, deliberately outside every lint/analysis gate.

## get_hotel.php — inspect a hotel's raw payload (esp. coordinates)

Fetches `GET /api/v1/static/hotels/{id}` and prints the full JSON plus a
**location summary** and **map links**, so you can judge how precise Sphinx's
own latitude/longitude are before we change the storefront "show on map" pin.

```bash
php get_hotel.php                 # defaults to hotel 3612
php get_hotel.php 3612            # one hotel
php get_hotel.php 3612 234 999    # several — compare precision side by side
```

Browser (drop the folder anywhere PHP is served, e.g. the sandbox docroot):

```
get_hotel.php?id=3612
```

### Credentials

Defaults to the Sphinx **dev/staging** values already committed in the
`sphinx_holidays` addon.xml. Override when you get production keys:

```bash
SPHINX_API_URL=https://api.sphinx…            \
SPHINX_API_TOKEN=<prod token>                 \
php get_hotel.php 3612

SPHINX_API_INSECURE=1 php get_hotel.php 3612   # skip TLS verify (dev boxes only)
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
