#!/usr/bin/env bash
# Build the distributable PressPilot plugin zip (slug "presspilot") into dist/.
#
# One build, a fixed set of vetted operations: no arbitrary PHP, no raw SQL, no
# arbitrary DB writes, no admin-ajax dispatch, no REST proxy. The GitHub self-updater
# is excluded because the Update URI mechanism is not allowed in the WordPress.org
# directory.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
DIST="$ROOT/dist"; rm -rf "$DIST"; mkdir -p "$DIST"

EXCLUDES=(
  --exclude='./.git' --exclude='./.github' --exclude='./.claude' --exclude='./.kilo'
  --exclude='./dist' --exclude='./build.sh' --exclude='./build-dev.sh'
  --exclude='./.gitignore' --exclude='./dev'
  --exclude='./docker-compose.yml' --exclude='./test-plugin.sh' --exclude='./README.md'
  --exclude='./assets'
  --exclude='./includes/class-pp-github-updater.php'
)

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
DEST="$TMP/presspilot"
mkdir -p "$DEST"
tar -C "$ROOT" "${EXCLUDES[@]}" -cf - . | tar -C "$DEST" -xf -
( cd "$TMP" && zip -rqX "$DIST/presspilot.zip" "presspilot" )

echo "== built =="; ls -la "$DIST"
