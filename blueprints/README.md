# WordPress Playground Blueprints

Each file describes a reproducible WordPress scenario used for development and testing
of the Avacy CMP plugin. The plugin itself is mounted from the local working copy via
the `--mount` flag of `wp-playground-cli`, so blueprints only install **other** plugins
and set options.

## Run a blueprint locally

From the monorepo root:

```bash
yarn wp:playground baseline           # just the Avacy plugin
yarn wp:playground with-wp-consent-api
yarn wp:playground with-html-forms
```

This starts a Playground server on `http://127.0.0.1:9400` by default (admin auto-login
on first request when `login: true`).

## Available blueprints

| File | What's in it |
| --- | --- |
| `baseline.json` | Plugin Avacy only, admin logged-in |
| `with-wp-consent-api.json` | Avacy + [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) + [Google Site Kit](https://wordpress.org/plugins/google-site-kit/), `googlecmp` webspace (Google CMP Bronze Tier test tenant) — plugin Avacy mounted locally |
| `with-wp-consent-api-public.json` | Same as above, but installs the Avacy plugin from WP.org via `installPlugin`. Shareable on `playground.wordpress.net` |
| `with-html-forms.json` | Avacy + [HTML Forms](https://wordpress.org/plugins/html-forms/), default test webspace |

## Default webspace

Blueprints that need a configured tenant use the shared Avacy **test-production**
webspace (`test-production|52fd6a7b-32b3-49d7-9092-e8afedb6313f`). It serves a real
banner from the Avacy CDN, so visual and network-level tests work against real assets.

## Share a blueprint as a public URL

Once merged to `development`, each blueprint is available at a stable URL on
[playground.wordpress.net](https://playground.wordpress.net/):

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jump-group/avacy/development/services/wp-plugin/blueprints/<name>.json
```

**Note:** blueprints ending in `-public.json` install Avacy via `installPlugin` from
WP.org, so they work as standalone URLs on playground.wordpress.net. The other
blueprints assume the plugin is mounted from the local working copy via
`yarn wp:playground` and will not work when shared as a raw URL.

Example public URL for the WP Consent API scenario:

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jump-group/avacy/development/services/wp-plugin/blueprints/with-wp-consent-api-public.json
```
