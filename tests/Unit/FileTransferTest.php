<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;

beforeEach(function (): void {
    $this->transfer = new FileTransfer;
});

it('returns false copying a missing source', function (): void {
    expect($this->transfer->copy('public', 'missing.txt', 'cold', 'missing.txt', 'public'))->toBeFalse();
});

it('returns false moving a missing source across disks', function (): void {
    expect($this->transfer->move('public', 'missing.txt', 'cold', 'missing.txt', 'public'))->toBeFalse();
});

it('treats a same-disk same-path copy as a no-op', function (): void {
    Storage::disk('public')->put('a.txt', 'x');

    expect($this->transfer->copy('public', 'a.txt', 'public', 'a.txt', 'public'))->toBeTrue();
    Storage::disk('public')->assertExists('a.txt');
});

it('treats a same-disk same-path move as a no-op', function (): void {
    Storage::disk('public')->put('a.txt', 'x');

    expect($this->transfer->move('public', 'a.txt', 'public', 'a.txt', 'public'))->toBeTrue();
    Storage::disk('public')->assertExists('a.txt');
});

it('copies on the same disk to a new path', function (): void {
    Storage::disk('public')->put('a.txt', 'x');

    expect($this->transfer->copy('public', 'a.txt', 'public', 'b.txt', 'public'))->toBeTrue();
    Storage::disk('public')->assertExists('a.txt');
    Storage::disk('public')->assertExists('b.txt');
});

it('moves on the same disk to a new path, removing the source', function (): void {
    Storage::disk('public')->put('a.txt', 'x');

    expect($this->transfer->move('public', 'a.txt', 'public', 'b.txt', 'public'))->toBeTrue();
    Storage::disk('public')->assertMissing('a.txt');
    Storage::disk('public')->assertExists('b.txt');
});

it('streams a copy across disks', function (): void {
    Storage::disk('public')->put('a.txt', 'payload');

    expect($this->transfer->copy('public', 'a.txt', 'cold', 'a.txt', 'public'))->toBeTrue();
    expect(Storage::disk('cold')->get('a.txt'))->toBe('payload');
});
