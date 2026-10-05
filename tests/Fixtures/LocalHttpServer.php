<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use RuntimeException;

/**
 * A real HTTP server on 127.0.0.1 (PHP's built-in one), so `addFromUrl()` can be driven through
 * Laravel's real HTTP client and curl rather than `Http::fake()`. A random port per start keeps
 * parallel test processes apart.
 */
final class LocalHttpServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
    ) {}

    public static function start(): self
    {
        $router = __DIR__.'/../files/http-router.php';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );

            if (! is_resource($process)) {
                continue;
            }

            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

                if (is_resource($socket)) {
                    fclose($socket);

                    return new self($process, $port);
                }

                if (! proc_get_status($process)['running']) {
                    break;
                }

                usleep(50_000);
            }

            proc_terminate($process);
        }

        throw new RuntimeException('The local HTTP server did not start.');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
