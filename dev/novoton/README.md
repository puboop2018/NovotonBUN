# `dev/novoton/` — Novoton API probes

Standalone probes for the Novoton XML-over-HTTP API (mirrors
`addon-novoton-holidays`'s `NovotonHttpClient` / API clients). Each posts one
`fn=<function>` to `{api_url}/index.php` and pretty-prints the windows-1251
response as UTF-8.

Shared client: `_novoton_client.php` (required by every probe). Config comes
from `NOVOTON_API_*` env vars, defaulting to the committed dev creds.

| Probe | API `fn` | What it shows | Key args |
| --- | --- | --- | --- |
| `hotel_list.php` | `hotel_list` | Hotel names, filterable | `--country --city --hotel --type --limit` |
| `hotel_info.php` | `hotelinfo` | Rooms / boards / packages for a hotel | `<hotel_id> --lang --limit` |
| `hotel_description.php` | `hotel_description` | Descriptive text | `<hotel_id> --lang --package` |
| `hotel_images.php` | `hotel_images` | Picture URLs | `<hotel_id> --limit` |
| `list_facilities.php` | `list_facilities` | Full facility/feature catalog | `--lang --limit` |
| `hotel_facilities.php` | `hotel_facilities` | Facilities for one hotel | `<hotel_id> --limit` |
| `resort_list.php` | `resort_list` | Destinations / resort names | `--country --lang --limit` |
| `room_price.php` | `room_price` | Real-time price for a room | `--hotel_id --room_id --board_id --check_in --check_out --adults --children` |
| `hotel_quota.php` | `hotel_quota` | Free allotment (availability) | `--hotel_id --room_id --check_in --check_out` |
| `search.php` | `frmsearch` | Availability search across hotels | `--country --city --hotel --check_in --check_out --adults --limit` |
| `price_info.php` | `priceinfo` | Season prices for a package | `--hotel_id --package --limit` |
| `special_offers.php` | `spo` | Early booking / discounts | `--hotel_id --package --limit` |
| `reservation.php` | `hotel_res_RQ` | **Submit a booking** (dry-run by default) | `--hotel_id --room_id --board_id --check_in --check_out --holder --guests --send --real` |
| `reservation_info.php` | `resinfo` | Status of an existing reservation | `--id_num` or `--confirm_agency` |

## Typical flow

```bash
# 1. find a hotel
php hotel_list.php --country=BULGARIA --hotel=EDART

# 2. get its room + board codes
php hotel_info.php 4535

# 3. price a room for some dates
php room_price.php --hotel_id=4535 --room_id="DBL 2+0" --board_id=BB \
                   --check_in=2026-08-02 --check_out=2026-08-09 --adults=2

# 4. preview a booking (nothing sent)
php reservation.php --hotel_id=4535 --room_id="DBL 2+0" --board_id=BB \
    --check_in=2026-08-02 --check_out=2026-08-09 \
    --holder="POPESCU ION" --guests="POPESCU ION,POPESCU ANA"
#   add --send to submit it as a TEST reservation.
```

`--limit=N` trims the most-repeated element (hotels, resorts, rooms, …) to the
first N rows. Run any probe with `--help` for its full argument list.
