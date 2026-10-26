#!/usr/bin/env bash
# Builds an installable plugin ZIP in build/ (respects .distignore).
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(grep -m1 "Version:" filora.php | sed -E 's/.*Version:[[:space:]]*//')
OUT="build/filora"
rm -rf build
mkdir -p "$OUT"

npm run build --silent
node bin/compile-mo.mjs

# Copy everything except what .distignore lists.
EXCLUDES=()
while IFS= read -r line; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  EXCLUDES+=(--exclude="${line#/}")
done < .distignore
tar -cf - "${EXCLUDES[@]}" --exclude=build . | tar -xf - -C "$OUT"

(cd build && zip -qr "filora-${VERSION}.zip" filora)
echo "build/filora-${VERSION}.zip"
