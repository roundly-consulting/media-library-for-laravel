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
- Draft media, validation rules derived from a bucket (`MediaLibrary::rulesFor()`), replace-in-place,
  attach-by-reference, and moving or copying media across disks, models and buckets.
- CDN-ready public URLs through a pluggable URL generator with cache-busting.
- Artisan commands: `media:regenerate`, `media:clean`, `media:clear`, `media:verify` and
  `media:prune-drafts`.
- Lifecycle events such as `MediaHasBeenAdded`, `VariantsHaveBeenGenerated`, `MediaHasBeenMoved`
  and `MediaHasBeenDeleted`.
- One public API in three layers: the `MediaLibrary` facade, the injectable `MediaLibraryManager`
  behind it, and the actions. `MediaLibrary::for($model)` scopes adds, draft binding, attaching,
  reads (`get`, `first`, `has`, `find`, `url`, `temporaryUrl`), `clear()` and an
  ownership-checked `delete()` to one owner; flat `attach()` (a `null` owner attaches to a global
  bucket), `bindDraft()`, `move()`, `moveToDisk()`, `moveVariantsToDisk()`, `copy()`, `replace()`,
  `delete()`, `regenerate()` and `pruneDrafts()`; `MediaLibrary::variants($media)` with `all()`,
  `generated()`, `missing()` and `regenerate(only:, force:)`.
- `RegenerateVariantsAction`, `MoveMediaVariantsAction` and `PruneDraftsAction`.
- `MediaLibrary::fake()`: a recording `MediaLibraryFake` that touches no disk, row, queue or event
  and sees calls made through the facade, an injected manager, the trait and `Media` model methods,
  with `assert*()` / `assertNothing*()` for every mutation.

### Changed

- The facade is `MediaLibrary` (was `Media`, which clashed with the `Media` model), and its root
  is `MediaLibraryManager` (was `MediaManager`).
- The `InteractsWithMedia` trait and the `Media` model's `move()`, `copy()`, `moveToDisk()`,
  `moveVariantsToDisk()`, `replace()` and `deleteWithFiles()` go through the manager.
- `Media::move()` / `copy()` take `?Model $to` (was `HasMedia|Model|null $toModel`).
- `MoveMediaAction` has one public `execute()`; moving only the variants is
  `MoveMediaVariantsAction` and "keep the owner" moves are `MediaLibrary::moveToDisk()`.
- `GenerateVariantsAction`, `FileAdderFactory` and `PendingFileAdd`'s constructor are `@internal`;
  `FileAdderFactory` returns the normalized `AddedFile`, and `PendingFileAdd::addedFile()` is gone.
- `GenerateVariantsJob` and `media:regenerate`, `media:clear` and `media:prune-drafts` run through
  the manager; the `--only` / `--force` filtering moved into `RegenerateVariantsAction`.
- `clearBucket()` returns the number of media deleted.

### Fixed

- `media:prune-drafts` and `media:clear` skipped every other chunk of 1000 rows: they deleted
  while paging by offset. Both now iterate by id.
- Clearing a bucket through `clearMediaBucket()` or the global clear never fired
  `MediaHasBeenDeleted`; it now fires once per media, like `media:clear`.
- `addMediaFromRequest()` with a key that carries no upload threw a `TypeError`; it now throws
  `FileDoesNotExist`.
