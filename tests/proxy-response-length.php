<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/proxy-response-length.php
 * Requires proc_open and an unused localhost TCP port 9983.
 * Each request executes the real proxy.php against a fake local CODE server.
 */

if (($argv[1] ?? '') === '--request') {
    if (!function_exists('getallheaders')) {
        function getallheaders() { return []; }
    }
    $_SERVER['QUERY_STRING'] = 'req=/test';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/proxy.php';
    require dirname(__DIR__) . '/proxy.php';
    exit(0);
}

$server = stream_socket_server('tcp://127.0.0.1:9983', $errno, $error);
if (!$server) {
    fwrite(STDERR, "Cannot start test backend on localhost:9983: $error\n");
    exit(1);
}

$followingResponse = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
$cases = [
    ['small body', str_repeat('a', 512), '', true],
    ['small body followed by another response', str_repeat('a', 512), $followingResponse, true],
    ['body spanning multiple reads', str_repeat("\0\xff\x17x", 20000), '', true],
    ['large body followed by another response', str_repeat("\0\xff\x17x", 20000), $followingResponse, true],
    ['empty body followed by another response', '', $followingResponse, true],
    ['body without Content-Length', 'read until EOF', '', false],
];
$failures = 0;

foreach ($cases as [$name, $expected, $following, $hasLength]) {
    $process = proc_open([PHP_BINARY, __FILE__, '--request'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start proxy process');
    }
    fclose($pipes[0]);
    $connection = stream_socket_accept($server, 5);
    if (!$connection) {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new RuntimeException('Proxy did not connect to the test backend');
    }
    stream_set_timeout($connection, 5);
    $request = '';
    while (strpos($request, "\r\n\r\n") === false) {
        $part = fread($connection, 8192);
        if ($part === false || $part === '') {
            throw new RuntimeException('Incomplete request to test backend');
        }
        $request .= $part;
    }
    $response = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\n";
    if ($hasLength) {
        $response .= 'Content-Length: ' . strlen($expected) . "\r\n";
    }
    $response .= "\r\n" . $expected . $following;
    $offset = 0;
    while ($offset < strlen($response)) {
        $written = fwrite($connection, substr($response, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write test response');
        }
        $offset += $written;
    }
    fclose($connection);
    stream_set_timeout($pipes[1], 5);
    $actual = stream_get_contents($pipes[1]);
    if (stream_get_meta_data($pipes[1])['timed_out']) {
        proc_terminate($process);
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $passed = $status === 0 && $stderr === '' && $actual === $expected;
    if (!$passed) {
        $failures++;
    }
    printf("%s: %s (expected %d bytes, received %d)\n", $passed ? 'PASS' : 'FAIL', $name, strlen($expected), strlen($actual));
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
}
fclose($server);
exit($failures === 0 ? 0 : 1);
