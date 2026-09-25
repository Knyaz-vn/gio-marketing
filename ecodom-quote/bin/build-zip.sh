#!/usr/bin/env bash
# Builds an installable ecodom-quote.zip with dompdf bundled (vendor/).
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
tmp="$(mktemp -d)"
mkdir "$tmp/ecodom-quote"
cp -r ecodom-quote.php readme.txt composer.json includes assets templates languages vendor sample-models.csv "$tmp/ecodom-quote/"
( cd "$tmp" && zip -rq ecodom-quote.zip ecodom-quote -x '*/.git/*' '*/.github/*' '*/tests/*' '*/docs/*' '*.md' '*/phpunit*' )
mv "$tmp/ecodom-quote.zip" ./ecodom-quote.zip
rm -rf "$tmp"
echo "Built $(pwd)/ecodom-quote.zip"
