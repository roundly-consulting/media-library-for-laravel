<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\TemporaryUrlOptions;
use RoundlyConsulting\MediaLibrary\Enums\ContentDisposition;
use RoundlyConsulting\MediaLibrary\Exceptions\TemporaryUrlNotSupported;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\HostUrlGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * A presigned URL is answered by the storage origin, not by the package's streaming route, so the
 * route's response rules (attachment unless inline-safe, the sniffed type) only reach it when the
 * URL asks for them: S3's response-content-disposition / response-content-type.
 */

/**
 * Make the faked `s3` disk presign, recording the options each temporaryUrl() call was handed.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function recordPresignOptions(): ArrayObject
{
    $calls = new ArrayObject;

    Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiry, array $options) use ($calls): string {
        $calls->append($options);

        return "https://s3.example.com/{$path}";
    });

    return $calls;
}

function onS3(string $label, string $fileName, string $mime, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'uuid' => mediaUuid($label),
        'file_name' => $fileName,
        'disk' => 's3',
        'visibility' => 'private',
        'mime_type' => $mime,
    ], $attributes));
}

function soon(): CarbonImmutable
{
    return CarbonImmutable::now()->addMinutes(5);
}

it('leaves temporary URLs as they were while the option is off', function (): void {
    $calls = recordPresignOptions();

    onS3('plain-html', 'page.html', 'text/html')->getTemporaryUrl(soon());

    expect($calls->getArrayCopy())->toBe([[]]);
});

it('asks the origin for an attachment of active content once the option is on', function (): void {
    config()->set('media.temporary_url_response_headers', true);
    $calls = recordPresignOptions();

    onS3('evil-html', 'page.html', 'text/html')->getTemporaryUrl(soon());
    onS3('evil-svg', 'x.svg', 'image/svg+xml')->getTemporaryUrl(soon());
    onS3('safe-png', 'pixel.png', 'image/png')->getTemporaryUrl(soon());

    expect($calls[0])->toBe([
        'ResponseContentDisposition' => 'attachment; filename=page.html',
        'ResponseContentType' => 'text/html',
    ])
        ->and($calls[1]['ResponseContentDisposition'])->toStartWith('attachment')
        ->and($calls[1]['ResponseContentType'])->toBe('image/svg+xml')
        ->and($calls[2]['ResponseContentDisposition'])->toBe('inline; filename=pixel.png')
        ->and($calls[2]['ResponseContentType'])->toBe('image/png');
});

it('asks for headers on one URL with getTemporaryUrlWithOptions(), whatever the option', function (): void {
    $calls = recordPresignOptions();
    $media = onS3('opts-png', 'pixel.png', 'image/png');

    $media->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions);
    $media->getTemporaryDownloadUrl(soon(), name: 'scans/Faktúra 2026.png');
    $media->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions(contentType: 'text/plain; charset=utf-8'));

    expect($calls[0]['ResponseContentDisposition'])->toBe('inline; filename=pixel.png')
        // Reduced to one safe segment, with an ASCII fallback beside the UTF-8 name.
        ->and($calls[1]['ResponseContentDisposition'])->toBe("attachment; filename=Faktura-2026.png; filename*=utf-8''Fakt%C3%BAra-2026.png")
        ->and($calls[2]['ResponseContentType'])->toBe('text/plain; charset=utf-8')
        ->and($calls[2]['ResponseContentDisposition'])->toStartWith('inline');
});

it('never honours inline for a type a browser could run', function (string $type): void {
    $calls = recordPresignOptions();

    onS3('inline-'.$type, 'file.bin', 'image/png')
        ->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions(ContentDisposition::Inline, contentType: $type));

    expect($calls[0]['ResponseContentDisposition'])->toStartWith('attachment')
        ->and($calls[0]['ResponseContentType'])->toBe($type);
})->with(['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/xml', 'application/javascript']);

it('describes the variant it serves', function (): void {
    $calls = recordPresignOptions();

    onS3('variant-png', 'photo.png', 'image/png', [
        'generated_variants' => ['thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 's3']],
    ])->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions, 'thumb');

    expect($calls[0])->toBe([
        'ResponseContentDisposition' => 'inline; filename=thumb.webp',
        'ResponseContentType' => 'image/webp',
    ]);
});

it('signs the response overrides into a real S3 presigned URL', function (string $driver): void {
    config()->set('filesystems.disks.real-s3', array_filter([
        'driver' => $driver,
        'key' => 'AKIATEST',
        'secret' => 'secret',
        'region' => 'eu-central-1',
        'bucket' => 'media',
        'endpoint' => 'http://rustfs:9000',
        'use_path_style_endpoint' => true,
        'presign_endpoint' => $driver === 'presigned-s3' ? 'https://media.example.com' : null,
    ]));
    Storage::forgetDisk('real-s3');

    $media = Media::factory()->create([
        'uuid' => mediaUuid('real-'.$driver), 'file_name' => 'page.html', 'disk' => 'real-s3',
        'visibility' => 'private', 'mime_type' => 'text/html',
    ]);

    parse_str((string) parse_url($media->getTemporaryDownloadUrl(soon()), PHP_URL_QUERY), $query);
    parse_str((string) parse_url($media->getTemporaryUrl(soon()), PHP_URL_QUERY), $plain);

    expect($query['response-content-disposition'] ?? null)->toBe('attachment; filename=page.html')
        ->and($query['response-content-type'] ?? null)->toBe('text/html')
        ->and($query)->toHaveKey('X-Amz-Signature')
        ->and($plain)->not->toHaveKey('response-content-disposition');
})->with(['s3', 'presigned-s3']);

it('falls back to the signed streaming route, which downloads under the asked-for name', function (): void {
    $uuid = mediaUuid('route-png');
    Storage::disk('secure')->put("{$uuid}/pixel.png", (string) file_get_contents(__DIR__.'/../files/pixel.png'));
    $media = Media::factory()->create([
        'uuid' => $uuid, 'file_name' => 'pixel.png', 'disk' => 'secure', 'visibility' => 'private', 'mime_type' => 'image/png',
    ]);

    $url = $media->getTemporaryDownloadUrl(soon(), name: '../report.png');

    expect(URL::hasValidSignature(Request::create($url)))->toBeTrue()
        ->and($url)->toContain('download=1')
        ->and($url)->toContain('name=report.png');

    $response = $this->get($url);

    expect($response->headers->get('content-disposition'))->toBe('attachment; filename=report.png')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');

    // Tampering with the name breaks the signature.
    $this->get(str_replace('name=report.png', 'name=evil.html', $url))->assertForbidden();

    // An inline-safe file without download=1 streams inline, its name left as stored.
    $inline = $this->get($media->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions(fileName: 'other.png')));
    expect($inline->headers->get('content-disposition'))->toBe('inline; filename=pixel.png');
});

it('downloads active content under the asked-for name on the streaming route', function (): void {
    $uuid = mediaUuid('route-html');
    Storage::disk('secure')->put("{$uuid}/page.html", '<script>alert(1)</script>');
    $media = Media::factory()->create([
        'uuid' => $uuid, 'file_name' => 'page.html', 'disk' => 'secure', 'visibility' => 'private', 'mime_type' => 'text/html',
    ]);

    $response = $this->get($media->getTemporaryUrlWithOptions(soon(), new TemporaryUrlOptions(fileName: 'saved.html')));

    expect($response->headers->get('content-disposition'))->toBe('attachment; filename=saved.html')
        ->and($response->headers->get('content-security-policy'))->toContain('sandbox');
});

it('asks for headers through the owner handle', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('vault');

    $url = MediaLibrary::for($user)->temporaryUrl('vault', options: TemporaryUrlOptions::download());

    expect($url)->toContain('download=1')
        ->and(MediaLibrary::for($user)->temporaryUrl('vault'))->not->toContain('download=1');
});

it('passes the options through the CDN generator', function (): void {
    $calls = recordPresignOptions();
    $media = onS3('cdn-html', 'page.html', 'text/html');

    (new CdnUrlGenerator(app(DefaultUrlGenerator::class)))
        ->getTemporaryUrlWithOptions($media, soon(), TemporaryUrlOptions::download());

    expect($calls[0]['ResponseContentDisposition'])->toStartWith('attachment');
});

it('refuses response options on a URL generator that cannot set them', function (): void {
    $media = onS3('host-gen', 'page.html', 'text/html');

    app()->instance(UrlGenerator::class, new HostUrlGenerator);

    // The plain contract keeps working unchanged.
    expect($media->getTemporaryUrl(soon()))->toBe('https://host.test/signed/'.$media->uuid)
        ->and(fn () => $media->getTemporaryDownloadUrl(soon()))->toThrow(TemporaryUrlNotSupported::class, HostUrlGenerator::class)
        ->and(fn () => (new CdnUrlGenerator(new HostUrlGenerator))->getTemporaryUrlWithOptions($media, soon(), new TemporaryUrlOptions))
        ->toThrow(TemporaryUrlNotSupported::class);
});

it('refuses options that would break the header', function (Closure $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'a content type with a line break' => [fn () => new TemporaryUrlOptions(contentType: "text/plain\r\nX-Evil: 1")],
    'no media type' => [fn () => new TemporaryUrlOptions(contentType: 'html')],
    'a blank file name' => [fn () => new TemporaryUrlOptions(fileName: ' ')],
    'a file name with a control character' => [fn () => new TemporaryUrlOptions(fileName: "a\nb.png")],
]);
