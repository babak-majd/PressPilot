#!/usr/bin/env bash
# Build the distributable PressPilot plugin zip (slug "presspilot") into dist/.
#
# One build with every capability. The dangerous ones (PHP exec, plugin install,
# raw SQL, admin-ajax) are NOT stripped — they are gated behind the time-limited,
# admin-only opt-in toggles on the Permissions screen. The GitHub self-updater is
# the only thing excluded, because the Update URI mechanism is not allowed in the
# WordPress.org directory.
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
