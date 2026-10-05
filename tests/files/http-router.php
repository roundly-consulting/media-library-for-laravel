<?php

// Router for the suite's local `php -S` server (see Tests\Fixtures\LocalHttpServer).

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/hello.txt') {
    header('Content-Type: text/plain');
    echo 'hello from the pinned server';

    return;
}

if ($path === '/redirect') {
    header('Location: '.($_GET['to'] ?? '/hello.txt'), true, 302);

    return;
}

if ($path === '/endless') {
    // No Content-Length: chunked, and it never ends on its own.
    header('Content-Type: application/octet-stream');
    $chunk = str_repeat('x', 8192);

    while (! connection_aborted()) {
        echo $chunk;
        flush();
    }

    return;
}

http_response_code(404);
