<?php

// A raw HTTP server for the suite (see Tests\Fixtures\RawHttpServer): every connection gets the
// same bytes back, verbatim — what PHP's built-in server cannot send, such as a 1xx before the
// final answer.

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $errorCode, $errorMessage);
$answer = (string) base64_decode($argv[2], true);

if ($server === false) {
    exit(1);
}

while (true) {
    $client = @stream_socket_accept($server, 60);

    if ($client === false) {
        continue;
    }

    // Read the request head, then answer and close.
    $head = '';

    while (! str_contains($head, "\r\n\r\n") && ($chunk = fread($client, 8192)) !== false && $chunk !== '') {
        $head .= $chunk;
    }

    fwrite($client, $answer);
    fclose($client);
}
