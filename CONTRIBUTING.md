# Contributing to the Avacy WordPress Plugin

> **This repository is a read-only mirror.**
> All development happens in the main Avacy monorepo at `jump-group/avacy`,
> under `services/wp-plugin/`.

## I want to report a bug or request a feature

Please [open an issue](https://github.com/jump-group/avacy-wp/issues) on this
repository. The team triages issues here.

## I want to contribute code

1. Work in the **monorepo** (`jump-group/avacy`), branch off `development`
2. Edit files under `services/wp-plugin/`
3. Open a PR against `development` in the monorepo
4. After merge, the `sync-wp-plugin-public.yml` workflow automatically mirrors
   changes to this repository

**Do not push commits directly to this repository.** They will be overwritten
on the next sync from the monorepo.

## Release flow

See [`DEVELOPMENT.md`](./DEVELOPMENT.md) in the monorepo for the full release
process (version bump → sync → tag → automated SVN deploy to WordPress.org).
