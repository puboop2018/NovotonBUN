#!/usr/bin/env bash
#
# Symlink each addon's DEPLOY artifacts from the read-only /repo mount into the
# CS-Cart docroot, so the store runs the repo's live code (edit in the host IDE,
# refresh the browser). Per-addon-id leaf links merge cleanly into CS-Cart's own
# app/addons, design, js and var/langs trees.
#
# Only real artifacts are linked — the [ -e ] guard silently skips what an addon
# doesn't ship (sphinx has no nova_theme / theme CSS; fgo is backend-only + EN
# only). Dev-only trees are excluded by construction: react-src lives OUTSIDE
# app/addons/ (the built bundle is already in js/addons/travel_core), and each
# addon's tests/vendor/composer sit unused under the app dir CS-Cart ignores.
#
# Re-runnable: drop a new addon into the map and re-run. Safe to run on every
# container start.
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
REPO="${REPO:-/repo}"

# addon-id -> repo top-level dir
declare -A ADDONS=(
    [travel_core]=addon-travel-core
    [novoton_holidays]=addon-novoton-holidays
    [sphinx_holidays]=addon-sphinx-holidays
    [fgo_invoicing]=addon-fgo-invoicing
)

link() { # $1 = source under /repo, $2 = destination under docroot
    local src="$1" dest="$2"
    [ -e "$src" ] || return 0
    mkdir -p "$(dirname "$dest")"
    rm -rf "$dest"
    ln -s "$src" "$dest"
    echo "    $dest -> $src"
}

for id in "${!ADDONS[@]}"; do
    base="$REPO/${ADDONS[$id]}"
    echo "[link-addons] $id"

    link "$base/app/addons/$id"                           "$DOCROOT/app/addons/$id"
    link "$base/design/backend/templates/addons/$id"      "$DOCROOT/design/backend/templates/addons/$id"
    link "$base/design/backend/css/addons/$id"            "$DOCROOT/design/backend/css/addons/$id"
    link "$base/design/backend/js/addons/$id"             "$DOCROOT/design/backend/js/addons/$id"
    link "$base/design/backend/mail/templates/addons/$id" "$DOCROOT/design/backend/mail/templates/addons/$id"
    link "$base/design/backend/media/images/addons/$id"   "$DOCROOT/design/backend/media/images/addons/$id"

    for theme in responsive nova_theme; do
        # Only link into a theme the kit actually ships.
        [ -d "$DOCROOT/design/themes/$theme" ] || continue
        link "$base/design/themes/$theme/templates/addons/$id" "$DOCROOT/design/themes/$theme/templates/addons/$id"
        link "$base/design/themes/$theme/css/addons/$id"       "$DOCROOT/design/themes/$theme/css/addons/$id"
    done

    link "$base/js/addons/$id" "$DOCROOT/js/addons/$id"

    for lang in en ro; do
        link "$base/var/langs/$lang/addons/$id.po" "$DOCROOT/var/langs/$lang/addons/$id.po"
    done
done

echo "[link-addons] done"
