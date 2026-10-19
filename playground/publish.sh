#!/usr/bin/env bash
# Publishes the WordPress test site of wp-demo.avacy-cdn.com (infra/wp-demo): the Playground
# engine pinned in engine.env, the entry page, and a snapshot of
# blueprints/google-cmp-audit-public.json with the Avacy plugin of this working copy.
# The engine is rebuilt only when the pin changes; otherwise it is read back from the bucket.
#
#   BUCKET=... DISTRIBUTION=... services/wp-plugin/playground/publish.sh [--dry-run]
#
# Needs aws, gh (to download the official build), jq, node with playwright and Chromium.
# --dry-run builds everything in $WORK and uploads nothing. PLAYGROUND_WEBSITE_DIR: an extracted
# website build of the pinned run (dist/packages/playground/wasm-wordpress-net), not downloaded.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(dirname "$HERE")"
# shellcheck source=engine.env
. "$HERE/engine.env"
: "${BUCKET:?}" "${DISTRIBUTION:?}"
DRY_RUN=false
[ "${1:-}" = "--dry-run" ] && DRY_RUN=true
DEST="s3://${BUCKET}/wp-playground"
WORK="${WORK:-$(mktemp -d)}"
ROOT="$WORK/root"
SHORT="public, max-age=300"
IMMUTABLE="public, max-age=31536000, immutable"

# 1. Engine
published=$(aws s3 cp "$DEST/engine.json" - 2>/dev/null | jq -r .sha || true)
engine_changed=false
if [ "$published" = "$PLAYGROUND_SHA" ]; then
  echo "engine ${PLAYGROUND_SHA:0:7} already published: reading it back"
  aws s3 sync --no-progress --only-show-errors "$DEST/" "$ROOT/" \
    --exclude "index.html" --exclude "blueprint.json" --exclude "site.zip"
else
  echo "engine ${published:-none} -> ${PLAYGROUND_SHA:0:7}: building it from run $PLAYGROUND_RUN_ID"
  engine_changed=true
fi
if [ "$engine_changed" = true ] && [ -n "${PLAYGROUND_WEBSITE_DIR:-}" ]; then
  # Local runs: the website build already extracted, same run.
  "$HERE/build-engine.sh" "$PLAYGROUND_WEBSITE_DIR" "$ROOT"
elif [ "$engine_changed" = true ]; then
  id=$(gh api "repos/WordPress/wordpress-playground/actions/runs/$PLAYGROUND_RUN_ID/artifacts" \
    --jq '.artifacts[] | select(.name == "playground-website" and (.expired | not)) | .id')
  [ -n "$id" ] || { echo "::error::no playground-website artifact left on run $PLAYGROUND_RUN_ID: pin a newer run in engine.env"; exit 1; }
  head=$(gh api "repos/WordPress/wordpress-playground/actions/runs/$PLAYGROUND_RUN_ID" --jq .head_sha)
  [ "$head" = "$PLAYGROUND_SHA" ] || { echo "::error::run $PLAYGROUND_RUN_ID built $head, not $PLAYGROUND_SHA"; exit 1; }
  mkdir -p "$WORK/build"
  gh api "repos/WordPress/wordpress-playground/actions/artifacts/$id/zip" >"$WORK/build/website.zip"
  unzip -q -o "$WORK/build/website.zip" -d "$WORK/build" && rm "$WORK/build/website.zip"
  tar xzf "$WORK/build/wasm-wordpress-net.tar.gz" -C "$WORK/build" && rm "$WORK/build/wasm-wordpress-net.tar.gz"
  "$HERE/build-engine.sh" "$WORK/build/dist/packages/playground/wasm-wordpress-net" "$ROOT"
  rm -rf "$WORK/build"
fi

# 2. Plugins: Avacy as the WordPress.org deploy packs it (services/wp-plugin/.github/workflows/deploy.yml),
# the other two at their latest stable release.
mkdir -p "$ROOT/plugins" "$WORK/avacy/avacy"
(cd "$PLUGIN" && cp -R avacy.php readme.md changelog.md composer.json composer.lock package.json rector.php \
  src vendor assets styles languages "$WORK/avacy/avacy/")
(cd "$WORK/avacy" && rm -f "$ROOT/plugins/avacy.zip" && zip -qr "$ROOT/plugins/avacy.zip" avacy)
for slug in wp-consent-api google-site-kit; do
  curl -fsSL -o "$ROOT/plugins/$slug.zip" "https://downloads.wordpress.org/plugin/$slug.latest-stable.zip"
done

# 3. Snapshot
cp "$HERE/index.html" "$ROOT/index.html"
node "$HERE/snapshot.mjs" "$PLUGIN/blueprints/google-cmp-audit-public.json" "$ROOT"

if [ "$DRY_RUN" = true ]; then
  echo "dry run: built in $ROOT, nothing uploaded"
  exit 0
fi

# 4. Upload. Hashed engine files for a year; the rest for minutes, and invalidated.
if [ "$engine_changed" = true ]; then
  aws s3 sync --no-progress --only-show-errors "$ROOT/assets/" "$DEST/assets/" --exclude "*.wasm" --cache-control "$IMMUTABLE"
  for w in "$ROOT"/assets/*.wasm; do
    aws s3 cp --no-progress --only-show-errors "$w" "$DEST/assets/$(basename "$w")" \
      --content-type application/wasm --content-encoding br --cache-control "$IMMUTABLE"
  done
  aws s3 sync --no-progress --only-show-errors "$ROOT/" "$DEST/" --cache-control "$SHORT" \
    --exclude "assets/*" --exclude "plugins/*" --exclude "install.json" --exclude "engine.json" \
    --exclude "index.html" --exclude "blueprint.json" --exclude "site.zip"
fi
aws s3 cp --no-progress --only-show-errors "$ROOT/site.zip" "$DEST/site.zip" --content-type application/zip --cache-control "$SHORT"
aws s3 cp --no-progress --only-show-errors "$ROOT/blueprint.json" "$DEST/blueprint.json" --content-type application/json --cache-control "$SHORT"
aws s3 cp --no-progress --only-show-errors "$ROOT/index.html" "$DEST/index.html" --content-type "text/html; charset=utf-8" --cache-control "$SHORT"
# Last: a run that stops halfway rebuilds the engine next time.
[ "$engine_changed" = false ] || aws s3 cp --no-progress --only-show-errors "$ROOT/engine.json" "$DEST/engine.json" --content-type application/json --cache-control "$SHORT"

if [ "$engine_changed" = true ]; then paths=("/*"); else paths=("/" "/index.html" "/blueprint.json" "/site.zip"); fi
aws cloudfront create-invalidation --distribution-id "$DISTRIBUTION" --paths "${paths[@]}" --query Invalidation.Id --output text
