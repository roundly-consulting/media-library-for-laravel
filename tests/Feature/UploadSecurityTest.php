<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function securityUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Mallory']);
}

/** Write `$contents` to a fresh temp directory under `$name`, so the source keeps a chosen filename. */
function namedTempFile(string $name, string $contents): string
{
    $directory = sys_get_temp_dir().'/media-security-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/'.$name, $contents);

    return $directory.'/'.$name;
}

/**
 * The `media_*` temp copies still holding `$bytes`. The temp dir is shared with every parallel
 * process, so a copy is found by its unique bytes, never by a before/after count.
 *
 * @return list<string>
 */
function tempCopiesHolding(string $bytes): array
{
    return array_values(array_filter(
        glob(sys_get_temp_dir().'/media_*') ?: [],
        static fn (string $path): bool => @file_get_contents($path) === $bytes,
    ));
}

it('discards the temporary copy of an add the bucket rejects', function (): void {
    $bytes = 'rejected-'.bin2hex(random_bytes(8));

    // Before: the copy addFromString() wrote was only unlinked once stored, so every refused
    // upload left a `media_*` file in the temp dir for good.
    expect(fn () => MediaLibrary::for(securityUser())->addFromString($bytes)->toBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class)
        ->and(tempCopiesHolding($bytes))->toBe([]);
});

it('never lets usingFileName() write outside the media directory', function (): void {
    $victim = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('gallery');
    $victimBytes = Storage::disk('public')->get($victim->getPath());

    $attack = MediaLibrary::addFromString('PWNED')
        ->usingFileName('../'.$victim->getPath())
        ->toBucket('gallery');

    expect(Storage::disk('public')->get($victim->getPath()))->toBe($victimBytes)
        ->and($victim->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($attack->file_name)->not->toContain('/')
        ->and($attack->file_name)->not->toContain('..')
        ->and($attack->getPath())->toStartWith($attack->uuid.'/');
});

it('never lets usingFileName() reach the disk root', function (): void {
    $media = MediaLibrary::addFromString('hello')->usingFileName('../index.html')->toBucket('gallery');

    Storage::disk('public')->assertMissing('index.html');
    expect($media->getPath())->toStartWith($media->uuid.'/');
});

it('strips separators and control characters from a custom file name', function (): void {
    $media = MediaLibrary::addFromString('hello')->usingFileName("..\\..\\notes\0 1.txt")->toBucket('gallery');

    expect($media->file_name)->toBe('notes-1.txt')
        ->and($media->getPath())->toBe($media->uuid.'/notes-1.txt');
});

it('stores a polyglot uploaded as html under the extension of its detected mime', function (): void {
    $user = securityUser();
    $polyglot = (string) file_get_contents(__DIR__.'/../files/pixel.png').'<script>alert(document.domain)</script>';

    $media = MediaLibrary::for($user)->add(namedTempFile('x.html', $polyglot))->toBucket('avatar');

    expect($media->mime_type)->toBe('image/png')
        ->and($media->file_name)->toBe('x.png')
        ->and($media->extension)->toBe('png');
});

it('ignores a lying client extension on an upload too', function (): void {
    $user = securityUser();
    $upload = UploadedFile::fake()->createWithContent(
        'x.svg',
        (string) file_get_contents(__DIR__.'/../files/pixel.png').'<svg onload="alert(1)"/>',
    );

    $media = MediaLibrary::for($user)->add($upload)->toBucket('avatar');

    expect($media->file_name)->toBe('x.png')
        ->and($media->mime_type)->toBe('image/png');
});

it('keeps a truthful extension, whatever its case', function (): void {
    $media = MediaLibrary::add(namedTempFile('IMG_0001.JPG', (string) file_get_contents(__DIR__.'/../files/landscape.jpg')))
        ->toBucket('gallery');

    expect($media->file_name)->toBe('IMG_0001.JPG')
        ->and($media->extension)->toBe('jpg');
});

it('keeps a harmless extension the sniffer only sees as generic text', function (): void {
    $media = MediaLibrary::add(namedTempFile('data.csv', "a,b\n1,2\n"))->toBucket('gallery');

    expect($media->file_name)->toBe('data.csv');
});

it('never stores a server-executable extension', function (): void {
    $media = MediaLibrary::addFromString('<?php echo "owned";')->usingFileName('shell.php')->toBucket('gallery');

    expect($media->file_name)->toBe('shell.txt')
        ->and($media->extension)->toBe('txt');
});

it('neutralises a server-executable inner extension', function (): void {
    $media = MediaLibrary::add(namedTempFile('shell.php.png', (string) file_get_contents(__DIR__.'/../files/pixel.png')))
        ->toBucket('gallery');

    expect($media->file_name)->toBe('shell-php.png');
});

it('sniffs remote bytes instead of trusting the remote content type', function (): void {
    Http::fake([
        'https://evil.test/*' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'image/png']),
    ]);

    $user = securityUser();

    expect(fn () => MediaLibrary::for($user)->addFromUrl('https://evil.test/a.html')->toBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    $media = MediaLibrary::addFromUrl('https://evil.test/a.html')->toBucket('gallery');

    expect($media->mime_type)->toBe('text/html');
});

it('refuses a non-http remote url', function (): void {
    expect(fn () => MediaLibrary::addFromUrl('file:///etc/passwd'))
        ->toThrow(RemoteFileRejected::class, 'http');
});

it('refuses a remote file larger than the package-level limit', function (): void {
    config()->set('media.max_file_size', 10);
    Http::fake(['https://cdn.test/*' => Http::response(str_repeat('a', 100), 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://cdn.test/big.txt'))
        ->toThrow(RemoteFileRejected::class, 'larger');
});

it('refuses a remote file whose declared length is over the limit before reading it', function (): void {
    config()->set('media.max_file_size', 10);
    Http::fake(['https://cdn.test/*' => Http::response('tiny', 200, ['Content-Length' => '5000'])]);

    expect(fn () => MediaLibrary::addFromUrl('https://cdn.test/declared.txt'))
        ->toThrow(RemoteFileRejected::class, 'larger');
});

it('sniffs a file added from a disk instead of trusting its stored mime type', function (): void {
    Storage::disk('s3')->put('incoming/x.html', (string) file_get_contents(__DIR__.'/../files/pixel.png'));

    $media = MediaLibrary::addFromDisk('incoming/x.html', 's3')->toBucket('gallery');

    expect($media->mime_type)->toBe('image/png')
        ->and($media->file_name)->toBe('x.png');
});
