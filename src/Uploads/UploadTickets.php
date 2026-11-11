<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Events\DirectUploadHasBeenRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadActorMismatch;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadTargetMismatch;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use SensitiveParameter;
use Throwable;

/**
 * The ticket rules every direct-upload action shares: look a token up, check its actor, move it
 * between states with conditional updates (so two requests can never both win), and clean up
 * the stored object of a ticket that will never be adopted.
 *
 * @internal
 */
final class UploadTickets
{
    /** How long an `adopting` claim holds before another request may take the ticket over. */
    public const int CLAIM_MINUTES = 10;

    public function __construct(
        private readonly StoredObjects $objects,
        private readonly S3UploadSigner $s3,
    ) {}

    /**
     * @throws DirectUploadsNotSupported when direct uploads are off
     * @throws DirectUploadNotFound
     */
    public function find(#[SensitiveParameter] string $token): MediaUpload
    {
        if (! MediaConfig::directUploadsEnabled()) {
            throw DirectUploadsNotSupported::disabled();
        }

        return UploadToken::find($token) ?? throw DirectUploadNotFound::forToken();
    }

    /**
     * @throws DirectUploadActorMismatch when the ticket names an actor and `$actor` is not it
     */
    public function ensureActor(MediaUpload $upload, ?Model $actor): void
    {
        if ($upload->actor_type === null) {
            return;
        }

        if ($actor === null
            || $actor->getMorphClass() !== $upload->actor_type
            || (string) $actor->getKey() !== $upload->actor_id) {
            throw DirectUploadActorMismatch::make();
        }
    }

    /**
     * Check the ticket against the owner and bucket the adopting route expects; a null expectation
     * is not checked. Reads the ticket only, so a refused ticket stays as it was. A global or
     * draft ticket has no owner, so it never matches an expected owner.
     *
     * @throws DirectUploadTargetMismatch
     */
    public function ensureTarget(MediaUpload $upload, ?Model $expectedOwner, ?string $expectedBucket): void
    {
        if ($expectedOwner !== null
            && ($upload->model_type === null
                || $upload->model_id === null
                || $expectedOwner->getKey() === null
                || $expectedOwner->getMorphClass() !== $upload->model_type
                || (string) $expectedOwner->getKey() !== $upload->model_id)) {
            throw DirectUploadTargetMismatch::owner();
        }

        if ($expectedBucket !== null && $expectedBucket !== $upload->bucket_name) {
            throw DirectUploadTargetMismatch::bucket();
        }
    }

    /**
     * A ticket that can still take bytes or be adopted — pending, or (with `$orAdopting`) claimed
     * by an adoption, whose claim then decides.
     *
     * @throws DirectUploadExpired
     * @throws DirectUploadNotFound for a rejected, aborted or adopted ticket
     */
    public function ensurePending(MediaUpload $upload, bool $orAdopting = false): void
    {
        if ($upload->status === DirectUploadStatus::Expired
            || ($upload->status === DirectUploadStatus::Pending && $upload->expires_at->isPast())) {
            throw DirectUploadExpired::make();
        }

        if ($upload->status !== DirectUploadStatus::Pending && ! ($orAdopting && $upload->status === DirectUploadStatus::Adopting)) {
            throw DirectUploadNotFound::forToken();
        }
    }

    /**
     * Take the ticket for adoption: pending and unexpired, or an `adopting` claim gone stale.
     * One conditional update, so of two concurrent requests exactly one gets it.
     */
    public function claim(MediaUpload $upload): bool
    {
        $now = CarbonImmutable::now();

        $claimed = MediaUpload::query()
            ->whereKey($upload->getKey())
            ->where(static function (Builder $query) use ($now): void {
                $query->where(static fn (Builder $pending) => $pending
                    ->where('status', DirectUploadStatus::Pending->value)
                    ->where('expires_at', '>', $now))
                    ->orWhere(static fn (Builder $stale) => $stale
                        ->where('status', DirectUploadStatus::Adopting->value)
                        ->where('claimed_at', '<', $now->subMinutes(self::CLAIM_MINUTES)));
            })
            ->update(['status' => DirectUploadStatus::Adopting->value, 'claimed_at' => $now, 'updated_at' => $now]) === 1;

        if ($claimed) {
            $upload->refresh();
        }

        return $claimed;
    }

    /** Give a claimed ticket back (the upload was not there yet, or adoption failed): it stays usable. */
    public function release(MediaUpload $upload): void
    {
        $this->transition($upload, DirectUploadStatus::Adopting, DirectUploadStatus::Pending, ['claimed_at' => null]);
    }

    /**
     * Move a ticket from `$from` to `$to` only if it is still in `$from`.
     *
     * @param  array<model-property<MediaUpload>, mixed>  $attributes
     */
    public function transition(MediaUpload $upload, DirectUploadStatus $from, DirectUploadStatus $to, array $attributes = []): bool
    {
        $moved = MediaUpload::query()
            ->whereKey($upload->getKey())
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => CarbonImmutable::now()] + $attributes) === 1;

        if ($moved) {
            $upload->refresh();
        }

        return $moved;
    }

    /**
     * Refuse a claimed ticket for good: its object goes (once this commits), the ticket records
     * why, and {@see DirectUploadHasBeenRejected} fires.
     */
    public function reject(MediaUpload $upload, string $reason): void
    {
        if ($this->transition($upload, DirectUploadStatus::Adopting, DirectUploadStatus::Rejected, ['failure_reason' => mb_substr($reason, 0, 255)])) {
            $this->discardObject($upload);
            event(new DirectUploadHasBeenRejected($upload, $reason));
        }
    }

    /**
     * Remove what a dead ticket left in storage — its object, or its unfinished multipart upload
     * — once the surrounding transaction commits.
     */
    public function discardObject(MediaUpload $upload): void
    {
        $upload->getConnection()->afterCommit(function () use ($upload): void {
            $s3 = $this->objects->s3($upload->disk);

            if ($upload->multipart_upload_id !== null && $s3 !== null && ! $upload->option('completed', false)) {
                try {
                    $this->s3->abortMultipart($s3, $upload->path, $upload->multipart_upload_id);
                } catch (Throwable $exception) {
                    app(ExceptionHandler::class)->report($exception);
                }

                return;
            }

            $this->objects->delete($upload->disk, $upload->path);
        });
    }

    /** The owner the ticket was issued for, or null for global media and drafts. */
    public function owner(MediaUpload $upload): ?Model
    {
        if ($upload->model_type === null || $upload->model_id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($upload->model_type) ?? $upload->model_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($upload->model_id);
    }
}
