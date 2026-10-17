<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

/**
 * A remote URL the guard parsed itself, rebuilt from its parts — see {@see RemoteUrlGuard}.
 *
 * @internal
 */
final readonly class CanonicalUrl
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public bool $ipLiteral,
        public string $pathAndQuery,
    ) {}

    /** The URL a request is sent to: no user info, no fragment, no default port. */
    public function toString(): string
    {
        return $this->scheme.'://'.$this->authority().$this->pathAndQuery;
    }

    private function authority(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->bracketedHost().($this->port === $default ? '' : ':'.$this->port);
    }

    private function bracketedHost(): string
    {
        return str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
    }
}
