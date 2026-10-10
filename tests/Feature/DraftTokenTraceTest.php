<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * A draft token is a capability: whoever holds it can bind the draft to a model of their
 * own. Wherever `zend.exception_ignore_args` is off, every frame of an exception keeps its
 * call arguments, and error trackers and debug dumps show them. A refused bind leaves the
 * draft unbound and its token live, so no frame of that exception may carry the token.
 */

beforeEach(function (): void {
    // A dev-style ini: call arguments kept in the trace, strings not cut at 15 bytes.
    $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    $this->maxLength = ini_set('zend.exception_string_param_max_len', '1000000');

    $this->user = TestUser::query()->create(['name' => 'Jane']);

    /*
     * Calls $call and returns what an error tracker could read from the thrown chain's
     * frames: every scalar among their arguments, and how many arguments were redacted
     * (a count above zero proves the frames kept their arguments).
     *
     * @return array{0: class-string<Throwable>, 1: string, 2: int}
     */
    $this->exposed = static function (Closure $call): array {
        try {
            $call();
        } catch (Throwable $e) {
            $seen = [];
            $redacted = 0;
            $collect = static function (mixed $value) use (&$collect, &$seen, &$redacted): void {
                if (is_array($value)) {
                    array_walk($value, $collect);
                } elseif ($value instanceof SensitiveParameterValue) {
                    $redacted++;
                } elseif (is_scalar($value)) {
                    $seen[] = (string) $value;
                }
            };

            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                foreach ($current->getTrace() as $frame) {
                    $collect($frame['args'] ?? []);
                }
            }

            return [$e::class, implode("\n", $seen), $redacted];
        }

        throw new RuntimeException('The call did not throw.');
    };

    // A text file the avatar bucket refuses: the bind throws and the token stays live.
    $this->refusedToken = static fn (): string => (string) MediaLibrary::draft(__DIR__.'/../files/note.txt')->toBucket('avatar')->draft_token;
});

afterEach(function (): void {
    ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    ini_set('zend.exception_string_param_max_len', (string) $this->maxLength);
    CarbonImmutable::setTestNow();
});

it('keeps a live draft token out of every frame of a refused manager bind', function (): void {
    $token = ($this->refusedToken)();
    $user = $this->user;

    [$class, $frames, $redacted] = ($this->exposed)(fn () => app(MediaLibraryManager::class)->bindDraft($token, $user, 'avatar'));

    expect($class)->toBe(FileUnacceptableForBucket::class)
        ->and($frames)->not->toContain($token)
        ->and($redacted)->toBeGreaterThan(0);
});

it('keeps a live draft token out of every frame of a refused handle bind', function (): void {
    $token = ($this->refusedToken)();
    $user = $this->user;

    [$class, $frames, $redacted] = ($this->exposed)(fn () => MediaLibrary::for($user)->bindDraft($token, 'avatar'));

    expect($class)->toBe(FileUnacceptableForBucket::class)
        ->and($frames)->not->toContain($token)
        ->and($redacted)->toBeGreaterThan(0);
});

it('keeps a live draft token out of every frame of a refused model bind', function (): void {
    $token = ($this->refusedToken)();
    $user = $this->user;

    [$class, $frames, $redacted] = ($this->exposed)(fn () => $user->attachDraftMedia($token, 'avatar'));

    expect($class)->toBe(FileUnacceptableForBucket::class)
        ->and($frames)->not->toContain($token)
        ->and($redacted)->toBeGreaterThan(0);
});

it('keeps a live draft token out of every frame of a refused fake bind', function (): void {
    MediaLibrary::fake();
    $token = ($this->refusedToken)();
    $user = $this->user;

    [$class, $frames, $redacted] = ($this->exposed)(fn () => MediaLibrary::for($user)->bindDraft($token, 'avatar'));

    expect($class)->toBe(FileUnacceptableForBucket::class)
        ->and($frames)->not->toContain($token)
        ->and($redacted)->toBeGreaterThan(0);
});

it('keeps an expired draft token out of every frame', function (): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');
    $token = (string) MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar')->draft_token;
    CarbonImmutable::setTestNow('2026-06-20 12:00:00');
    $user = $this->user;

    [$class, $frames, $redacted] = ($this->exposed)(fn () => MediaLibrary::for($user)->bindDraft($token, 'avatar'));

    expect($class)->toBe(DraftMediaExpired::class)
        ->and($frames)->not->toContain($token)
        ->and($redacted)->toBeGreaterThan(0);
});
