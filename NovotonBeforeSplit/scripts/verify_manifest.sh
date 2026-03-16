#!/usr/bin/env bash
#
# Verify addon files against their manifest.json checksums.
# Reports missing files, checksum mismatches, and untracked files.
#
# Usage: ./scripts/verify_manifest.sh [addon_name]
#   If addon_name is provided, only verifies that addon.
#   Otherwise, verifies all three addons.
#
# Exit codes:
#   0 = all files verified successfully
#   1 = verification failures found

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ADDONS_DIR="$REPO_ROOT/app/addons"

verify_manifest() {
    local addon_name="$1"
    local addon_dir="$ADDONS_DIR/$addon_name"
    local manifest_file="$addon_dir/manifest.json"

    if [ ! -f "$manifest_file" ]; then
        echo "MISSING manifest: $manifest_file"
        return 1
    fi

    python3 -c "
import os, hashlib, json, sys

addon_dir = '$addon_dir'
addon_name = '$addon_name'
manifest_file = '$manifest_file'

with open(manifest_file) as f:
    data = json.load(f)

files_in_manifest = data.get('files', {})
errors = 0
ok = 0

print(f'Verifying: {addon_name}')

# Check each file in manifest
for rel_path, expected in sorted(files_in_manifest.items()):
    full_path = os.path.join(addon_dir, rel_path)
    if not os.path.isfile(full_path):
        print(f'  MISSING  {rel_path}')
        errors += 1
        continue

    with open(full_path, 'rb') as f:
        actual = f'sha256:{hashlib.sha256(f.read()).hexdigest()}'

    if actual == expected:
        ok += 1
    else:
        print(f'  MISMATCH {rel_path}')
        print(f'           expected: {expected}')
        print(f'           actual:   {actual}')
        errors += 1

# Check for untracked files
extensions = {'.php', '.tpl', '.css', '.js', '.xml', '.po', '.json'}
untracked = []
for root, dirs, filenames in os.walk(addon_dir):
    for fname in sorted(filenames):
        if fname == 'manifest.json':
            continue
        _, ext = os.path.splitext(fname)
        if ext not in extensions:
            continue
        full_path = os.path.join(root, fname)
        rel_path = os.path.relpath(full_path, addon_dir)
        if rel_path not in files_in_manifest:
            untracked.append(rel_path)

total = len(files_in_manifest)
if errors == 0:
    print(f'  OK {ok}/{total} files verified')
else:
    print(f'  FAILED {errors}/{total} files')

if untracked:
    print(f'  WARNING {len(untracked)} untracked files:')
    for u in untracked:
        print(f'    + {u}')

print()
sys.exit(1 if errors > 0 else 0)
"
}

ADDONS=("travel_core" "novoton_holidays" "sphinx_holidays")
TOTAL_ERRORS=0

if [ $# -gt 0 ]; then
    verify_manifest "$1" || TOTAL_ERRORS=$((TOTAL_ERRORS + 1))
else
    for addon in "${ADDONS[@]}"; do
        verify_manifest "$addon" || TOTAL_ERRORS=$((TOTAL_ERRORS + 1))
    done
fi

if [ $TOTAL_ERRORS -gt 0 ]; then
    echo "Verification failed for $TOTAL_ERRORS addon(s)"
    exit 1
else
    echo "All manifests verified successfully"
    exit 0
fi
