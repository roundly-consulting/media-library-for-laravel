<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Support\Str;

/**
 * A local file opened through a byte budget: writes past the {@see SizeLimit} are refused with a
 * short write count, never an error.
 *
 * That count is the point. As a download's sink, a short write is what makes curl abort the
 * transfer at once — however the server sends the body (no Content-Length, chunked, lying about
 * its length) — whereas a failed write only throws inside curl's callback, which curl ignores and
 * keeps receiving until the timeout. `Http::fake()` writes its body through the same handle.
 *
 * A PHP stream wrapper, so it is opened with `fopen()`; use {@see self::open()}.
 *
 * @internal
 */
final class CappedFileStream
{
    private const PROTOCOL = 'media-library-capped';

    /** @var array<string, array{0: string, 1: SizeLimit}> files waiting to be opened, by id */
    private static array $pending = [];

    /** @var resource|null set by PHP for every stream wrapper */
    public $context;

    /** @var resource|null */
    private $file;

    private ?SizeLimit $limit = null;

    /**
     * Open `$path` for writing (truncated) through `$limit`.
     *
     * @return resource|false
     */
    public static function open(string $path, SizeLimit $limit)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $id = Str::random(32);
        self::$pending[$id] = [$path, $limit];

        try {
            return @fopen(self::PROTOCOL.'://'.$id, 'w+b');
        } finally {
            unset(self::$pending[$id]);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $entry = self::$pending[substr($path, strlen(self::PROTOCOL) + 3)] ?? null;

        if ($entry === null) {
            return false;
        }

        $file = @fopen($entry[0], $mode);

        if ($file === false) {
            return false;
        }

        $this->file = $file;
        $this->limit = $entry[1];

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->file === null || $this->limit === null) {
            return 0;
        }

        $room = $this->limit->maxBytes - $this->limit->written;

        if (strlen($data) > $room) {
            $this->limit->exceeded = true;
            $data = substr($data, 0, max(0, $room));
        }

        if ($data === '') {
            return 0;
        }

        $written = fwrite($this->file, $data);
        $written = $written === false ? 0 : $written;
        $this->limit->written += $written;

        return $written;
    }

    public function stream_read(int $count): string|false
    {
        return $this->file === null ? false : fread($this->file, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->file === null || feof($this->file);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->file !== null && fseek($this->file, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return $this->file === null ? 0 : (int) ftell($this->file);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->file === null ? false : fstat($this->file);
    }

    public function stream_flush(): bool
    {
        return $this->file !== null && fflush($this->file);
    }

    public function stream_close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
    }
}
