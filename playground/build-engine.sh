#!/usr/bin/env bash
# Trims the official Playground website build to the engine of one PHP and one WordPress version,
# without the website shell (its index.html loads Google Analytics) and without
# wordpress-static.zip (25 MB): WordPress static files are served one by one instead.
# The .wasm files come out brotli-compressed, as they are uploaded (Content-Encoding: br).
#   build-engine.sh <website-dir> <out-dir>
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=engine.env
. "$HERE/engine.env"
B=$1
O=$2
php=${PLAYGROUND_PHP/./_}
wp=$PLAYGROUND_WP

rm -rf "$O"
mkdir -p "$O/assets" "$O/client" "$O/wp-$wp"
cp "$B/remote.html" "$B/sw.js" "$O/"
cp "$B"/playground-worker-endpoint-blueprints-*.js "$B"/capture-site-thumbnail-*.js "$O/"
rm -f "$O"/*.map
cp "$B"/client/*.js "$O/client/"
for f in "$B"/assets/*; do
  n=$(basename "$f")
  [ -d "$f" ] && continue
  case "$n" in
    *.map) continue ;;
    php_[0-9]_[0-9]-*) [[ "$n" == php_${php}-* ]] || continue ;;
    wp-*.tar-*.zst) [[ "$n" == wp-${wp}.tar-* ]] || continue ;;
  esac
  cp "$f" "$O/assets/"
done
cp -R "$B/wp-$wp/wp-admin" "$B/wp-$wp/wp-content" "$B/wp-$wp/wp-includes" "$B/wp-$wp/wordpress-remote-asset-paths" "$O/wp-$wp/"
# The service worker precaches this list for offline mode: nothing to precache here.
echo '[]' >"$O/assets-required-for-offline-mode.json"

if grep -rlE "googletagmanager|G-SVTNFCP8T7" "$O" --include=*.html --include=*.js; then
  echo "build-engine: a Google tag is left in the engine" >&2
  exit 1
fi

node -e '
const fs = require("node:fs"), zlib = require("node:zlib");
for (const f of process.argv.slice(1)) {
  const raw = fs.readFileSync(f);
  fs.writeFileSync(f, zlib.brotliCompressSync(raw, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11, [zlib.constants.BROTLI_PARAM_SIZE_HINT]: raw.length } }));
}' "$O"/assets/*.wasm

printf '{"sha":"%s","php":"%s","wp":"%s"}\n' "$PLAYGROUND_SHA" "$PLAYGROUND_PHP" "$PLAYGROUND_WP" >"$O/engine.json"
du -sh "$O"
