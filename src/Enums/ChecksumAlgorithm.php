<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Enums;

/**
 * The hash algorithms `media.checksum_algorithm` accepts.
 *
 * The checksum is the deduplication key: two uploads with the same digest share ONE stored file.
 * A hash with practical collisions (md5, sha1, crc32…) would let a crafted upload take over the
 * bytes of someone else's media, so only collision-resistant SHA-2/SHA-3 digests of 256 bits or
 * more are allowed. The longest (512-bit) fits the 128-character `checksum` column.
 */
enum ChecksumAlgorithm: string
{
    case Sha256 = 'sha256';
    case Sha384 = 'sha384';
    case Sha512 = 'sha512';
    case Sha512_256 = 'sha512/256';
    case Sha3_256 = 'sha3-256';
    case Sha3_384 = 'sha3-384';
    case Sha3_512 = 'sha3-512';
}
