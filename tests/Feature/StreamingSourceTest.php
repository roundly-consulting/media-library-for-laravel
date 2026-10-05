<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\LocalHttpServer;

/*
 * A source is copied into the package's own temp file before it is stored. Reading it whole into
 * a PHP string first made every add cost the file's size in memory — an 80 MB upload, well under
 * the 256 MB cap, was a fatal "Allowed memory size exhausted" at memory_limit=64M — and a remote
 * body without a Content-Length was downloaded in full before its size was checked.
 */

/*
 * Big enough to tell "bounded" from "the whole file": libmagic's type sniffing alone reads up to
 * ~15 MB of any file, whatever its size.
 */
const STREAMED_BYTES = 48 * 1024 * 1024;

/** A file of `$bytes` bytes. */
function largeSourceFile(int $bytes = STREAMED_BYTES): string
{
    $path = sys_get_temp_dir().'/media-large-'.uniqid().'.bin';
    $handle = fopen($path, 'wb');

    for ($written = 0; $written < $bytes; $written += 1048576) {
        fwrite($handle, str_repeat(chr(65 + intdiv($written, 1048576) % 26), 1048576));
    }

    fclose($handle);

    return $path;
}

/** Peak memory the call added on top of what was in use before it. */
function peakMemoryOf(Closure $work): int
{
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $work();

    return memory_get_peak_usage() - $before;
}

it('adds an upload without reading it into memory', function (): void {
    $path = largeSourceFile();
    $upload = new UploadedFile($path, 'large.bin', null, null, true);

    try {
        $peak = peakMemoryOf(fn () => MediaLibrary::add($upload)->toBucket('library'));

        expect($peak)->toBeLessThan(STREAMED_BYTES / 2);
    } finally {
        @unlink($path);
    }
});

it('adds a stream without reading it into memory', function (): void {
    $path = largeSourceFile();
    $stream = fopen($path, 'rb');

    try {
        $peak = peakMemoryOf(fn () => MediaLibrary::addFromStream($stream)->toBucket('library'));

        expect($peak)->toBeLessThan(STREAMED_BYTES / 2);
    } finally {
        fclose($stream);
        @unlink($path);
    }
});

it('adds a file from another disk without reading it into memory', function (): void {
    $path = largeSourceFile();
    Storage::disk('s3')->writeStream('incoming/large.bin', fopen($path, 'rb'));
    @unlink($path);

    $peak = peakMemoryOf(fn () => MediaLibrary::addFromDisk('incoming/large.bin', 's3')->toBucket('library'));

    expect($peak)->toBeLessThan(STREAMED_BYTES / 2);
});

it('keeps the bytes of a streamed source intact', function (): void {
    $path = largeSourceFile(4 * 1048576);

    try {
        $media = MediaLibrary::addFromStream(fopen($path, 'rb'))->toBucket('library');

        expect($media->size)->toBe(4 * 1048576)
            ->and($media->checksum)->toBe(hash_file('sha256', $path));
    } finally {
        @unlink($path);
    }
});

it('stops a remote download once it passes the size cap, without a content length', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.max_file_size', 65536);
    config()->set('media.remote.timeout', 5);
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);
    app()->instance(HostResolver::class, new HostResolver(static fn (): array => ['127.0.0.1']));

    $started = microtime(true);

    try {
        expect(fn () => MediaLibrary::addFromUrl("http://media-pin.invalid:{$server->port}/endless"))
            ->toThrow(RemoteFileRejected::class, 'larger');

        expect(microtime(true) - $started)->toBeLessThan(4.0);
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('refuses a faked remote body over the cap, content length or not', function (): void {
    config()->set('media.max_file_size', 10);
    Http::fake([
        'cdn.example.com/eleven.txt' => Http::response(str_repeat('a', 11), 200),
        'cdn.example.com/ten.txt' => Http::response(str_repeat('a', 10), 200),
    ]);

    expect(fn () => MediaLibrary::addFromUrl('https://cdn.example.com/eleven.txt'))
        ->toThrow(RemoteFileRejected::class, 'larger');

    expect(MediaLibrary::addFromUrl('https://cdn.example.com/ten.txt')->toBucket('library')->size)->toBe(10);
});

it('stores the full body of a faked response every time it is answered', function (?int $cap): void {
    config()->set('media.max_file_size', $cap);
    Http::fake(['*' => Http::response('the same stub', 200)]);

    $first = MediaLibrary::addFromUrl('https://cdn.example.com/a.txt')->toBucket('library');
    $second = MediaLibrary::addFromUrl('https://cdn.example.com/b.txt')->toBucket('library');

    expect(Storage::disk('public')->get($first->getPath()))->toBe('the same stub')
        ->and(Storage::disk('public')->get($second->getPath()))->toBe('the same stub');
})->with(['capped' => 1024, 'uncapped' => null]);

it('refuses an over-cap faked response however often it is answered', function (): void {
    config()->set('media.max_file_size', 4);
    Http::fake(['*' => Http::response('eleven char', 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://cdn.example.com/a.txt'))->toThrow(RemoteFileRejected::class, 'larger')
        ->and(fn () => MediaLibrary::addFromUrl('https://cdn.example.com/b.txt'))->toThrow(RemoteFileRejected::class, 'larger');
});

it('downloads without a cap when media.max_file_size is null', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.max_file_size', null);
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);

    try {
        $media = MediaLibrary::addFromUrl("http://127.0.0.1:{$server->port}/hello.txt")->toBucket('library');

        expect(Storage::disk('public')->get($media->getPath()))->toBe('hello from the pinned server');
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');
