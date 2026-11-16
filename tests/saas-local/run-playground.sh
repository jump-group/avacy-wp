#!/usr/bin/env bash
# Playground per il test plugin → SaaS locale. Lo lancia run.sh, che prepara SAAS_RUN_DIR
# (blueprint e zip di Contact Form 7 in cache). Le mu-plugin del blocco sono montate PRIMA
# dell'installazione: il blocco e' attivo da subito.
set -euo pipefail
PORT="${1:?porta}"
RUN_DIR="${SAAS_RUN_DIR:?manca SAAS_RUN_DIR: usa run.sh}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"
# versione fissa (la 3.1.19 del node_modules risponde a caso 500, vedi scripts/playground.sh);
# prefer-offline: se il pacchetto e' gia' nella cache di npm non si contatta il registro
exec env npm_config_prefer_offline=true npm_config_update_notifier=false npx --yes @wp-playground/cli@3.1.58 server \
  --blueprint="${RUN_DIR}/blueprint.json" \
  --port="${PORT}" \
  --wp=7.1.4 \
  --mount-before-install="${HERE}/mu-plugins:/wordpress/wp-content/mu-plugins" \
  --mount-before-install="${HERE}/guard-log:/wordpress/wp-content/avacy-guard-log" \
  --mount-before-install="${RUN_DIR}/cache:/avacy-cache" \
  --mount="${PLUGIN_DIR}:/wordpress/wp-content/plugins/avacy"
