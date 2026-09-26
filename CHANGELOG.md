# Changelog

All notable changes to `media-library-for-laravel` will be documented in this file.

## Unreleased

Initial feature set:

- Model media buckets via the `HasMedia` contract and `InteractsWithMedia` trait, plus global
  (ownerless) media through the `Media` facade — all in one polymorphic `media` table.
- Multi-disk storage on any Laravel disk, with a configurable default and separate disks for
  originals and variants.
- Image variants (image-only) via `ext-imagick` with an `ext-gd` fallback; synchronous by default
  with per-variant/bucket/config queue opt-in.
- Private-media URLs through a single `temporaryUrl()` API that presigns natively or falls back to
  a signed streaming route, plus inline/range streaming and downloads.
- Move and copy media across disks, models, and buckets.
- Content-addressable deduplication with refcount-guarded delete/move, checksum integrity
  verification, and the `media:verify` command.
- ThumbHash and Blurhash LQIP placeholders and responsive `srcset` helpers.
- Draft (token-bound) media, bucket-derived validation rules, replace-in-place, and
  attach-existing-by-reference.
- A pluggable CDN URL generator with cache-busting.
- Artisan commands: `media:regenerate`, `media:clean`, `media:clear`, `media:verify`, and
  `media:prune-drafts`.

### Fixed

- A host-bound `Contracts\ImageDriver` is now actually used for variants and placeholders.
