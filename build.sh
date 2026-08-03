#!/usr/bin/env bash
# Build the distributable PressPilot plugin zip (slug "presspilot") into dist/.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
DIST="$ROOT/dist"; rm -rf "$DIST"; mkdir -p "$DIST"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

DEST="$TMP/presspilot"
mkdir -p "$DEST"
tar -C "$ROOT" \
  --exclude='./.git' --exclude='./.github' --exclude='./.claude' \
  --exclude='./dist' --exclude='./build.sh' --exclude='./build-dev.sh' \
  --exclude='./.gitignore' --exclude='./dev' \
  -cf - . | tar -C "$DEST" -xf -

( cd "$TMP" && zip -rqX "$DIST/presspilot.zip" "presspilot" )

echo "== built =="; ls -la "$DIST"
