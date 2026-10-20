#!/usr/bin/env bash
# Builds dist/snapexpay-whmcs-<version>.zip: WHMCS-root layout plus README.
set -euo pipefail
cd "$(dirname "$0")"
version=$(grep -oP "const VERSION = '\K[^']+" src/modules/gateways/snapexpay/lib/Gateway.php)
php tests/run.php >/dev/null
rm -rf dist && mkdir -p dist/tmp
cp -r src/. dist/tmp/
cp README.md dist/tmp/README.md
# Files must be readable by the web server whatever umask built them.
find dist/tmp -type d -exec chmod 755 {} + && find dist/tmp -type f -exec chmod 644 {} +
(cd dist/tmp && zip -qrX "../snapexpay-whmcs-${version}.zip" .)
rm -rf dist/tmp
echo "dist/snapexpay-whmcs-${version}.zip"
