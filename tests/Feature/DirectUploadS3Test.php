<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\FakeS3;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Direct uploads on S3, against real SDK clients answered by an in-memory store: presigning is
 * local, and every request that is sent is logged — so these prove the bytes never pass through
 * the application (no PutObject) and are inspected with one ranged read.
 */

beforeEach(function (): void {
    config()->set('media.direct_uploads.enabled', true);
    $this->s3 = new FakeS3;
    s3UploadDisk($this->s3);
});

/** @param array<string, mixed> $overrides */
function s3UploadDisk(FakeS3 $s3, array $overrides = []): void
{
    config()->set('filesystems.disks.media-s3', array_filter(array_merge([
        'driver' => 's3',
        'key' => 'AKIATEST',
        'secret' => 'secret',
        'region' => 'eu-central-1',
        'bucket' => 'media',
        'endpoint' => 'http://rustfs:9000',
        'use_path_style_endpoint' => true,
        'handler' => $s3,
    ], $overrides), static fn (mixed $value): bool => $value !== null));
    Storage::forgetDisk('media-s3');
}

function s3User(): TestUser
{
    return TestUser::query()->create(['name' => 'Ada']);
}

function s3Ticket(DirectUpload $upload): MediaUpload
{
    return MediaUpload::query()->findOrFail(explode('.', $upload->token)[0]);
}

/** @return array<string, string> */
function s3Query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('presigns a PUT to the staging key', function (): void {
    $upload = MediaLibrary::for(s3User())->upload('a.png', 69)->useDisk('media-s3')->toMediaBucket('gallery');

    expect($upload->method)->toBe(DirectUploadMethod::Put)
        ->and($upload->url)->toStartWith('http://rustfs:9000/media/'.$upload->key.'?')
        ->and(s3Query($upload->url))->toHaveKey('X-Amz-Signature')
        ->and($upload->headers)->not->toHaveKey('Host')
        ->and($this->s3->names())->toBe([]);
});

it('signs a declared sha256 and uploads private media in place', function (): void {
    $sha = hash_file('sha256', __DIR__.'/../files/pixel.png');
    $upload = MediaLibrary::for(s3User())->upload('a.png', 69)->withSha256($sha)->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');

    expect($upload->key)->toBe($upload->mediaUuid.'/a.png')
        ->and(s3Ticket($upload)->in_place)->toBeTrue()
        ->and($upload->sizeEnforcedBySignature)->toBeTrue()
        ->and(s3Query($upload->url)['x-amz-checksum-sha256'] ?? null)->toBe(base64_encode((string) hex2bin($sha)));

    // A public one is never written where it will be served from: it goes to staging first.
    $public = MediaLibrary::for(s3User())->upload('a.png', 69)->withSha256($sha)->useDisk('media-s3')->toMediaBucket('gallery');

    expect($public->key)->toStartWith('_uploads/');
});

it('signs PUTs for the public origin on a presigned-s3 disk', function (): void {
    s3UploadDisk($this->s3, ['driver' => 'presigned-s3', 'presign_endpoint' => 'https://media.example.com']);

    $upload = MediaLibrary::for(s3User())->upload('a.png', 69)->useDisk('media-s3')->toMediaBucket('gallery');

    expect($upload->url)->toStartWith('https://media.example.com/media/'.$upload->key);
});

it('issues a POST policy that caps the size and binds the key and type', function (): void {
    config()->set('media.direct_uploads.disks.media-s3.method', 'post');

    $upload = MediaLibrary::for(s3User())->upload('a.png', 69)->declaringType('image/png')->useDisk('media-s3')->toMediaBucket('gallery');
    $policy = json_decode((string) base64_decode($upload->fields['Policy'] ?? '', true), true);

    expect($upload->method)->toBe(DirectUploadMethod::Post)
        ->and($upload->sizeEnforcedBySignature)->toBeTrue()
        ->and($upload->fields['key'])->toBe($upload->key)
        ->and($upload->fields['Content-Type'])->toBe('image/png')
        ->and($upload->fields)->toHaveKey('X-Amz-Signature')
        ->and($policy['conditions'])->toContain(['content-length-range', 1, 69], ['eq', '$key', $upload->key], ['eq', '$Content-Type', 'image/png']);
});

it('adopts by one ranged read and a server-side copy — never a PUT through the app', function (string $file, string $type, string $disposition): void {
    $bytes = (string) file_get_contents(__DIR__.'/../files/'.$file);
    $upload = MediaLibrary::for(s3User())->upload($file, strlen($bytes))->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->objects[$upload->key] = $bytes;

    $media = MediaLibrary::adoptUpload($upload->token);
    $copy = $this->s3->params('CopyObject')[0] ?? [];

    expect($this->s3->names())->not->toContain('PutObject')
        ->and(array_filter($this->s3->params('GetObject'), static fn (array $get): bool => isset($get['Range'])))->toHaveCount(1)
        ->and($copy['MetadataDirective'] ?? null)->toBe('REPLACE')
        ->and($copy['ContentType'] ?? null)->toBe($type)
        ->and($copy['ContentDisposition'] ?? null)->toStartWith($disposition)
        ->and($copy['ACL'] ?? null)->toBe('public-read')
        ->and($this->s3->objects)->toHaveKey($media->getPath())
        ->and($this->s3->objects)->not->toHaveKey($upload->key)
        ->and($media->mime_type)->toBe($type);
})->with([
    'an image, served inline' => ['wide.png', 'image/png', 'inline'],
    'a text file, served inline' => ['note.txt', 'text/plain', 'inline'],
]);

it('stores active content as an attachment with its sniffed type, whatever the browser sent', function (): void {
    $html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
    $upload = MediaLibrary::for(s3User())->upload('cat.png', strlen($html))->declaringType('image/png')->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->objects[$upload->key] = $html;
    $this->s3->metadata[$upload->key] = ['ContentType' => 'image/png'];

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media->mime_type)->toBe('text/html')
        ->and($media->file_name)->toBe(FileNames::conform('cat.png', 'text/html'))
        ->and($this->s3->metadata[$media->getPath()]['ContentType'])->toBe('text/html')
        ->and($this->s3->metadata[$media->getPath()]['ContentDisposition'])->toStartWith('attachment');
});

it('trusts the store\'s own sha256 for a signed PUT when the disk is configured to', function (): void {
    config()->set('media.direct_uploads.disks.media-s3.storage_checksums', true);
    $bytes = (string) file_get_contents(__DIR__.'/../files/note.txt');
    $sha = hash('sha256', $bytes);
    $upload = MediaLibrary::for(s3User())->upload('note.txt', strlen($bytes))->withSha256($sha)->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->objects[$upload->key] = $bytes;
    $this->s3->metadata[$upload->key] = ['ChecksumSHA256' => base64_encode((string) hex2bin($sha))];

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media->checksum)->toBe($sha)
        ->and($media->getPath())->toBe($upload->key)
        ->and($this->s3->names())->not->toContain('CopyObject')
        ->and(array_filter($this->s3->params('GetObject'), static fn (array $get): bool => ! isset($get['Range'])))->toBe([])
        ->and(array_column($this->s3->params('HeadObject'), 'ChecksumMode'))->toContain('ENABLED');
});

it('uploads a multipart file in place, checking parts before every batch and at completion', function (): void {
    $partSize = 5 * 1024 * 1024;
    $bytes = str_repeat('a', $partSize).str_repeat('b', 1024);
    $upload = MediaLibrary::for(s3User())->upload('big.txt', strlen($bytes))->multipart($partSize)->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');
    $ticket = s3Ticket($upload);

    $parts = MediaLibrary::signUploadParts($upload->token, [1, 2]);
    $etag1 = $this->s3->uploadPart((string) $ticket->multipart_upload_id, 1, substr($bytes, 0, $partSize));
    $etag2 = $this->s3->uploadPart((string) $ticket->multipart_upload_id, 2, substr($bytes, $partSize));

    expect($upload->method)->toBe(DirectUploadMethod::Multipart)
        ->and($upload->partCount)->toBe(2)
        ->and($ticket->in_place)->toBeTrue()
        ->and($parts)->toHaveCount(2)
        ->and(s3Query($parts[1]->url))->toMatchArray(['partNumber' => '2', 'uploadId' => $ticket->multipart_upload_id])
        ->and(fn () => MediaLibrary::completeUpload($upload->token, [1 => $etag1, 2 => 'wrong']))->toThrow(DirectUploadIncomplete::class)
        ->and(s3Ticket($upload)->status)->toBe(DirectUploadStatus::Pending);

    $media = MediaLibrary::completeUpload($upload->token, [1 => $etag1, 2 => '"'.$etag2.'"']);

    expect($media->getPath())->toBe($upload->key)
        ->and($media->size)->toBe(strlen($bytes))
        ->and($media->checksum)->toBe(hash('sha256', $bytes))
        ->and($this->s3->names())->toContain('CompleteMultipartUpload')
        ->and($this->s3->names())->not->toContain('CopyObject')
        ->and($this->s3->names())->not->toContain('PutObject')
        ->and(MediaLibrary::completeUpload($upload->token, [1 => $etag1])->is($media))->toBeTrue();
});

it('rejects a multipart upload whose parts outgrow the ticket, aborting it', function (): void {
    $partSize = 5 * 1024 * 1024;
    $upload = MediaLibrary::for(s3User())->upload('big.txt', $partSize + 10)->multipart($partSize)->useDisk('media-s3')->toMediaBucket('gallery');
    $ticket = s3Ticket($upload);
    $this->s3->uploadPart((string) $ticket->multipart_upload_id, 1, str_repeat('a', $partSize + 1));

    expect(fn () => MediaLibrary::signUploadParts($upload->token, [2]))->toThrow(FileUnacceptableForBucket::class)
        ->and(s3Ticket($upload)->status)->toBe(DirectUploadStatus::Rejected)
        ->and($this->s3->names())->toContain('AbortMultipartUpload')
        ->and($this->s3->multipart)->toBe([])
        ->and(fn () => MediaLibrary::signUploadParts($upload->token, [99]))->toThrow(DirectUploadNotFound::class);
});

it('refuses a part number outside the ticket', function (): void {
    $upload = MediaLibrary::for(s3User())->upload('big.txt', 10)->multipart()->useDisk('media-s3')->toMediaBucket('gallery');

    expect(fn () => MediaLibrary::signUploadParts($upload->token, [2]))->toThrow(InvalidArgumentException::class);
});

it('aborts a multipart upload and prunes stale ones in the store', function (): void {
    config()->set('media.direct_uploads.prune_grace', 0);
    $aborted = MediaLibrary::for(s3User())->upload('a.txt', 10)->multipart()->useDisk('media-s3')->toMediaBucket('gallery');
    MediaLibrary::abortUpload($aborted->token);

    $expired = MediaLibrary::for(s3User())->upload('b.txt', 10)->multipart()->expiresIn(1)->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->multipart['orphan'] = ['key' => '_uploads/orphan', 'parts' => [], 'initiated' => time() - 86400];
    $this->s3->objects['_uploads/left-behind'] = 'x';

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    $pruned = MediaLibrary::pruneUploads();
    CarbonImmutable::setTestNow();

    expect(s3Ticket($aborted)->status)->toBe(DirectUploadStatus::Aborted)
        ->and(s3Ticket($expired)->status)->toBe(DirectUploadStatus::Expired)
        ->and($pruned)->toBe(3)
        ->and($this->s3->multipart)->toBe([])
        ->and($this->s3->objects)->not->toHaveKey('_uploads/left-behind');
});

it('refuses a multipart upload that needs more parts than allowed', function (): void {
    config()->set('media.direct_uploads.multipart.max_parts', 1);

    expect(fn () => MediaLibrary::upload('big.bin', 6 * 1024 * 1024)->multipart(5 * 1024 * 1024)->useDisk('media-s3')->toBucket('library'))
        ->toThrow(DirectUploadsNotSupported::class, 'parts')
        ->and($this->s3->names())->not->toContain('CreateMultipartUpload');
});

it('always asks for response headers on temporary URLs of media adopted in place', function (): void {
    $sha = hash_file('sha256', __DIR__.'/../files/note.txt');
    $upload = MediaLibrary::for(s3User())->upload('note.txt', (int) filesize(__DIR__.'/../files/note.txt'))
        ->withSha256($sha)->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->objects[$upload->key] = (string) file_get_contents(__DIR__.'/../files/note.txt');
    $inPlace = MediaLibrary::adoptUpload($upload->token);

    $moved = MediaLibrary::for(s3User())->upload('note.txt', (int) filesize(__DIR__.'/../files/note.txt'))
        ->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');
    $this->s3->objects[$moved->key] = (string) file_get_contents(__DIR__.'/../files/note.txt');
    $other = MediaLibrary::adoptUpload($moved->token);

    expect(s3Query($inPlace->getTemporaryUrl(now()->addMinutes(5))))->toHaveKey('response-content-disposition')
        ->and(s3Query($other->getTemporaryUrl(now()->addMinutes(5))))->not->toHaveKey('response-content-disposition')
        ->and($inPlace->getPath())->toBe($upload->key);
});
