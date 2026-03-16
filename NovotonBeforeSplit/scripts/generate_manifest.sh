#!/usr/bin/env bash
#
# Generate manifest.json for each travel addon.
# Lists all PHP/TPL/CSS/JS files with SHA-256 checksums.
#
# Usage: ./scripts/generate_manifest.sh [addon_name]
#   If addon_name is provided, only generates for that addon.
#   Otherwise, generates for all three addons.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ADDONS_DIR="$REPO_ROOT/app/addons"

generate_manifest() {
    local addon_name="$1"
    local addon_dir="$ADDONS_DIR/$addon_name"

    if [ ! -d "$addon_dir" ]; then
        echo "Error: addon directory not found: $addon_dir" >&2
        return 1
    fi

    # Read version from addon.xml if it exists
    local version="unknown"
    if [ -f "$addon_dir/addon.xml" ]; then
        version=$(grep -oP '<version>\K[^<]+' "$addon_dir/addon.xml" 2>/dev/null || echo "unknown")
    fi

    local manifest_file="$addon_dir/manifest.json"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    # Use python3 for reliable JSON generation
    python3 -c "
import os, hashlib, json
from datetime import datetime

addon_dir = '$addon_dir'
addon_name = '$addon_name'
version = '$version'
timestamp = '$timestamp'

extensions = {'.php', '.tpl', '.css', '.js', '.xml', '.po', '.json'}
files = {}

for root, dirs, filenames in os.walk(addon_dir):
    for fname in sorted(filenames):
        if fname == 'manifest.json':
            continue
        _, ext = os.path.splitext(fname)
        if ext not in extensions:
            continue
        full_path = os.path.join(root, fname)
        rel_path = os.path.relpath(full_path, addon_dir)
        with open(full_path, 'rb') as f:
            checksum = hashlib.sha256(f.read()).hexdigest()
        files[rel_path] = f'sha256:{checksum}'

manifest = {
    'addon': addon_name,
    'version': version,
    'generated': timestamp,
    'files': dict(sorted(files.items()))
}

with open('$manifest_file', 'w') as f:
    json.dump(manifest, f, indent=2)
    f.write('\n')
"

    echo "Generated: $manifest_file ($addon_name v$version)"
}

ADDONS=("travel_core" "novoton_holidays" "sphinx_holidays")

if [ $# -gt 0 ]; then
    generate_manifest "$1"
else
    for addon in "${ADDONS[@]}"; do
        generate_manifest "$addon"
    done
fi
