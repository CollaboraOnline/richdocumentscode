<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/proxy-empty-reply.php
 * Requires proc_open and an unused localhost TCP port 9983.
 * Each request executes the real proxy.php against a fake local CODE server that reads the request
 * and closes the connection without answering, or keeps it open past the read timeout, and checks
 * the status and the error message, which must not hold the access token.
 */

if (($argv[1] ?? '') === '--request') {
    if (!function_exists('getallheaders')) {
        function getallheaders() { return []; }
    }
    $_SERVER['QUERY_STRING'] = 'req=' . getenv('TEST_REQ');
    $_SERVER['REQUEST_METHOD'] = getenv('TEST_METHOD');
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['SCRIPT_NAME'] = '/proxy.php';
    register_shutdown_function(function () {
        fwrite(STDERR, 'status ' . (http_response_code() ?: 200) . "\n");
    });
    require dirname(__DIR__) . '/proxy.php';
    exit(0);
}

$server = stream_socket_server('tcp://127.0.0.1:9983', $errno, $error);
if (!$server) {
    fwrite(STDERR, "Cannot start test backend on localhost:9983: $error\n");
    exit(1);
}

$token = 'SECRETTOKEN0123456789';
$sessionId = '34A87BFBDFE2FD165DE9E89C3EBDC809';
$downloadId = 'aqns2byXxi2A9O2M';
// None of these may reach the log or the client
$secrets = [$token, $sessionId, $downloadId, 'cloud.example.com', 'secretword'];
$wopiSrc = rawurlencode('https://cloud.example.com/index.php/apps/richdocuments/wopi/files/386_oc');
$document = rawurlencode("https://cloud.example.com/wopi/files/386?access_token=$token");
// name, method, req= of the request, seconds the backend keeps the connection open, expected
// status and expected message, and what the backend sends before it waits, with the output the
// client is expected to get then
$cases = [
    ['compat close', 'POST', "/cool/ws?WOPISrc=$wopiSrc&access_token=$token&compat=/0255ABCDEF/close/64", 0,
        502, "closed the connection without answering POST /cool/ws (compat close)."],
    ['document URL in the path', 'POST', "/cool/$document/ws?WOPISrc=$wopiSrc&compat=/ws/open/open/1", 0,
        502, "closed the connection without answering POST /cool/.../ws (compat open)."],
    ['encoded compat parameter', 'POST', "/cool/ws?WOPISrc=$wopiSrc&compat=%2Fws%2F$sessionId%2Fwait%2F7", 0,
        502, "closed the connection without answering POST /cool/ws (compat wait)."],
    ['session id in the path', 'POST', "/cool/$document/ws/$sessionId/close/5", 0,
        502, "closed the connection without answering POST /cool/.../ws/.../close/...."],
    ['download id in the path', 'GET', "/cool/$document/download/$downloadId", 0,
        502, "closed the connection without answering GET /cool/.../download/...."],
    ['unknown compat command', 'POST', "/cool/ws?WOPISrc=$wopiSrc&compat=/ws/$sessionId/secretword/1", 0,
        502, "closed the connection without answering POST /cool/ws."],
    ['plain request', 'GET', '/hosting/discovery', 0,
        502, "closed the connection without answering GET /hosting/discovery."],
    ['answer ending among its headers', 'GET', '/hosting/discovery', 0,
        502, "closed the connection without answering GET /hosting/discovery.", "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nX-Fo"],
    ['answer ending after its headers', 'GET', '/hosting/discovery', 0,
        502, "closed the connection without answering GET /hosting/discovery.", "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n"],
    ['answer ending early', 'GET', '/hosting/discovery', 0,
        200, "to GET /hosting/discovery ended early.", "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n0123456789", '0123456789'],
    ['no answer in time', 'GET', '/hosting/discovery', 2,
        504, "did not answer GET /hosting/discovery in time."],
    ['answer stopping after its headers', 'GET', '/hosting/discovery', 2,
        504, "did not answer GET /hosting/discovery in time.", "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n"],
    ['answer cut short by the timeout', 'GET', '/hosting/discovery', 2,
        200, "to GET /hosting/discovery stopped coming in time.", "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n0123456789", '0123456789'],
    ['answer to a HEAD request', 'HEAD', '/hosting/discovery', 2,
        200, '', "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n", ''],
    ['304 answer', 'GET', '/hosting/discovery', 2,
        304, '', "HTTP/1.1 304 Not Modified\r\nContent-Length: 100\r\n\r\n", ''],
];
$failures = 0;

// What the proxy writes to its output and to its error stream until it ends, or until the
// deadline, when it is stopped
function read_output($process, array $pipes, int $seconds): array
{
    $streams = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $deadline = microtime(true) + $seconds;
    while ($open && microtime(true) < $deadline) {
        $read = array_values($open);
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 200000) === false) {
            break;
        }
        foreach ($open as $i => $pipe) {
            if (in_array($pipe, $read, true)) {
                $data = fread($pipe, 65536);
                if ($data !== false && $data !== '') {
                    $streams[$i] .= $data;
                } elseif (feof($pipe)) {
                    unset($open[$i]);
                }
            }
        }
    }
    if ($open) {
        proc_terminate($process);
    }
    return $streams;
}

foreach ($cases as $case) {
    [$name, $method, $req, $hold, $expectedStatus, $expected] = $case;
    $send = $case[6] ?? '';
    $expectedOutput = $case[7] ?? null;
    $process = proc_open([PHP_BINARY, '-d', 'default_socket_timeout=1', __FILE__, '--request'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, getenv() + ['TEST_REQ' => $req, 'TEST_METHOD' => $method]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start proxy process');
    }
    fclose($pipes[0]);
    $connection = stream_socket_accept($server, 5);
    if (!$connection) {
        proc_terminate($process);
        throw new RuntimeException('Proxy did not connect to the test backend');
    }
    stream_set_timeout($connection, 5);
    $request = '';
    while (strpos($request, "\r\n\r\n") === false) {
        $part = fread($connection, 8192);
        if ($part === false || $part === '') {
            break;
        }
        $request .= $part;
    }
    fwrite($connection, $send);
    sleep($hold);
    fclose($connection);
    [1 => $output, 2 => $stderr] = read_output($process, $pipes, 10);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    preg_match('/^status (\d+)$/m', $stderr, $match);
    $status = (int)($match[1] ?? 0);
    $passed = $status === $expectedStatus && strpos($stderr, $expected) !== false
        && count(array_filter($secrets, fn($secret) => strpos($stderr . $output, $secret) !== false)) === 0
        && ($expectedOutput === null || $output === $expectedOutput);
    if (!$passed) {
        $failures++;
    }
    printf("%s: %s (expected status %d, received %d)\n", $passed ? 'PASS' : 'FAIL', $name, $expectedStatus, $status);
    if (!$passed) {
        fwrite(STDERR, "output: " . var_export($output, true) . "\n$stderr");
    }
}
fclose($server);
exit($failures === 0 ? 0 : 1);
