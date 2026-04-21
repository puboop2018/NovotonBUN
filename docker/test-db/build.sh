#!/usr/bin/env bash
#
# Build the pre-baked MySQL test image for the NovotonBUN monorepo.
#
# Runs ONCE on the maintainer's machine. Output is a Docker image tagged with
# the schema hash, pushed to GHCR. CI jobs pull that image as a service
# container — they never re-run this script.
#
# Re-run only when:
#   - CS-Cart upgrades (new zip)
#   - An addon's addon.xml <item for="install"> DDL changes
#   - A new addon is added or removed
#
# Inputs (required env vars):
#   CSCART_SRC           Absolute path to either:
#                          - cscart_v4.20.1.zip  (will be unzipped), or
#                          - an already-extracted CS-Cart 4.20.1 directory.
#                        Back-compat alias: CSCART_ZIP_PATH.
#   CSCART_LICENSE_KEY   Valid CS-Cart license key
#
# Optional env vars:
#   IMAGE_BASE           Default: ghcr.io/puboop2018/novotonbun-test-db
#   CSCART_VERSION       Default: 4.20.1
#   SKIP_PUSH            Set to 1 to build only, skip docker push
#
# Local config:
#   If docker/test-db/build.env exists it is sourced before env-var checks.
#   That file is gitignored — put your machine-specific paths there so you
#   don't have to `export` every time. See build.env.example.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"

# Load maintainer-local config if present (gitignored).
if [ -f "$HERE/build.env" ]; then
    # shellcheck disable=SC1091
    . "$HERE/build.env"
fi

# Back-compat: old variable name.
CSCART_SRC="${CSCART_SRC:-${CSCART_ZIP_PATH:-}}"

: "${CSCART_SRC:?CSCART_SRC (or CSCART_ZIP_PATH) must point at the cscart zip or extracted dir}"
: "${CSCART_LICENSE_KEY:?CSCART_LICENSE_KEY must be set (never commit)}"

if [ ! -e "$CSCART_SRC" ]; then
    echo "[build.sh] FATAL: CSCART_SRC=$CSCART_SRC does not exist." >&2
    echo "[build.sh] On Git Bash the path uses forward slashes with /c/... prefix," >&2
    echo "[build.sh] e.g. /c/GitRepoNovotonSphinx/cscart_v4.20.1" >&2
    echo "[build.sh] On WSL use /mnt/c/..." >&2
    exit 2
fi

IMAGE_BASE="${IMAGE_BASE:-ghcr.io/puboop2018/novotonbun-test-db}"
CSCART_VERSION="${CSCART_VERSION:-4.20.1}"
SKIP_PUSH="${SKIP_PUSH:-0}"

WORK="$(mktemp -d -t novoton-testdb-XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

echo "[build.sh] Workdir:   $WORK"
echo "[build.sh] Repo root: $REPO_ROOT"
echo "[build.sh] CS-Cart:   $CSCART_SRC"

# ── 1. Materialise CS-Cart into workdir ─────────────────────────────────────
# Support either a .zip archive or a pre-extracted directory.
case "$CSCART_SRC" in
    *.zip)
        echo "[build.sh] Unzipping $CSCART_SRC ..."
        unzip -q "$CSCART_SRC" -d "$WORK/cscart"
        ;;
    *)
        if [ -d "$CSCART_SRC" ]; then
            echo "[build.sh] Copying extracted CS-Cart dir ..."
            # Avoid dereferencing symlinks; preserve file modes.
            cp -a "$CSCART_SRC" "$WORK/cscart"
        else
            echo "[build.sh] FATAL: CSCART_SRC is neither a .zip nor a directory: $CSCART_SRC" >&2
            exit 2
        fi
        ;;
esac

# Normalize: CS-Cart zips often extract to a single top-level folder. If
# $WORK/cscart doesn't contain install/index.php at its root, look one level
# deeper and hoist.
if [ ! -f "$WORK/cscart/install/index.php" ]; then
    inner=$(find "$WORK/cscart" -maxdepth 2 -type f -name 'index.php' -path '*/install/*' -printf '%h\n' | head -n1)
    if [ -n "$inner" ]; then
        parent=$(dirname "$inner")
        echo "[build.sh] Hoisting CS-Cart from $parent to workdir root..."
        mv "$parent" "$WORK/cscart.tmp"
        rm -rf "$WORK/cscart"
        mv "$WORK/cscart.tmp" "$WORK/cscart"
    fi
fi

# ── 2. Drop the three addons into app/addons/ ────────────────────────────────
for addon in novoton_holidays travel_core sphinx_holidays; do
    case "$addon" in
        novoton_holidays) src="$REPO_ROOT/addon-novoton-holidays/app/addons/novoton_holidays" ;;
        travel_core)      src="$REPO_ROOT/addon-travel-core/app/addons/travel_core" ;;
        sphinx_holidays)  src="$REPO_ROOT/addon-sphinx-holidays/app/addons/sphinx_holidays" ;;
    esac
    echo "[build.sh] Copying $addon..."
    cp -R "$src" "$WORK/cscart/app/addons/$addon"
done

# ── 3. Boot a throwaway MySQL + run the CS-Cart installer ────────────────────
NET="novoton-testdb-build-$$"
docker network create "$NET" >/dev/null

MYSQL_CID=$(docker run -d --rm --network "$NET" --network-alias db \
    -e MYSQL_DATABASE=cscart \
    -e MYSQL_USER=cscart \
    -e MYSQL_PASSWORD=cscart \
    -e MYSQL_ROOT_PASSWORD=root \
    mysql:8.0)
trap 'docker kill "$MYSQL_CID" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

echo "[build.sh] Waiting for MySQL to become ready..."
for i in $(seq 1 60); do
    if docker exec "$MYSQL_CID" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

echo "[build.sh] Running CS-Cart CLI installer..."
docker run --rm --network "$NET" \
    -v "$WORK/cscart:/var/www/html" \
    -e LICENSE_KEY="$CSCART_LICENSE_KEY" \
    -w /var/www/html \
    php:8.3-cli bash -c '
        set -e
        docker-php-ext-install pdo_mysql mysqli >/dev/null 2>&1 || true
        php install/index.php \
            --db_host=db \
            --db_name=cscart \
            --db_user=cscart \
            --db_password=cscart \
            --admin_username=admin \
            --admin_password=admin \
            --admin_email=admin@example.com \
            --license_number="$LICENSE_KEY" \
            --non-interactive \
            || { echo "Installer failed — adapt flags to match your CS-Cart 4.20.1 CLI"; exit 1; }
    '

echo "[build.sh] Enabling addons..."
for addon in novoton_holidays travel_core sphinx_holidays; do
    docker run --rm --network "$NET" \
        -v "$WORK/cscart:/var/www/html" \
        -w /var/www/html \
        php:8.3-cli php admin.php --dispatch=addons.update addon="$addon" status=A \
        || echo "[build.sh] Warning: could not enable $addon via CLI; may need manual SQL" >&2
done

# ── 4. Dump and compress ─────────────────────────────────────────────────────
echo "[build.sh] Dumping cscart schema + data..."
docker exec "$MYSQL_CID" mysqldump \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    -uroot -proot cscart \
    | gzip -9 > "$HERE/seed.sql.gz"

HASH=$(sha256sum "$HERE/seed.sql.gz" | awk '{print $1}' | head -c 12)
TAG="${CSCART_VERSION}-${HASH}"
IMAGE="${IMAGE_BASE}:${TAG}"

# ── 5. Build the image ───────────────────────────────────────────────────────
echo "[build.sh] Building $IMAGE..."
docker build -t "$IMAGE" -t "${IMAGE_BASE}:latest" "$HERE"

# ── 6. Paranoia: verify the license key is NOT in the image layers ───────────
echo "[build.sh] Auditing image for license-key leakage..."
if docker save "$IMAGE" | tar -xO | grep -aFq "$CSCART_LICENSE_KEY"; then
    echo "[build.sh] FATAL: license key found in image layers; aborting push." >&2
    exit 2
fi

# ── 7. Push (unless SKIP_PUSH=1) ─────────────────────────────────────────────
if [ "$SKIP_PUSH" = "1" ]; then
    echo "[build.sh] SKIP_PUSH=1 — not pushing."
else
    echo "[build.sh] Pushing $IMAGE ..."
    docker push "$IMAGE"
    docker push "${IMAGE_BASE}:latest"
fi

# ── 8. Clean up the seed dump from disk (the image has it) ──────────────────
rm -f "$HERE/seed.sql.gz"

echo "[build.sh] Done."
echo "[build.sh] Update docker-compose.test.yml and .github/workflows/ci.yml"
echo "[build.sh] to reference image tag: $TAG"
