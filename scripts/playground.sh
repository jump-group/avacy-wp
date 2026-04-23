#!/usr/bin/env bash
# Run a WordPress Playground instance with the local plugin mounted as `avacy`.
# Usage: ./scripts/playground.sh [blueprint] [port]
#   blueprint : file name (without .json) under ./blueprints/    (default: baseline)
#   port      : TCP port                                         (default: 9400)

set -euo pipefail

BLUEPRINT="${1:-baseline}"
PORT="${2:-9400}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BLUEPRINT_FILE="${PLUGIN_DIR}/blueprints/${BLUEPRINT}.json"

if [ ! -f "${BLUEPRINT_FILE}" ]; then
  echo "✘ Blueprint not found: ${BLUEPRINT_FILE}" >&2
  echo "Available blueprints:" >&2
  ls -1 "${PLUGIN_DIR}/blueprints/"*.json 2>/dev/null | xargs -n1 basename | sed 's/\.json$//' | sed 's/^/  - /' >&2
  exit 1
fi

echo "🎮 Starting Playground with blueprint '${BLUEPRINT}' on port ${PORT}..."
echo "   Plugin mounted from: ${PLUGIN_DIR}"
echo ""

exec npx --yes wp-playground-cli server \
  --blueprint="${BLUEPRINT_FILE}" \
  --port="${PORT}" \
  --mount="${PLUGIN_DIR}:/wordpress/wp-content/plugins/avacy"
