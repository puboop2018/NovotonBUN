#!/usr/bin/env bash
#
# Sync addon files from app/addons/{name}/ to addon-{name}/app/addons/{name}/
# and regenerate manifests.
#
# The addon-* directories are distribution mirrors that contain the full
# CS-Cart addon structure for deployment.
#
# Usage: ./scripts/sync_mirrors.sh [addon_name]

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPT_DIR="$REPO_ROOT/scripts"

sync_addon() {
    local addon_name="$1"
    local source_dir="$REPO_ROOT/app/addons/$addon_name"
    # Mirror directories use hyphens (addon-travel-core) while addon dirs use underscores (travel_core)
    local addon_hyphen="${addon_name//_/-}"
    local mirror_dir="$REPO_ROOT/addon-$addon_hyphen/app/addons/$addon_name"

    if [ ! -d "$source_dir" ]; then
        echo "Error: source not found: $source_dir" >&2
        return 1
    fi

    if [ ! -d "$mirror_dir" ]; then
        echo "Mirror directory not found, skipping: $mirror_dir"
        return 0
    fi

    # Remove old mirror contents and copy fresh
    rm -rf "$mirror_dir"
    cp -a "$source_dir" "$mirror_dir"

    echo "Synced: $source_dir -> $mirror_dir"
}

ADDONS=("travel_core" "novoton_holidays" "sphinx_holidays")

if [ $# -gt 0 ]; then
    sync_addon "$1"
else
    for addon in "${ADDONS[@]}"; do
        sync_addon "$addon"
    done
fi

# Regenerate manifests
echo
echo "Regenerating manifests..."
bash "$SCRIPT_DIR/generate_manifest.sh" ${1:+"$1"}

echo
echo "Done. Run ./scripts/verify_manifest.sh to verify."
