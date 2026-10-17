<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Enums;

use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;

/**
 * Why a remote URL was refused or could not be fetched — {@see RemoteFetchFailed::reason()}.
 *
 * The first seven are the guard's verdicts on a URL (`vet()`), the vocabulary the blocked-network
 * contract fixture shares with the Node twin; the rest come from fetching it.
 */
enum RemoteFetchReason: string
{
    /** Not an absolute URL the guard can parse (no scheme, no `//` authority, a control character). */
    case InvalidUrl = 'invalid_url';

    /** Not `http` or `https`. */
    case Scheme = 'scheme';

    /** A user name or password in the URL. */
    case Credentials = 'credentials';

    /** A host spelled so parsers could disagree on it: numeric IPv4 forms, `%`, `\`, a zone id. */
    case NonCanonicalHost = 'non_canonical_host';

    /** A private, loopback, link-local, metadata or reserved destination (or `localhost`). */
    case Blocked = 'blocked';

    /** A port outside 1–65535, or outside `media.remote.allowed_ports`. */
    case Port = 'port';

    /** The host name does not resolve. Retryable: DNS can heal. */
    case Unresolved = 'unresolved';

    case TooManyRedirects = 'too_many_redirects';

    /** The body passed the byte cap (counted after decoding). */
    case TooLarge = 'too_large';

    /** The connection cannot be pinned to the vetted addresses (no ext-curl). */
    case CannotPin = 'cannot_pin';

    /** The server answered with a status other than 2xx; see `status()`. */
    case Status = 'status';

    /** The TLS certificate could not be verified. */
    case Tls = 'tls';

    /** The connection failed (refused, reset). */
    case Connection = 'connection';

    /** The total time budget ran out. */
    case Timeout = 'timeout';
}
