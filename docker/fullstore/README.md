# Full-store CS-Cart sandbox

A disposable, local CS-Cart 4.20.1 store on **MariaDB 10.11** (matching the
production server) with all four repo addons linked in for live testing —
install/uninstall, crons, booking flows, templates, and emails — without
touching devx.

> This is a **dev sandbox**, not a production image (development mode on,
> debug-friendly PHP). devx/production remain the deploy targets.

## What you need

- **Docker Desktop** (you already have it) with the **WSL2 backend** enabled
  (Settings → General → *Use the WSL 2 based engine*).
- Your **CS-Cart 4.20.1 kit** (`cscart_v4.20.1.zip` or an extracted folder) and
  a **valid license key**. Nothing in the repo ships the CS-Cart core — you
  supply it. Both stay gitignored.

## One-time setup (Windows + WSL2)

1. **Clone the repo inside WSL2**, not on the Windows drive — file watching and
   PHP are dramatically faster on the Linux filesystem. In a WSL (Ubuntu)
   terminal:
   ```bash
   cd ~ && git clone <repo-url> NovotonBUN && cd NovotonBUN/docker/fullstore
   ```
   (If you must keep the repo on `C:`, it still works via `/mnt/c/...`, just
   slower.)

2. **Create your env file** and fill in the license key:
   ```bash
   cp .env.example .env
   nano .env            # set CSCART_LICENSE_KEY=...
   ```

3. **Drop your kit** into `./kit/`:
   ```bash
   cp /mnt/c/path/to/cscart_v4.20.1.zip ./kit/
   ```

4. **Build and start**:
   ```bash
   docker compose up -d --build
   ```
   First boot unpacks the kit, links the addons, and runs the CS-Cart installer
   — watch it with `docker compose logs -f app`. Give it a few minutes; it's
   done when you see `Provisioning complete — store is live`.

## URLs

| What | URL | Notes |
|------|-----|-------|
| Storefront | http://localhost:8080 | |
| Admin | http://localhost:8080/admin.php | `admin@example.com` / `admin` |
| Mailpit (all emails) | http://localhost:8025 | booking/invoice/alert mails land here |
| phpMyAdmin | http://localhost:8081 | |

> If `/admin.php` 404s, CS-Cart may have used a different admin script name —
> check `$config['admin_index']` in `config.local.php`
> (`docker compose exec app cat config.local.php | grep admin`).

## Daily use

- **Edit an addon** in the repo (host IDE) → **refresh the browser**. PHP picks
  up on the next request; templates recompile automatically (development mode).
  No manual cache clear needed.
- **Run a cron** (copy the real URL from Admin → the addon's Dashboard):
  ```bash
  curl "http://localhost:8080/index.php?dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates"
  ```
- **Re-run the ordered addon install** (to test the lifecycle):
  ```bash
  docker compose exec app php /usr/local/bin/install-addons.php
  ```
- **Shell into the store**: `docker compose exec app bash`
- **Logs**: `docker compose logs -f app`

## Reset

- Restart (keeps data): `docker compose restart`
- **Wipe clean** (re-provision from scratch — new DB, re-install):
  ```bash
  docker compose down -v && docker compose up -d --build
  ```

## Notes & limits

- **Live provider API calls** (Novoton/Sphinx) need real credentials + network
  reachability. The seeded `enc:` test credentials exercise the store, admin,
  install/uninstall, template rendering, cron plumbing, and email — but not live
  API responses. Keep `disable_api_submission` on when testing booking-flow UI.
- **Uninstall order** is enforced by the addons: CS-Cart blocks uninstalling
  `travel_core` while `novoton_holidays`/`sphinx_holidays` are active — uninstall
  the providers first. `fgo_invoicing` is independent.
- The kit and `.env` are gitignored; everything else here is committed so the
  whole team shares the same sandbox.
- The separate `docker/test-db/` image (MySQL-only, for CI) is unrelated and
  untouched by this.
