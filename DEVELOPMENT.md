# Avacy WP Plugin — Development

This directory contains the Avacy WordPress plugin. It is mirrored to the public repo
[`jump-group/avacy-wp`](https://github.com/jump-group/avacy-wp) (read-only) via a GitHub
Action, and from there released to WordPress.org via SVN.

**Canonical source:** this directory in the monorepo.
**Do not edit the public repo directly** — commits there will be overwritten on the next sync.

---

## Prerequisites

- Docker Desktop running
- `yarn install` at monorepo root (this installs `@wordpress/env`)

## Start the local WordPress

From the monorepo root:

```bash
yarn wp:start
```

Then open [http://localhost:8888](http://localhost:8888).

**Admin credentials** (default `wp-env`):

- URL: `http://localhost:8888/wp-admin`
- User: `admin`
- Password: `password`

## What's pre-installed

Defined in [`.wp-env.json`](./.wp-env.json):

- **Avacy CMP** (`avacy` slug) — this plugin (mounted from the current directory to `wp-content/plugins/avacy/` via `mappings`, auto-activated via `lifecycleScripts.afterStart`)
- **[WP Consent API](https://wordpress.org/plugins/wp-consent-api/)** — required for the Bronze Tier Google CMP certification (PBI Sprint 24)
- **[Google Site Kit](https://wordpress.org/plugins/google-site-kit/)** — a WP Consent API subscriber, used to validate consent flow end-to-end

## Other commands

| Command          | Effect                                        |
| ---------------- | --------------------------------------------- |
| `yarn wp:stop`   | Stop containers (preserves data)              |
| `yarn wp:clean`  | Wipe DB and re-provision (keep Docker images) |
| `yarn wp:destroy`| Full tear-down including images               |

## Adding other test plugins

Edit [`.wp-env.json`](./.wp-env.json), add to the `plugins` array, then `yarn wp:clean && yarn wp:start`.

Examples:

```json
"plugins": [
  ".",
  "https://downloads.wordpress.org/plugin/wp-consent-api.zip",
  "https://downloads.wordpress.org/plugin/google-site-kit.zip",
  "https://downloads.wordpress.org/plugin/woocommerce.zip"
]
```

## Public repo sync

On every push to `development` or `master` that touches `services/wp-plugin/**`, the
GH Action [`sync-wp-plugin-public.yml`](../../.github/workflows/sync-wp-plugin-public.yml)
mirrors this directory to `jump-group/avacy-wp`:

- `development` → `development` branch on public
- `master` → `main` branch on public

**Secret required:** `WP_PLUGIN_SYNC_TOKEN` — a fine-grained GitHub PAT with:

- Repo access: `jump-group/avacy-wp` only
- Permissions: `Contents: Read and write`, `Metadata: Read-only`
- Expiration: 1 year (rotate annually)

To generate and install:

1. Generate at <https://github.com/settings/personal-access-tokens/new>
2. Set the secret:
   ```bash
   gh secret set WP_PLUGIN_SYNC_TOKEN --repo jump-group/avacy --body "<token>"
   ```

## Release to WordPress.org (manual, for now)

1. Bump `Stable tag` in [`readme.md`](./readme.md)
2. Bump version header in [`avacy.php`](./avacy.php)
3. Commit, push, let the sync mirror to the public repo
4. From the public repo, push to SVN trunk + create the version tag (same flow as before)

Automating the SVN deploy is tracked as a separate chore; see `openspec/changes/migrate-wp-plugin-to-monorepo/tasks.md` section 7.

## Interaction with the main monorepo

The wp-env stack is isolated from the main Avacy Docker stack:

| Stack | Ports | Network | Notes |
| --- | --- | --- | --- |
| Main monorepo (`yarn start`) | 80/443 via Traefik | avacy | api, frontend, iab-docs, scan |
| wp-env (`yarn wp:start`) | 8888/8889, MySQL random | wp-env | independent |

**Caveat:** `yarn start` at the monorepo root runs `yarn kill-all-docker` as its first
step, which kills **all** running Docker containers including wp-env. If you already have
wp-env running and then run `yarn start`, re-run `yarn wp:start` afterwards. The reverse
order (`yarn start` then `yarn wp:start`) is safe.

No port or `/etc/hosts` conflicts exist — the two stacks can run in parallel.

## PHP dependencies

The plugin ships `vendor/` committed (standard for WordPress.org distribution). Do not run
`composer install` in a way that modifies `vendor/` layout without coordination.
