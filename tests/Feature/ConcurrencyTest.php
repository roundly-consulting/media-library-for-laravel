<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
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
