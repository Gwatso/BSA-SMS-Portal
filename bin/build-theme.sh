#!/usr/bin/env bash
# Builds dist/bsa-portal-code-<version>.zip: the child theme's text files (PHP, CSS, notes)
# and its cover image (screenshot.png).
# Font binaries are not in the zip; the server downloads them from the URLs in
# bsa-portal/assets/fonts/SOURCES.txt and must match the listed SHA-256 values.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^Version:[[:space:]]*\(.*\)$/\1/p' bsa-portal/style.css)
find bsa-portal -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
mkdir -p dist
out="dist/bsa-portal-code-${version}.zip"
rm -f "$out"
zip -qr "$out" bsa-portal -x '*.woff2'
echo "$out"
