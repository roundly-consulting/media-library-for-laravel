# Changelog

All notable changes to `media-library-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.2.0 - 2026-10-17

### Added

- A `presigned-s3` disk driver for S3 and S3-compatible stores that browsers reach through a
  different origin than the application does (an in-cluster object store behind a public ingress).
  A disk opts in with `'driver' => 'presigned-s3'`; every `s3` key still applies. With
  `presign_endpoint` set, reads and writes keep going to `endpoint` while `temporaryUrl()` and
  `temporaryUploadUrl()` are signed for the public origin by a second client that shares the disk's
  credentials (session tokens included), region and client options, and never sends a request.
  `presign_use_path_style_endpoint` overrides the path style for the public origin. `url()` refuses
  to build an unsigned URL from the in-cluster endpoint unless the disk sets `url`, and
  `temporary_url` is refused next to `presign_endpoint`, because re-hosting a signed URL breaks its
  signature. Without `presign_endpoint` the disk is Laravel's stock S3 adapter. The S3 stack stays
  the host's: `league/flysystem-aws-s3-v3` is suggested, not required.
- `MediaLibrary::remote()`: guarded server-side fetches of user-supplied URLs, with the guard
  `addFromUrl()` uses and nothing stored. `fetch($url, maxBytes:, timeout:, maxRedirects:, accept:)`
  returns a `RemoteResponse` (body, status, content type, final URL, headers) for a 2xx answer and
  throws a `RemoteFetchFailed` for anything else. `vet($url)` returns the guard's verdict without
  fetching: a `VettedUrl` (the canonical URL, host, port, every vetted address, and for a request
  of your own `httpOptions()`: Laravel HTTP client options that pin the vetted addresses and keep
  the request off the environment's proxy; `resolveEntry()` is the bare `CURLOPT_RESOLVE` pin).
  `isPrivateAddress($address)` judges one address. The same calls exist as `FetchRemoteUrlAction`
  and `VetRemoteUrlAction`. Under `MediaLibrary::fake()` they stay real and `Http::fake()` answers
  them.
- Status-aware remote errors. `RemoteFetchFailed` is the new base of `RemoteFileRejected` and the
  new `RemoteFileUnavailable`, with `retryable()`, `status()`, `retryAfter()` and `reason()` (a
  `RemoteFetchReason`). `RemoteFileRejected` is terminal (a refused URL, a 4xx other than 408/429,
  too many redirects, a body over the cap, a TLS certificate that does not verify);
  `RemoteFileUnavailable` is retryable (a 5xx, 408 or 429 with its `Retry-After`, a refused or reset
  connection, a timeout, a host that does not resolve). Only a 2xx body is read: any other answer
  stops after its headers and is judged by its status, so a 503 with an error page larger than the
  cap stays a retryable 503. `addFromUrl()` still throws `RemoteFileRejected` for a refused URL and
  reports a non-2xx answer, a failed connection and a host that does not resolve as it always has.
  Messages name the URL with all of its user info masked (a password holding `/`, `?`, `#` or `@`
  included), never an address it resolved to.
- `media.remote.fetch_max_bytes` (the `fetch()` byte cap, default 5 MB), `media.remote.max_redirects`
  (default 5, the limit that used to be fixed; `0` follows none; only a 301, 302, 303, 307 or 308 is
  followed), `media.remote.allowed_ports` (default any port) and
  `media.remote.forward_headers_on_redirect` (default off).
- A `SafeRemoteUrl` validation rule for URLs users hand in for a server-side fetch: it fails with
  one translated message for every refusal (`media::validation.remote_url`), so its answer never
  tells a user which internal names exist; `vet()` gives the exact reason. These are the package's
  first language files, in English and Slovak, publishable with the `media-translations` tag.

### Changed

- `RemoteFileRejected` now extends `RemoteFetchFailed` (still a `MediaLibraryException`), so
  existing catches keep working.
- `media.remote.timeout` is now one budget for the whole fetch, every redirect hop included. Before,
  each of up to six requests got the full timeout. A hop that would start with less than a
  millisecond of it left fails as a timeout (curl reads a zero timeout as none).
- Maintenance: the blocked-network table, its address and URL vectors, the redirect statuses and the
  resolution of redirect locations are one contract shared with the package's Node twin and tested
  on both sides, so both judge the same destinations.

### Security

- Remote fetches, `addFromUrl()` included, send `media.remote.headers` only to the URL's own origin
  (scheme, host and port). A redirect to another origin no longer receives them, so an
  `Authorization` header cannot leak to whatever host the first one redirects to; set
  `media.remote.forward_headers_on_redirect` to send them anyway. A `Host` header in
  `media.remote.headers` is refused as invalid configuration: it would pick another virtual host on
  the address vetted for the URL's own.
- Guarded fetches bypass the environment's `HTTP_PROXY` / `HTTPS_PROXY`: a proxy resolves the host
  name itself, which made the private-network guard and the address pin moot.
- The connection is pinned to every vetted address, not only the first, so a host whose first
  address is down fails over only to addresses the guard checked.
- A URL is parsed and rebuilt by the guard before any request, so PHP and curl can no longer read a
  different host into it. Refused: a control character anywhere in the URL, user info, numeric IPv4
  spellings (`2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`), a backslash, whitespace (Unicode
  whitespace and U+FEFF included) or `%` in the host, a backslash anywhere else, an IPv6 zone id,
  and the names `localhost` / `*.localhost`. A host with a non-ASCII character or a punycode
  (`xn--`) label goes through UTS #46 with ext-intl (nontransitional, with the hyphen, bidi and
  joiner rules) and is refused without it (ext-intl is now in `suggest`: without it even an ASCII
  `xn--` host is refused); an address it maps to (`８.８.８.８`, `127。0。0。1`) is refused, never
  normalised. A redirect `Location` with a backslash or a control character is refused, and a
  fragment-only one stays on the same document. `media.remote.allowed_private_hosts` names match
  with or without a trailing dot, and an IPv6 entry may be bracketed (`[::1]`). An informational
  answer (`103 Early Hints`) is passed over: the final answer is the one judged.
- The blocked networks gain Azure's WireServer (`168.63.129.16`, reachable from every Azure VM and
  AKS node), `3fff::/20` (IPv6 documentation) and `5f00::/16` (SRv6), and an IPv4-translated
  address (`::ffff:0:a.b.c.d`) is judged by the IPv4 address it carries.

## 1.1.4 - 2026-10-11

### Fixed

- A stored original could be deleted while another transaction was starting to share it. A
  deduplicated add or replace, an `attach()` or a same-disk `copy()` points its row at an existing
  file, but until that row commits no other connection can see it. A concurrent force-delete of
  the last committed row on the file found no other referrer and removed it; the sharing row then
  committed, pointing at nothing. The sharing row is now saved under a row lock on the row it shares
  with, so the delete waits for it, then sees it. If the shared row is gone by the time the lock is
  held, a deduplicating add or replace stores its own copy instead, and `attach()` / `copy()` throw
  `ModelNotFoundException`.
- A `forceDelete()` inside a transaction that then rolled back lost its files: the row came back,
  its original and variants did not. The files of a force-deleted media are now removed once the
  transaction commits, at once outside one, and stay when it rolls back.
- `replace()` could overwrite bytes another row was about to share. It decided whether the old
  original was shared with a plain read, so a deduplicated add onto it, not yet committed, was
  invisible: the new bytes were written in place and the deduplicated row committed pointing at
  them. A replace now locks its own row first and checks for other referrers with a locking read.
  A row that starts sharing the file has either committed (the replace sees it and writes its new
  bytes to a path of its own) or waits until the replace is done. A replace of a row that no
  longer exists throws `ModelNotFoundException`.
- A replace inside a transaction that then rolled back left the row on its old checksum with the
  new bytes: an in-place overwrite was not undone. The old bytes are now kept aside, put back on a
  rollback and dropped on commit; a replace written to a new path deletes that file on a rollback.
  The URL of an in-place replace stays the same, as before.

## 1.1.3 - 2026-10-11

### Changed

- Maintenance: requires the latest roundly packages — package-toolkit `^1.3.0`;
  dev: testing `^1.2.1`.

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
