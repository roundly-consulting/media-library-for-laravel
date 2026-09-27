# Changelog

All notable changes to `media-library-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Named media buckets on any Eloquent model (`HasMedia` + `InteractsWithMedia`) and global media
  with no owner, all in one polymorphic `media` table.
- Files from uploads, requests, URLs, disks, strings, base64 and streams through a fluent
  `addMedia*()` → `toMediaBucket()` builder with names, custom properties and visibility.
- Any Laravel disk, with separate disks for originals and variants.
- Image variants (crop, resize, format, quality) generated with Imagick or GD, synchronously or
  queued.
- Private media: `temporaryUrl()` presigns natively or falls back to a signed streaming route.
- ThumbHash and Blurhash placeholders plus responsive `srcset()` helpers.
- Content-addressable deduplication and checksum integrity checks (`verifyIntegrity()`).
- Draft media, validation rules derived from a bucket (`Media::rulesFor()`), replace-in-place,
  attach-by-reference, and moving or copying media across disks, models and buckets.
- CDN-ready public URLs through a pluggable URL generator with cache-busting.
- Artisan commands: `media:regenerate`, `media:clean`, `media:clear`, `media:verify` and
  `media:prune-drafts`.
- Lifecycle events such as `MediaHasBeenAdded`, `VariantsHaveBeenGenerated`, `MediaHasBeenMoved`
  and `MediaHasBeenDeleted`.
