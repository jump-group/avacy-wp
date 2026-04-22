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

## Pre-seeding the Avacy plugin config

To avoid re-entering tenant / webspace / token values every time you `yarn wp:clean`,
create a local seed file:

```bash
cp services/wp-plugin/.wp-env-seed/local.env.example \
   services/wp-plugin/.wp-env-seed/local.env
```

Edit `local.env` with values from your test tenant on Avacy. The file is gitignored.

On every `yarn wp:start`, `lifecycleScripts.afterStart` runs `.wp-env-seed/seed.sh`,
which calls `wp option update` for each non-empty variable. Leave a variable empty
to skip that option.

Currently seeded options: `avacy_tenant`, `avacy_webspace_key`, `avacy_api_token`,
`avacy_show_banner`, `avacy_enable_preemptive_block`.

To re-seed without a full restart:

```bash
cd services/wp-plugin && bash .wp-env-seed/seed.sh
```

## Scenario testing with WordPress Playground

For scenario-based testing (matrix of plugin combinations, consent states, etc.) the
plugin uses [WordPress Playground](https://wordpress.github.io/wordpress-playground/).
A Playground instance runs WordPress in Node via PHP-WASM, with the current working
copy of the plugin mounted at `/wordpress/wp-content/plugins/avacy`.

Each scenario is a JSON Blueprint under [`blueprints/`](./blueprints/).

### Run a blueprint locally

From the monorepo root:

```bash
yarn wp:playground baseline              # plugin Avacy only
yarn wp:playground with-wp-consent-api   # + WP Consent API + Google Site Kit
yarn wp:playground with-html-forms       # + HTML Forms + preset webspace
```

Default port is `9400` (second arg overrides: `yarn wp:playground baseline 9500`).

The blueprint is applied on the first incoming request, so expect a ~10–15s warmup
before the front-end is fully ready.

### Available blueprints

See [`blueprints/README.md`](./blueprints/README.md) for the full list and what each
one installs/configures.

### Run Playwright tests against a blueprint

```bash
yarn test:wp
```

This launches **all** matrix blueprints in parallel (currently `with-html-forms` on
`:9400` and `with-wp-consent-api` on `:9401`) via `webServer` and runs the matching
specs in [`tests/`](./tests/). Each project in [`playwright.config.ts`](./playwright.config.ts)
pairs a blueprint with a spec file via `testMatch`. Run a single blueprint with
`yarn workspace @avacy/wp-plugin test --project=<name>`.

[`tests/global-setup.ts`](./tests/global-setup.ts) polls the front-end until Avacy
scripts appear, absorbing the Playground bootstrap delay so individual tests can
start immediately.

**Add a new scenario:**

1. Drop a new `<name>.json` in `blueprints/`
2. Write a `<name>.spec.ts` under `tests/`
3. Expand the `projects` / `webServer` config in [`playwright.config.ts`](./playwright.config.ts) (matrix expansion is tracked as a follow-up chore)

## Adding other test plugins to the wp-env stack

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

## Release to WordPress.org

The deploy to SVN is **automated** via the
[`deploy.yml`](./.github/workflows/deploy.yml) workflow on the **public mirror**
(`jump-group/avacy-wp`). It triggers on pushes to the public repo's **branches**,
not on tags:

- push to `main` → **stable** release: syncs SVN `trunk` and creates `/tags/X.Y.Z/`.
  Bumps `Stable tag` on WP.org → auto-update reaches all installs.
- push to `development` → **beta** release: only replaces `/tags/X.Y.Z-beta/`.
  **Trunk is not touched**, so `Stable tag` on WP.org does not change and existing
  installs keep their current version. The beta ZIP is available at
  `https://downloads.wordpress.org/plugin/avacy.X.Y.Z-beta.zip` for Playground
  testing and a small pilot cohort.

`X.Y.Z` is read from the `Version:` header in [`avacy.php`](./avacy.php).

### Steps to cut a beta

1. **Bump version** in the monorepo if it's a new version line:
   - [`avacy.php`](./avacy.php): `Version: X.Y.Z`
   - [`readme.md`](./readme.md): `Stable tag: X.Y.Z` (bumped now — `Stable tag`
     on WP.org stays on the previously promoted version until you hit main)
2. **Merge to `development`** in the monorepo
3. `sync-wp-plugin-public.yml` mirrors the plugin to `avacy-wp` `development`
4. `deploy.yml` on the public repo replaces `/tags/X.Y.Z-beta/` on SVN

### Steps to promote a beta to stable

1. **Merge `development` → `master`** in the monorepo
2. `sync-wp-plugin-public.yml` mirrors the plugin to `avacy-wp` `main`
3. `deploy.yml` on the public repo:
   - Syncs `trunk` with the current distribution files (explicit file list, no dev tooling)
   - Verifies `avacy.php` is at SVN root
   - Commits `trunk` + creates `/tags/X.Y.Z/`
   - Blocks the run if any `HONEYPOT*` files are present in the build

No manual tagging on the public repo is needed anymore.

### What gets deployed (and what doesn't)

| Deployed to SVN | NOT deployed (dev tooling) |
| --- | --- |
| `avacy.php`, `readme.md`, `changelog.md` | `.wp-env.json`, `.wp-env-seed/` |
| `src/`, `vendor/`, `assets/`, `styles/`, `languages/` | `blueprints/`, `tests/`, `scripts/` |
| `composer.json`, `composer.lock`, `package.json`, `rector.php` | `playwright.config.ts`, `DEVELOPMENT.md`, `node_modules/` |

### SVN credentials

Stored as secrets on the **public repo** `jump-group/avacy-wp`:
- `SVN_USERNAME` / `SVN_PASSWORD` (set 2025-04-01)

### Troubleshooting

If the workflow fails, check the GH Actions log on the public repo:
```bash
gh run list --repo jump-group/avacy-wp --workflow=deploy.yml --limit 5
gh run view <run-id> --repo jump-group/avacy-wp --log
```

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
