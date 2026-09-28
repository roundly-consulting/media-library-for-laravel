<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams (or downloads) a media file behind a signed route (§9.2).
 *
 * The framework `signed` middleware enforces the signature; this action additionally verifies —
 * defense in depth — that the resolved media is the one the signature was minted for, then
 * streams via Laravel's Storage. Active content (HTML, SVG, …) is sent as a sandboxed attachment,
 * never rendered inline on the application's origin — see {@see Media::toResponse()}.
 */
final class MediaStreamController
{
    public function __invoke(Request $request, Media $media, string $variant = ''): StreamedResponse
    {
        $this->ensureSignedForThisMedia($request, $media);
        $this->ensureFileExists($media, $variant);

        if ($request->boolean('download')) {
            return $media->toDownloadResponse(null, $variant);
        }

        return $media->toResponse($request);
    }

    private function ensureSignedForThisMedia(Request $request, Media $media): void
    {
        $signed = $request->route('media');

        if (is_string($signed) && $signed !== $media->uuid) {
            throw new NotFoundHttpException;
        }
    }

    private function ensureFileExists(Media $media, string $variant): void
    {
        if ($variant !== '' && ! $media->hasGeneratedVariant($variant)) {
            throw new NotFoundHttpException;
        }

        $disk = $media->diskFor($variant);

        if (! Storage::disk($disk)->exists($media->getPath($variant))) {
            throw new NotFoundHttpException;
        }
    }
}
