#!/usr/bin/env bash
# Regenerate languages/avacy.pot with WP-CLI, the tool translate.wordpress.org uses.
# Name, version and description come from the avacy.php header.
# Needs PHP. WP-CLI is downloaded once into .cache/ and checked against its SHA-512.

set -euo pipefail

WP_CLI_VERSION="2.12.0"
WP_CLI_SHA512="be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHAR="${PLUGIN_DIR}/.cache/wp-cli-${WP_CLI_VERSION}.phar"

if ! command -v php >/dev/null 2>&1; then
  echo "✘ PHP is required to run WP-CLI." >&2
  exit 1
fi

if [ ! -f "${PHAR}" ]; then
  mkdir -p "$(dirname "${PHAR}")"
  curl -fsSL -o "${PHAR}.tmp" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
  if ! echo "${WP_CLI_SHA512}  ${PHAR}.tmp" | shasum -a 512 -c - >/dev/null; then
    rm -f "${PHAR}.tmp"
    echo "✘ WP-CLI checksum mismatch." >&2
    exit 1
  fi
  mv "${PHAR}.tmp" "${PHAR}"
fi

cd "${PLUGIN_DIR}"
php "${PHAR}" i18n make-pot . languages/avacy.pot \
  --slug=avacy --domain=avacy \
  --exclude=vendor,node_modules,tests,test-results,playground,blueprints,scripts,assets/libraries,.cache \
  --skip-js --skip-block-json --skip-theme-json
