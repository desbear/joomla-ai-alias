#!/bin/sh
# Builds the installable package into dist/
set -e

cd "$(dirname "$0")"
version=$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' aialias.xml | head -1)
out="dist/plg_system_aialias-$version.zip"

mkdir -p dist
rm -f "$out"
zip -qr "$out" aialias.xml services src media language -x '*.DS_Store'

echo "$out"
