#!/bin/bash
# Baut das Slackware-Paket dist/zfs-backup-guard-<version>-noarch-1.txz und trägt die MD5 in die .plg ein.
set -euo pipefail
cd "$(dirname "$0")"

NAME=zfs-backup-guard
VERSION=$(sed -n "s/^const VERSION = '\(.*\)';/\1/p" src/usr/local/emhttp/plugins/$NAME/include/bootstrap.php)
[ -n "$VERSION" ] || { echo "VERSION nicht gefunden" >&2; exit 1; }
PKG="$NAME-$VERSION-noarch-1.txz"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp -r src/. "$STAGE/"
# Zeilenenden normalisieren (Windows-Checkout), Rechte setzen
find "$STAGE" -type f \( -name '*.php' -o -name '*.page' -o -name '*.js' -o -name '*.css' -o -name zbg \) -exec sed -i 's/\r$//' {} +
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/usr/local/emhttp/plugins/$NAME/bin/zbg"
if [ -d "$STAGE/usr/local/emhttp/plugins/$NAME/events" ]; then
    find "$STAGE/usr/local/emhttp/plugins/$NAME/events" -type f -exec chmod 755 {} +
fi

mkdir -p dist
tar --owner=0 --group=0 --numeric-owner --sort=name --mtime="@0" -C "$STAGE" -cJf "dist/$PKG" usr
MD5=$(md5sum "dist/$PKG" | cut -d' ' -f1)

sed -i -e "s|<!ENTITY version \".*\">|<!ENTITY version \"$VERSION\">|" \
       -e "s|<!ENTITY md5 \".*\">|<!ENTITY md5 \"$MD5\">|" plugin/$NAME.plg
echo "dist/$PKG  md5=$MD5"
