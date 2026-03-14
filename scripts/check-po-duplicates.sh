#!/bin/bash
# Check for duplicate language keys in .po files
# Usage: ./scripts/check-po-duplicates.sh [--fix] [addon_name]
#        ./scripts/check-po-duplicates.sh --fix novoton_holidays
#        ./scripts/check-po-duplicates.sh              # checks all addons

FIX_MODE=false
ADDON_FILTER=""
for arg in "$@"; do
    if [[ "$arg" == "--fix" ]]; then
        FIX_MODE=true
    elif [[ -n "$arg" ]]; then
        ADDON_FILTER="$arg"
    fi
done

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

ERRORS=0

if [[ -n "$ADDON_FILTER" ]]; then
    PO_PATTERN="var/langs/*/addons/${ADDON_FILTER}.po"
else
    PO_PATTERN="var/langs/*/addons/*.po"
fi

for file in $PO_PATTERN; do
    [[ -f "$file" ]] || continue
    lang=$(echo "$file" | cut -d'/' -f3)

    # Find duplicate keys
    dups=$(grep -oP 'msgctxt "\K[^"]+' "$file" | sort | uniq -d)

    if [ -n "$dups" ]; then
        dup_count=$(echo "$dups" | wc -l)
        echo -e "${RED}✗ $lang: Found $dup_count duplicate key(s)${NC}"
        echo "$dups" | while read key; do
            count=$(grep -c "msgctxt \"$key\"" "$file")
            echo "    - $key ($count occurrences)"
        done
        ERRORS=$((ERRORS + 1))

        if [ "$FIX_MODE" = true ]; then
            echo -e "${YELLOW}  Fixing duplicates in $file...${NC}"
            python3 - "$file" << 'PYTHON'
import sys
import re

filepath = sys.argv[1]
with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# Find header (everything before first msgctxt)
header_match = re.match(r'^(.*?)\n(?=msgctxt)', content, re.DOTALL)
header = header_match.group(1).rstrip() if header_match else ""

# Find all entries
pattern = r'(msgctxt "([^"]+)"\nmsgid "[^"]*"\nmsgstr "[^"]*"\n*)'
entries = re.findall(pattern, content)

# Keep only last occurrence of each key
seen = {}
for entry_text, key in entries:
    seen[key] = entry_text

# Rebuild file
new_content = header + '\n\n' + '\n\n'.join(seen.values()) + '\n'

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(new_content)

print(f"  Fixed: {len(entries)} -> {len(seen)} entries")
PYTHON
        fi
    else
        total=$(grep -c 'msgctxt "' "$file" || echo 0)
        echo -e "${GREEN}✓ $lang: No duplicates ($total unique keys)${NC}"
    fi
done

echo ""
if [ $ERRORS -gt 0 ]; then
    if [ "$FIX_MODE" = false ]; then
        echo -e "${YELLOW}Run with --fix to automatically remove duplicates${NC}"
    fi
    exit 1
else
    echo -e "${GREEN}All language files are clean!${NC}"
    exit 0
fi
