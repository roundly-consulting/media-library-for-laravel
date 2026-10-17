<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use RuntimeException;

/**
 * A raw HTTP server on 127.0.0.1 that answers every request with the same bytes, verbatim — for
 * answers PHP's built-in server cannot give (an informational `103` before the final one), sent
 * through Laravel's real HTTP client and curl. A random port per start keeps parallel test
 * processes apart.
 */
final class RawHttpServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
    ) {}

    public static function start(string $answer): self
    {
        $script = __DIR__.'/../files/raw-http-server.php';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, $script, (string) $port, base64_encode($answer)],
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

        throw new RuntimeException('The raw HTTP server did not start.');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
