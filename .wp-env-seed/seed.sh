#!/usr/bin/env bash
# Pre-seed Avacy plugin WP options from `.wp-env-seed/local.env`.
# Called by `lifecycleScripts.afterStart` in `.wp-env.json`.
# Safe to call repeatedly — `wp option update` is idempotent.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${HERE}/local.env"

if [ ! -f "${ENV_FILE}" ]; then
  cat <<EOF
ℹ️  No .wp-env-seed/local.env — plugin runs unconfigured.
    To pre-seed on start: cp .wp-env-seed/local.env.example .wp-env-seed/local.env
    then fill with values from your test tenant.
EOF
  exit 0
fi

# shellcheck disable=SC1090
set -a; source "${ENV_FILE}"; set +a

update_option() {
  local option_key="$1"
  local option_value="$2"
  if [ -z "${option_value}" ]; then
    return 0
  fi
  echo "→ ${option_key} = ${option_value}"
  yarn wp-env run cli wp option update "${option_key}" "${option_value}" >/dev/null 2>&1
}

echo "🌱 Seeding Avacy plugin options from local.env..."
update_option "avacy_tenant"                  "${AVACY_TENANT:-}"
update_option "avacy_webspace_key"            "${AVACY_WEBSPACE_KEY:-}"
update_option "avacy_api_token"               "${AVACY_API_TOKEN:-}"
update_option "avacy_show_banner"             "${AVACY_SHOW_BANNER:-}"
update_option "avacy_enable_preemptive_block" "${AVACY_ENABLE_PREEMPTIVE_BLOCK:-}"
echo "✅ Seed complete."
