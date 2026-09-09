#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
mkdir -p dist
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT HUP INT TERM
mkdir "$stage/dzen-chat"
cp dzen-chat.php uninstall.php readme.txt "$stage/dzen-chat/"
cp -R src assets "$stage/dzen-chat/"
archive="$PWD/dist/dzen-chat-0.1.1.zip"
rm -f "$archive"
cd "$stage"
zip -q -r "$archive" dzen-chat
