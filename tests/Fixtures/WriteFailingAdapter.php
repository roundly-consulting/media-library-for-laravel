<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * A local disk that refuses writes — what a refused S3 PutObject (a full bucket, a denied
 * policy, an outage) looks like to Laravel. Behind a disk with `'throw' => false`, Laravel's
 * adapter turns the refusal into a plain `false` result, which is exactly the case a caller must
 * check for.
 *
 * `$failingPaths` narrows the refusal to paths matching a pattern (e.g. only variant files), and
 * `$failVisibility` makes explicit visibility changes fail (a write's own visibility still lands).
 */
final class WriteFailingAdapter extends LocalFilesystemAdapter
{
    private bool $writing = false;

    public function __construct(
        string $root,
        private readonly ?string $failingPaths = null,
        private readonly bool $failVisibility = false,
        private readonly bool $failWrites = true,
    ) {
        parent::__construct($root);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->refuse($path);

        $this->writing = true;

        try {
            parent::write($path, $contents, $config);
        } finally {
            $this->writing = false;
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->refuse($path);

        $this->writing = true;

        try {
            parent::writeStream($path, $contents, $config);
        } finally {
            $this->writing = false;
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->refuse($destination);

        parent::copy($source, $destination, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($this->failVisibility && ! $this->writing) {
            throw UnableToSetVisibility::atLocation($path, 'the disk refuses visibility changes');
        }

        parent::setVisibility($path, $visibility);
    }

    private function refuse(string $path): void
    {
        if ($this->failWrites && ($this->failingPaths === null || preg_match($this->failingPaths, $path) === 1)) {
            throw UnableToWriteFile::atLocation($path, 'the disk refuses writes');
        }
    }
}
