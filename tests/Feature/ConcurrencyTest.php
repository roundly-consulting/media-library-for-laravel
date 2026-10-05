<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;

/*
 * Two requests for the same owner (a double submit) interleave. These tests replay one
 * interleaving deterministically: the second request runs, start to finish, inside a model event
 * of the first.
 */

beforeEach(fn () => LockRecorder::flush());

it('keeps exactly one media when two adds race for a single-file bucket', function (): void {
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);
    $raced = false;

    // Request B runs to completion between A's save and A's single-file enforcement.
    Media::created(static function () use ($owner, &$raced): void {
        if (! $raced) {
            $raced = true;
            MediaLibrary::for($owner)->add(__DIR__.'/../files/wide.png')->toBucket('single');
        }
    });

    MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket('single');

    expect(MediaLibrary::for($owner)->get('single'))->toHaveCount(1);
});

it('locks the owner row while it enforces a single-file bucket', function (): void {
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket('single');

    $locks = LockRecorder::recorded();

    expect($locks)->toHaveCount(1)
        ->and($locks[0]['marker'])->toBe('lock-for-update')
        ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1);
});

it('binds a draft token once when two binds race for it', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $token = (string) $draft->draft_token;
    $ada = TestUser::query()->create(['name' => 'Ada']);
    $bob = TestUser::query()->create(['name' => 'Bob']);

    $bound = 0;
    Event::listen(DraftMediaHasBeenBound::class, static function () use (&$bound): void {
        $bound++;
    });

    // Bob's bind runs between Ada's lookup of the draft and her save.
    $raced = false;
    $refusal = null;
    Media::saving(static function (Media $media) use ($token, $bob, &$raced, &$refusal): void {
        if ($raced || $media->getOriginal('draft_token') !== $token) {
            return;
        }

        $raced = true;

        try {
            MediaLibrary::for($bob)->bindDraft($token, 'gallery');
        } catch (DraftMediaNotFound $exception) {
            $refusal = $exception;
        }
    });

    $media = MediaLibrary::for($ada)->bindDraft($token, 'gallery');

    expect($refusal)->toBeInstanceOf(DraftMediaNotFound::class)
        ->and($bound)->toBe(1)
        ->and((string) $media->model_id)->toBe((string) $ada->id)
        ->and((string) Media::query()->findOrFail($draft->id)->model_id)->toBe((string) $ada->id);
});
