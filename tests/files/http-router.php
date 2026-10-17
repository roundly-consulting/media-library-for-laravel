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

if ($path === '/gzip-bomb') {
    // 2 MiB of 'a', a few KiB on the wire: only a cap on DECODED bytes stops it.
    $body = (string) gzencode(str_repeat('a', 2 * 1048576), 9);
    header('Content-Type: text/plain');
    header('Content-Encoding: gzip');
    header('Content-Length: '.strlen($body));
    echo $body;

    return;
}

if ($path === '/unavailable-endless' || $path === '/redirect-endless') {
    // An answer that is not a success, with a body that never ends: only its status may count.
    if ($path === '/unavailable-endless') {
        header('Retry-After: 7');
        http_response_code(503);
    } else {
        header('Location: /hello.txt', true, 302);
    }

    $chunk = str_repeat('x', 8192);

    while (! connection_aborted()) {
        echo $chunk;
        flush();
    }

    return;
}

if ($path === '/unavailable') {
    header('Retry-After: 7');
    http_response_code(503);
    echo 'come back later';

    return;
}

http_response_code(404);
