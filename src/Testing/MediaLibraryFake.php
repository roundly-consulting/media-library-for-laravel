<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * The recording stand-in {@see MediaLibrary::fake()} swaps in.
 *
 * Nothing is performed: no file is written to or removed from any disk, no row is inserted,
 * updated or deleted, no variant job is queued and no event fires — so a test needs neither a
 * real disk nor `Storage::fake()`. Every mutation is recorded instead, from wherever it came:
 * the facade, an injected manager, a `for()` handle, `variants()`, the `InteractsWithMedia`
 * trait or a `Media` model method.
 *
 * Mutations still return something realistic: adds, attaches and copies return an unsaved
 * {@see Media} carrying the attributes the real call would have stored (a fresh uuid, owner,
 * bucket, disk, variants disk, visibility, name); moves re-point the given media in memory; a
 * bound draft is the recorded (or stored) draft, re-pointed in memory onto its bucket's disk and
 * visibility.
 *
 * It refuses what the real manager refuses — it differs in side effects only: a bucket's
 * acceptance rules (mime allowlist, size cap, image dimensions) apply to adds, draft binds,
 * attaches, moves into another bucket, copies and replacements; a disk that is not configured
 * throws {@see DiskDoesNotExist}; an unknown or expired draft token throws. Reads (`get()`,
 * `find()`, `bucket()`, `rulesFor()`) run against the database as usual.
 *
 * @phpstan-type Call array{
 *     media: Media|null,
 *     result: Media|null,
 *     owner: string|null,
 *     bucket: string|null,
 *     disk: string|null,
 *     token: string|null,
 *     only: list<string>,
 *     force: bool,
 * }
 */
final class MediaLibraryFake extends MediaLibraryManager
{
    /** @var array<string, list<Call>> */
    private array $calls = [];

    public function store(PendingFileAddState $state): Media
    {
        // Like the real add: the temporary source goes however the add ends, refused included.
        try {
            return $this->recordStore($state);
        } finally {
            if ($state->file->isTemporary && is_file($state->file->path)) {
                @unlink($state->file->path);
            }
        }
    }

    private function recordStore(PendingFileAddState $state): Media
    {
        $guard = $this->guard();

        if (! $state->draft) {
            $guard->ensureSavedOwner($state->owner);
        }

        $bucket = $guard->bucketFor($state->owner, $state->bucket);
        $dimensions = str_starts_with((string) $state->file->mimeType, 'image/')
            ? ExifOrientation::displayDimensions($state->file->path)
            : null;

        $guard->ensureAccepts($bucket, $state->bucket, $state->file->mimeType, $state->file->size, $dimensions[0] ?? null, $dimensions[1] ?? null);

        $disk = $this->disks()->resolveOriginalDisk($state->diskOverride, $bucket);
        $variantsDisk = $this->disks()->resolveVariantsDisk($state->variantsDiskOverride, $bucket, $disk);

        $media = MediaModel::new();
        $media->uuid = (string) Str::uuid();
        $media->bucket_name = $state->bucket;
        $media->name = FileNames::displayName($state->name ?? $state->file->name);
        $media->file_name = $this->fileName($state);
        $media->mime_type = $state->file->mimeType;
        $media->extension = FileNames::extensionOf($media->file_name);
        $media->size = $state->file->size;
        $media->disk = $disk;
        $media->variants_disk = $variantsDisk === $disk ? null : $variantsDisk;
        $media->visibility = $state->visibility ?? $bucket?->getVisibility() ?? MediaConfig::defaultVisibility();
        $media->custom_properties = $state->customProperties;
        $media->generated_variants = [];

        if ($state->draft) {
            $media->draft_token = (string) Str::uuid();
            $media->draft_expires_at = CarbonImmutable::now()->addMinutes($this->draftTtl());
        } elseif ($state->owner instanceof Model) {
            $media->model_type = $state->owner->getMorphClass();
            $media->model_id = $state->owner->getKey();
        }

        $this->record('added', result: $media, owner: $this->ownerKeyOf($media), bucket: $state->bucket, disk: $media->disk);

        return $media;
    }

    public function attach(Media $media, ?Model $to = null, string $bucket = 'default'): Media
    {
        $this->guard()->ensureSavedOwner($to);
        $this->ensureAccepted($media, $to, $bucket);

        $attached = $this->replicaOf($media, $to, $bucket, $media->disk);

        $this->record('attached', media: $media, result: $attached, owner: $this->ownerKey($to), bucket: $bucket);

        return $attached;
    }

    public function bindDraft(string $token, Model $to, string $bucket = 'default'): Media
    {
        $this->guard()->ensureSavedOwner($to);

        $draft = $this->recordedDraft($token)
            ?? MediaModel::query()->drafts()->where('draft_token', $token)->first()
            ?? throw DraftMediaNotFound::forToken($token);

        if ($draft->draft_expires_at instanceof CarbonInterface && $draft->draft_expires_at->isPast()) {
            throw DraftMediaExpired::forToken($token);
        }

        $target = $this->guard()->bucketFor($to, $bucket);
        $this->guard()->ensureAcceptsMedia($target, $bucket, $draft);

        // Where the real bind puts it: the bucket's disk and visibility.
        if ($target !== null) {
            $disk = $target->getDisk() ?? $draft->disk;
            $this->disks()->ensureDiskExists($disk);
            $variantsDisk = $this->disks()->resolveVariantsDisk(null, $target, $disk);

            $draft->disk = $disk;
            $draft->variants_disk = $variantsDisk === $disk ? null : $variantsDisk;
            $draft->visibility = $target->getVisibility() ?? $draft->visibility;
        }

        $this->rehome($draft, $to, $bucket);
        $draft->draft_token = null;
        $draft->draft_expires_at = null;

        $this->record('bound', result: $draft, owner: $this->ownerKey($to), bucket: $bucket, token: $token);

        return $draft;
    }

    public function move(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null): Media
    {
        $this->disks()->ensureDiskExists($disk ?? $media->disk);

        if ($media->bucket_name !== $bucket || $media->model_type !== $to?->getMorphClass() || (string) $media->model_id !== (string) $to?->getKey()) {
            $this->guard()->ensureSavedOwner($to);
            $this->ensureAccepted($media, $to, $bucket);
        }

        $this->record('moved', media: $media, owner: $this->ownerKey($to), bucket: $bucket, disk: $disk ?? $media->disk);

        $this->rehome($media, $to, $bucket);
        $media->disk = $disk ?? $media->disk;

        return $media;
    }

    public function moveToDisk(Media $media, string $disk): Media
    {
        $this->disks()->ensureDiskExists($disk);

        $this->record('moved', media: $media, owner: $this->ownerKeyOf($media), bucket: $media->bucket_name, disk: $disk);

        $media->disk = $disk;

        return $media;
    }

    public function moveVariantsToDisk(Media $media, string $disk): Media
    {
        $this->disks()->ensureDiskExists($disk);

        $this->record('variantsMoved', media: $media, disk: $disk);

        $media->variants_disk = $disk === $media->disk ? null : $disk;

        return $media;
    }

    public function copy(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null): Media
    {
        $this->guard()->ensureSavedOwner($to);
        $this->disks()->ensureDiskExists($disk ?? $media->disk);
        $this->ensureAccepted($media, $to, $bucket);

        $copy = $this->replicaOf($media, $to, $bucket, $disk ?? $media->disk);

        $this->record('copied', media: $media, result: $copy, owner: $this->ownerKey($to), bucket: $bucket, disk: $copy->disk);

        return $copy;
    }

    public function replace(Media $media, string|UploadedFile $file): Media
    {
        $source = $this->files->fromFile($file);

        try {
            $dimensions = str_starts_with((string) $source->mimeType, 'image/')
                ? ExifOrientation::displayDimensions($source->path)
                : null;

            $this->guard()->ensureAccepts(
                $this->guard()->bucketFor($media->model, $media->bucket_name),
                $media->bucket_name,
                $source->mimeType,
                $source->size,
                $dimensions[0] ?? null,
                $dimensions[1] ?? null,
            );
        } finally {
            if ($source->isTemporary && is_file($source->path)) {
                @unlink($source->path);
            }
        }

        $this->record('replaced', media: $media);

        return $media;
    }

    public function delete(Media $media): void
    {
        $this->record('deleted', media: $media);
    }

    /**
     * Records the request and renders nothing, so it always returns an empty list.
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    public function regenerate(Media $media, array $only = [], bool $force = false): array
    {
        $this->record('regenerated', media: $media, only: $only, force: $force);

        return [];
    }

    /** Records the request and deletes nothing, so it always returns 0. */
    public function pruneDrafts(): int
    {
        $this->record('pruned');

        return 0;
    }

    public function assertAdded(?string $bucket = null, ?Model $to = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('added', bucket: $bucket, to: $to),
            'Expected media to be added'.$this->describe($bucket, $to).', but none was.',
        );
    }

    public function assertNothingAdded(): void
    {
        $this->assertNone('added', 'Expected no media to be added');
    }

    public function assertAttached(?Media $media = null, ?Model $to = null, ?string $bucket = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('attached', media: $media, bucket: $bucket, to: $to),
            'Expected media to be attached'.$this->describe($bucket, $to).', but none was.',
        );
    }

    public function assertNothingAttached(): void
    {
        $this->assertNone('attached', 'Expected no media to be attached');
    }

    public function assertDraftBound(?string $token = null, ?Model $to = null, ?string $bucket = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('bound', bucket: $bucket, to: $to, token: $token),
            'Expected a draft to be bound'.$this->describe($bucket, $to).', but none was.',
        );
    }

    public function assertNothingBound(): void
    {
        $this->assertNone('bound', 'Expected no draft to be bound');
    }

    /** Matches `move()` and `moveToDisk()` calls. */
    public function assertMoved(?Media $media = null, ?Model $to = null, ?string $bucket = null, ?string $disk = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('moved', media: $media, bucket: $bucket, to: $to, disk: $disk),
            'Expected media to be moved'.$this->describe($bucket, $to, $disk).', but none was.',
        );
    }

    public function assertNothingMoved(): void
    {
        $this->assertNone('moved', 'Expected no media to be moved');
    }

    public function assertVariantsMoved(?Media $media = null, ?string $disk = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('variantsMoved', media: $media, disk: $disk),
            'Expected media variants to be moved'.$this->describe(null, null, $disk).', but none were.',
        );
    }

    public function assertNoVariantsMoved(): void
    {
        $this->assertNone('variantsMoved', 'Expected no media variants to be moved');
    }

    public function assertCopied(?Media $media = null, ?Model $to = null, ?string $bucket = null, ?string $disk = null): void
    {
        Assert::assertNotEmpty(
            $this->matching('copied', media: $media, bucket: $bucket, to: $to, disk: $disk),
            'Expected media to be copied'.$this->describe($bucket, $to, $disk).', but none was.',
        );
    }

    public function assertNothingCopied(): void
    {
        $this->assertNone('copied', 'Expected no media to be copied');
    }

    public function assertReplaced(?Media $media = null): void
    {
        Assert::assertNotEmpty($this->matching('replaced', media: $media), 'Expected media to be replaced, but none was.');
    }

    public function assertNothingReplaced(): void
    {
        $this->assertNone('replaced', 'Expected no media to be replaced');
    }

    public function assertDeleted(?Media $media = null): void
    {
        Assert::assertNotEmpty($this->matching('deleted', media: $media), 'Expected media to be deleted, but none was.');
    }

    public function assertNothingDeleted(): void
    {
        $this->assertNone('deleted', 'Expected no media to be deleted');
    }

    /**
     * @param  list<string>|null  $only  the exact `$only` list requested, when given
     */
    public function assertRegenerated(?Media $media = null, ?array $only = null, ?bool $force = null): void
    {
        $matches = array_filter(
            $this->matching('regenerated', media: $media),
            static fn (array $call): bool => ($only === null || $call['only'] === $only)
                && ($force === null || $call['force'] === $force),
        );

        Assert::assertNotEmpty($matches, 'Expected media variants to be regenerated, but none were.');
    }

    public function assertNothingRegenerated(): void
    {
        $this->assertNone('regenerated', 'Expected no media variants to be regenerated');
    }

    public function assertDraftsPruned(): void
    {
        Assert::assertNotEmpty($this->calls['pruned'] ?? [], 'Expected expired drafts to be pruned, but they were not.');
    }

    public function assertDraftsNotPruned(): void
    {
        $this->assertNone('pruned', 'Expected expired drafts not to be pruned');
    }

    /**
     * @param  list<string>  $only
     */
    private function record(
        string $verb,
        ?Media $media = null,
        ?Media $result = null,
        ?string $owner = null,
        ?string $bucket = null,
        ?string $disk = null,
        ?string $token = null,
        array $only = [],
        bool $force = false,
    ): void {
        $this->calls[$verb][] = [
            'media' => $media,
            'result' => $result,
            'owner' => $owner,
            'bucket' => $bucket,
            'disk' => $disk,
            'token' => $token,
            'only' => $only,
            'force' => $force,
        ];
    }

    /**
     * @return list<Call>
     */
    private function matching(
        string $verb,
        ?Media $media = null,
        ?string $bucket = null,
        ?Model $to = null,
        ?string $disk = null,
        ?string $token = null,
    ): array {
        $owner = $this->ownerKey($to);

        return array_values(array_filter(
            $this->calls[$verb] ?? [],
            static fn (array $call): bool => ($media === null || $call['media']?->uuid === $media->uuid)
                && ($bucket === null || $call['bucket'] === $bucket)
                && ($owner === null || $call['owner'] === $owner)
                && ($disk === null || $call['disk'] === $disk)
                && ($token === null || $call['token'] === $token),
        ));
    }

    private function assertNone(string $verb, string $message): void
    {
        $count = count($this->calls[$verb] ?? []);

        Assert::assertSame(0, $count, "{$message}, but {$count} call(s) were recorded.");
    }

    private function describe(?string $bucket, ?Model $to, ?string $disk = null): string
    {
        $parts = array_filter([
            $bucket !== null ? "bucket [{$bucket}]" : null,
            $to !== null ? 'owner ['.$this->ownerKey($to).']' : null,
            $disk !== null ? "disk [{$disk}]" : null,
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /** The unbound draft this fake stored under `$token`, if any. */
    private function recordedDraft(string $token): ?Media
    {
        foreach ($this->calls['added'] ?? [] as $call) {
            if ($call['result']?->draft_token === $token) {
                return $call['result'];
            }
        }

        return null;
    }

    /** The real manager's acceptance check for media entering `$to`'s `$bucket`. */
    private function ensureAccepted(Media $media, ?Model $to, string $bucket): void
    {
        $this->guard()->ensureAcceptsMedia($this->guard()->bucketFor($to, $bucket), $bucket, $media);
    }

    private function guard(): BucketGuard
    {
        return $this->container->make(BucketGuard::class);
    }

    private function disks(): DiskResolver
    {
        return $this->container->make(DiskResolver::class);
    }

    private function replicaOf(Media $media, ?Model $to, string $bucket, string $disk): Media
    {
        $replica = $media->replicate(['uuid', 'generated_variants', 'draft_token', 'draft_expires_at', 'order_column']);
        $replica->uuid = (string) Str::uuid();
        $replica->generated_variants = [];
        $replica->disk = $disk;

        $this->rehome($replica, $to, $bucket);

        return $replica;
    }

    private function rehome(Media $media, ?Model $to, string $bucket): void
    {
        $media->bucket_name = $bucket;
        $media->model_type = $to?->getMorphClass();
        $media->model_id = $to?->getKey();
    }

    private function ownerKey(?Model $model): ?string
    {
        return $model === null ? null : $model->getMorphClass().'#'.$model->getKey();
    }

    private function ownerKeyOf(Media $media): ?string
    {
        return $media->model_type === null ? null : $media->model_type.'#'.$media->model_id;
    }

    /** The same safe stored name the real add computes. */
    private function fileName(PendingFileAddState $state): string
    {
        $requested = $state->fileName !== null
            ? FileNames::conform(FileNames::sanitize($state->fileName), $state->file->mimeType)
            : $state->file->fileName;

        return FileNames::sanitize($this->container->make(FileNamer::class)->originalFileName($requested));
    }

    private function draftTtl(): int
    {
        return MediaConfig::draftTtl();
    }
}
