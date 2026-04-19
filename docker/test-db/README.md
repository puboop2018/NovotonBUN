# Pre-baked test-DB image

A private Docker image carrying a clean post-install MySQL state for CS-Cart
4.20.1 with the three NovotonBUN addons enabled. Consumed by the
`phpunit-integration-*` CI jobs and the local `docker-compose.test.yml`.

Rebuilt manually by a maintainer — **never** rebuilt by CI. This is
deliberate: CS-Cart's installer is interactive, license-prompt-sensitive,
and adds 3-5 minutes of flaky wall clock to every CI run.

## What ships in the image

- `mysql:8.0` base.
- A single gzipped SQL dump at `/docker-entrypoint-initdb.d/00_cscart_seed.sql.gz`
  produced by `mysqldump` against a fully-installed CS-Cart 4.20.1 database with
  `novoton_holidays`, `travel_core`, and `sphinx_holidays` addons enabled.

## What does NOT ship

- Any CS-Cart PHP code.
- The CS-Cart license key.
- The original `cscart_v4.20.1.zip`.

`build.sh` runs a paranoia check against the final image layers to confirm the
license key is absent before pushing.

## Prerequisites (maintainer machine)

1. Docker 24+ with `docker buildx`.
2. GHCR push access (`docker login ghcr.io`) to
   `ghcr.io/puboop2018/novotonbun-test-db`.
3. Local copy of `cscart_v4.20.1.zip`.
4. A valid CS-Cart 4.20.1 license key.

## Rebuild procedure

```bash
export CSCART_ZIP_PATH=/absolute/path/to/cscart_v4.20.1.zip
export CSCART_LICENSE_KEY=XXXX-XXXX-XXXX-XXXX
./docker/test-db/build.sh
```

`build.sh`:

1. Unzips CS-Cart to a temp dir.
2. Copies the three addons into `app/addons/`.
3. Boots a throwaway `mysql:8.0` + `php:8.3-cli` pair, runs CS-Cart's CLI
   installer, enables the addons.
4. `mysqldump` → `seed.sql.gz`.
5. `docker build` → tags with schema hash.
6. Verifies license key is absent from image layers.
7. `docker push` (unless `SKIP_PUSH=1`).

Run time: ~3-5 minutes on a warm machine.

## When to rebuild

Rebuild triggers:

- CS-Cart core upgrade (new zip version).
- Any addon's `addon.xml` `<item for="install">` DDL changes.
- A new addon is added or an existing addon is removed.
- New `schemas/*.xml` files alter data structures the integration tests read.

After pushing a new tag, bump the tag in:

- `docker-compose.test.yml` (root)
- `.github/workflows/ci.yml` (any `phpunit-integration-*` job's `services:` block)

## Local smoke-test

```bash
docker run --rm -p 3307:3306 \
    -e MYSQL_ROOT_PASSWORD=root \
    ghcr.io/puboop2018/novotonbun-test-db:latest

# In another shell — wait for healthcheck, then:
mysql -h 127.0.0.1 -P 3307 -uroot -proot cscart -e 'SHOW TABLES LIKE "cscart_novoton_%"'
# Expect: cscart_novoton_bookings, cscart_novoton_hotels, cscart_novoton_cache, ...
```

## CS-Cart installer CLI flags

`build.sh` passes the following to `install/index.php`:

- `--db_host`, `--db_name`, `--db_user`, `--db_password`
- `--admin_username`, `--admin_password`, `--admin_email`
- `--license_number`
- `--non-interactive`

If CS-Cart 4.20.1's CLI installer uses different flag names or requires
additional fields, adapt `build.sh` accordingly. The script's installer stage
is the one part most likely to need maintainer tweaks on first run.

## Troubleshooting

**Installer hangs.** Some CS-Cart versions fall through to an interactive
prompt if a flag is unrecognised. Re-run with `SKIP_PUSH=1` and tail the
container log.

**`mysqldump` exits non-zero with `--no-tablespaces`.** Required on MySQL 8
without `PROCESS` grant; should succeed as root.

**Image too large.** CS-Cart's seed data (demo catalog, sample orders) can
balloon `seed.sql.gz` past 30 MB. If this becomes a CI pull-time problem,
add a `mysqldump --ignore-table` block to the dump step for the demo-only
tables.
