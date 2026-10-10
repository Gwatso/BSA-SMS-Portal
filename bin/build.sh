#!/usr/bin/env bash
# Builds dist/bsa-school-management-<version>.zip after linting every PHP file.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n "s/^define( 'BSA_SM_VERSION', '\(.*\)' );/\1/p" bsa-school-management/bsa-school-management.php)
find bsa-school-management -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
mkdir -p dist
rm -f "dist/bsa-school-management-${version}.zip"
zip -qr "dist/bsa-school-management-${version}.zip" bsa-school-management -x '*/tests/*'
echo "dist/bsa-school-management-${version}.zip"
