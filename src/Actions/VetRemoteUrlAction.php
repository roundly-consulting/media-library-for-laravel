<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\VettedUrl;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;
use RoundlyConsulting\MediaLibrary\Support\RemoteUrlGuard;

/**
 * The guard's verdict on a URL, without fetching it — `MediaLibrary::remote()->vet()`.
 *
 * The URL is canonicalised, its host resolved and every address judged, exactly as a fetch's
 * first hop would be; `media.remote.allowed_private_hosts` and `allowed_ports` apply. It judges
 * even when `media.remote.block_private_networks` switches the fetch guard off.
 */
final readonly class VetRemoteUrlAction
{
    public function __construct(
        private RemoteUrlGuard $guard,
    ) {}

    /**
     * @throws RemoteFileRejected when the URL is refused
     * @throws RemoteFileUnavailable when its host does not resolve (retryable: DNS can heal)
     */
    public function execute(string $url): VettedUrl
    {
        return $this->guard->vet($url);
    }
}
