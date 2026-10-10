# Changelog

All notable changes to `media-library-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.1.2 - 2026-10-11

### Security

- A draft token passed to `MediaLibrary::bindDraft()`, `MediaLibrary::for($model)->bindDraft()` or
  `$model->attachDraftMedia()` no longer sits in the stack frames of an exception thrown while
  binding it. The token parameter is now `#[SensitiveParameter]` along the whole bind path: the
  manager, the model handle, the `InteractsWithMedia` trait, `BindDraftMediaAction`, the
  `DraftMediaNotFound` / `DraftMediaExpired` factories and `MediaLibraryFake`. Before, wherever
  `zend.exception_ignore_args` is off, a bind the bucket refused (the draft stays unbound, so its
  token stays usable) left the token in those frames, where error trackers that collect frame
  arguments could show it.
- Flat facade calls (`MediaLibrary::bindDraft($token, $model)`) no longer leave
  `#[SensitiveParameter]` arguments in the facade's stack frame: the `MediaLibrary` facade uses
  package-toolkit's `RedactsSensitiveArguments`, which hides exactly the arguments the
  `MediaLibraryManager` method marks, while every other argument stays visible. Requires
  package-toolkit `^1.3`.

## 1.1.1 - 2026-10-06

### Fixed

- An add inside your own `DB::transaction()` no longer leaves its files behind when that transaction
  rolls back. The original and the variants rendered during the add are deleted again; an original
  shared with an existing media (deduplicated) is kept. A savepoint rollback cleans up only the adds
  inside it, and a commit keeps everything.

## 1.1.0 - 2026-10-05

### Added

- `media.remote.block_private_networks` (default `true`) and `media.remote.allowed_private_hosts`
  (default `[]`) configure the new private-network guard of `addFromUrl()` (see Security).
- `media.max_image_pixels` (default `50_000_000`, `null` for no cap): the largest image the package
  decodes for variants and placeholders.
- The exceptions `FileCannotBeWritten`, `InvalidVisibility` and `MediaOwnerNotSaved`, plus new
  `RemoteFileRejected` and `InvalidVariant` factories. All extend `MediaLibraryException`.
- A `media_dimensions` validation rule (what `MediaLibrary::rulesFor()` emits for a bucket's
  dimension limits) that measures the size a viewer sees, EXIF orientation applied.

### Changed

- `addFromUrl()` resolves the URL's host before the request, even under `Http::fake()`. A test that
  fakes a made-up host name (one that does not resolve, or resolves to a private address such as a
  local `*.test` domain) now gets `RemoteFileRejected`: add the host to
  `media.remote.allowed_private_hosts`, or set `media.remote.block_private_networks` to `false`, in
  your test environment.
- With the guard on, `addFromUrl()` needs `ext-curl` for URLs with a host name (it pins the
  connection to the vetted address) and throws `RemoteFileRejected` without it. Address literals
  and allowlisted host names don't need it.
- Images larger than `media.max_image_pixels` (50 megapixels by default) are stored without variants
  or placeholders. If you store larger photos, raise the cap or set it to `null`.
- Replacing a media with a file of another type (a JPEG with a PNG) now renames the stored file to
  match (`landscape.jpg` becomes `landscape.png`), so its URL changes. A same-type replacement
  still overwrites in place and keeps its URL. Re-read `getUrl()` after a `replace()` instead of
  caching the old URL.
- `MediaLibrary::fake()` now refuses what the real manager refuses: bucket acceptance rules apply
  to `attach()`, `move()`, `copy()` and `replace()` too, an unconfigured disk throws
  `DiskDoesNotExist`, and `bindDraft()` returns the draft on its bucket's disk and visibility.
  Configure the disks your buckets use in your test environment (`Storage::fake()` alone does not
  add one to `filesystems.disks`).
- SVG and other non-raster images are never decoded any more, so they get no variants and no
  placeholders. Render previews of such files yourself if you need them.
- `MediaLibrary::rulesFor()` emits the exact size cap in kilobytes (e.g. `max:0.9765625`) and a
  `media_dimensions:` rule in place of Laravel's `dimensions:`, so the rules never pass a file the
  add then refuses. Its failure message falls back to your `validation.dimensions` line; add a
  `validation.media_dimensions` line to word it differently.
- `withVisibility()` on an add or a bucket accepts only `public` and `private` and throws the new
  `InvalidVisibility` otherwise. `Private` used to be stored and treated as public. Pass exactly
  `'public'` or `'private'`.
- Adding, attaching, binding, moving or copying media to a model that was never saved throws the
  new `MediaOwnerNotSaved`. Save the owner first.
- A variant that fails to render during an add, bind, attach, move, copy or replace is reported to
  your exception handler and skipped; the call succeeds with the media stored. An explicit
  `regenerate()` still throws. Code that caught the add's exception should check
  `MediaLibrary::variants($media)->missing()` instead.
- Maintenance: `composer.json` `homepage` and `support.docs` point at the package's docs page.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.

### Fixed

- A move, variant move, copy or draft bind onto a disk that refuses the write (one configured with
  `'throw' => false`) throws the new `FileCannotBeWritten` and changes nothing, instead of
  committing the new location and deleting the only copy.
- `moveToDisk()` keeps the owner, bucket and variants of media whose owner is soft-deleted or gone,
  instead of turning it into global media and dropping its variants.
- Inside your own `DB::transaction()`, moves, draft binds, re-homes and replacements delete the
  files they release only once your transaction commits, so a rollback keeps the bytes the
  restored row points at.
- The queued variants job is pushed only after the surrounding transaction commits (it implements
  `ShouldQueueAfterCommit`), so a worker never drops it for a row it cannot see yet.
- An add or replace whose write fails, or whose source can no longer be read, throws instead of
  saving a row with no file; variant writes are checked the same way; deduplication only shares
  a stored file that still exists.
- Two concurrent adds to a single-file bucket for the same owner leave exactly one media instead of
  deleting each other.
- The same draft token can no longer be bound twice by concurrent `bindDraft()` calls.
- Moving a draft to an owner or a global bucket settles it: the token stops working, `bucket()`
  lists it and `pruneDrafts()` leaves it alone. Pruning only touches owner-less drafts.
- Uploads, disk files and streams are copied to the temporary file in chunks rather than read into
  memory whole.
- Imagick variants of animated or optimized GIFs come from the first frame, matching GD.
- ThumbHash placeholders decode to the right picture and aspect ratio, and Blurhash placeholders
  decode their first AC component correctly, matching the reference implementations.
- `placeholderDataUri()` and `responsiveImage()` work on Imagick-only hosts (and return no
  placeholder instead of throwing when neither GD nor Imagick is loaded).
- `verifyIntegrity()`, `media.verify_checksum_on_read` and `media:verify` keep recognising media
  stored before `media.checksum_algorithm` changed.
- Display names longer than the 255-character `name` column are cut to fit, and an add whose row
  fails to save no longer leaves its file behind.
- An image a driver cannot decode throws `InvalidVariant` saying so, from GD and Imagick alike.

### Security

- `addFromUrl()` refuses URLs whose host is, resolves to or redirects to a private, loopback,
  link-local, carrier-grade NAT, cloud-metadata or other reserved address (IPv4 and IPv6,
  IPv4-mapped included). Every hop is resolved once and the connection pinned to the vetted
  address, so DNS rebinding cannot swap it. Allow trusted internal hosts with the new
  `media.remote.allowed_private_hosts`, or turn the guard off with
  `media.remote.block_private_networks`.
- `addFromUrl()` streams the download to disk and aborts it as soon as the body passes
  `media.max_file_size`, even without a `Content-Length`.
- Images are measured from their header before any decode and skipped (no placeholder, no
  variants) above the new `media.max_image_pixels` (default 50,000,000), so a small file declaring
  a huge canvas cannot exhaust memory. Only raster types are decoded, each through an explicit
  ImageMagick coder, so an SVG can no longer pull a server file into a public thumbnail.

## 1.0.0 - 2026-10-03

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
- Upload safety: mime types sniffed from the bytes, stored names reduced to one safe path segment
  with an extension that cannot lie about active content, `http(s)`-only and size-capped
  `addFromUrl()`, and a streaming route that serves active content as a sandboxed attachment.
- Bucket rules (mime allowlist, size cap, image dimensions, single-file) enforced on every way
  media enters a bucket — add, draft bind, attach, move, copy and replace.
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
