<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/proxy-multipart.php
 * Requires proc_open and an unused localhost TCP port 9983.
 * Each request executes the real proxy.php with $_POST and $_FILES as PHP fills them from a
 * multipart/form-data request, against a fake local CODE server that records the body it gets.
 */

if (($argv[1] ?? '') === '--request') {
    $request = json_decode(getenv('TEST_REQUEST'), true);
    if (!function_exists('getallheaders')) {
        function getallheaders() { return ['Content-Type' => $_SERVER['CONTENT_TYPE']]; }
    }
    $_SERVER['QUERY_STRING'] = 'req=/cool/clipboard?Tag=1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['SCRIPT_NAME'] = '/proxy.php';
    $_SERVER['CONTENT_TYPE'] = $request['contentType'];
    $_SERVER['CONTENT_LENGTH'] = (string)$request['contentLength'];
    $_POST = $request['post'];
    $_FILES = $request['files'];
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

$tmpDir = sys_get_temp_dir() . '/richdocumentscode-test-' . getmypid();
mkdir($tmpDir, 0700);
// The proxy process of the case under way, stopped if the test ends early
$process = null;
register_shutdown_function(function () use ($tmpDir, &$process) {
    if (is_resource($process)) {
        proc_terminate($process);
    }
    array_map('unlink', glob("$tmpDir/*"));
    rmdir($tmpDir);
});

$html = "<!DOCTYPE html>\r\n<html><body><table><tr><td>42</td></tr></table></body></html>";
file_put_contents("$tmpDir/upload", $html);
$binary = '';
for ($i = 0; $i < 70000; $i++) {
    $binary .= chr($i % 256);
}
file_put_contents("$tmpDir/binary", $binary);

function file_entry($name, $type, $tmpName, $error = UPLOAD_ERR_OK)
{
    return ['name' => $name, 'type' => $type, 'tmp_name' => $tmpName, 'error' => $error, 'size' => 0];
}

function file_part($delimiter, $name, $filename, $type, $content)
{
    return "$delimiter\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\n" .
        ($type !== '' ? "Content-Type: $type\r\n" : '') . "\r\n$content\r\n";
}

$type = 'multipart/form-data; boundary=----geckoformboundary1234';
$delimiter = "------geckoformboundary1234";
$paste = file_entry('blob', 'text/html', "$tmpDir/upload");

// Each case gives $_POST, $_FILES, the ini settings, the Content-Type and Content-Length, and
// either the body coolwsd is expected to get or the status and a part of the error message
$cases = [
    'file of a paste' => [
        'files' => ['file' => $paste],
        'body' => file_part($delimiter, 'file', 'blob', 'text/html', $html) . "$delimiter--\r\n"],
    'file under another name and without a type' => [
        'files' => ['data' => file_entry('clipboard', '', "$tmpDir/upload")],
        'body' => file_part($delimiter, 'data', 'clipboard', '', $html) . "$delimiter--\r\n"],
    'fields and binary files' => [
        'post' => ['format' => 'pdf', 'options' => '{}'],
        'files' => ['data' => file_entry('a.odt', 'application/octet-stream', "$tmpDir/binary"),
            'template' => file_entry('b.ott', 'application/octet-stream', "$tmpDir/binary")],
        'body' => "$delimiter\r\nContent-Disposition: form-data; name=\"format\"\r\n\r\npdf\r\n" .
            "$delimiter\r\nContent-Disposition: form-data; name=\"options\"\r\n\r\n{}\r\n" .
            file_part($delimiter, 'data', 'a.odt', 'application/octet-stream', $binary) .
            file_part($delimiter, 'template', 'b.ott', 'application/octet-stream', $binary) . "$delimiter--\r\n"],
    'field and file with quotes in their names' => [
        'post' => ['a "b"' => 'value'],
        'files' => ['file' => file_entry("c\"d\r\n", 'text/html', "$tmpDir/upload")],
        'body' => "$delimiter\r\nContent-Disposition: form-data; name=\"a %22b%22\"\r\n\r\nvalue\r\n" .
            file_part($delimiter, 'file', 'c%22d%0D%0A', 'text/html', $html) . "$delimiter--\r\n"],
    'quoted boundary' => [
        'contentType' => 'multipart/form-data; boundary="b c"; charset=utf-8',
        'post' => ['f' => 'v'],
        'body' => "--b c\r\nContent-Disposition: form-data; name=\"f\"\r\n\r\nv\r\n--b c--\r\n"],
    'boundary after another parameter' => [
        'contentType' => 'multipart/form-data; charset=utf-8; boundary=xyz',
        'post' => ['f' => 'v'],
        'body' => "--xyz\r\nContent-Disposition: form-data; name=\"f\"\r\n\r\nv\r\n--xyz--\r\n"],
    'empty file input' => [
        'files' => ['file' => file_entry('', '', '', UPLOAD_ERR_NO_FILE)],
        'body' => file_part($delimiter, 'file', '', '', '') . "$delimiter--\r\n"],
    'request without a body' => [
        'contentLength' => 0,
        'body' => ''],
    'no part kept' => [
        'body' => "$delimiter--\r\n"],
    'fields with file uploads off' => [
        'ini' => ['file_uploads' => '0'],
        'post' => ['url' => 'https://example.com/a.png'],
        'body' => "$delimiter\r\nContent-Disposition: form-data; name=\"url\"\r\n\r\nhttps://example.com/a.png\r\n$delimiter--\r\n"],
    'file uploads off' => [
        'ini' => ['file_uploads' => '0'],
        'status' => 500, 'message' => "file_uploads setting is off"],
    'max_file_uploads of 0' => [
        'ini' => ['max_file_uploads' => '0'],
        'status' => 500, 'message' => "max_file_uploads setting is 0"],
    'field named like an array' => [
        'post' => ['a' => ['1', '2']],
        'status' => 400, 'message' => "no fields named like a[]"],
    'files named like an array' => [
        'files' => ['f' => ['name' => ['x', 'y'], 'type' => ['', ''], 'tmp_name' => ['', ''], 'error' => [0, 0], 'size' => [0, 0]]],
        'status' => 400, 'message' => "no files named like f[]"],
    'file over upload_max_filesize' => [
        'files' => ['file' => file_entry('blob', 'text/html', '', UPLOAD_ERR_INI_SIZE)],
        'status' => 413, 'message' => "larger than maximum up-load file-size"],
    'partial upload' => [
        'files' => ['file' => file_entry('blob', 'text/html', '', UPLOAD_ERR_PARTIAL)],
        'status' => 400, 'message' => "ended before the whole file arrived"],
    'file PHP could not store' => [
        'files' => ['file' => file_entry('blob', 'text/html', '', UPLOAD_ERR_CANT_WRITE)],
        'status' => 500, 'message' => "could not store the uploaded file blob (error 7). Check the upload_tmp_dir"],
    'stored file that cannot be read' => [
        'files' => ['file' => file_entry('blob', 'text/html', "$tmpDir/missing")],
        'status' => 500, 'message' => "cannot read it"],
];
$failures = 0;

foreach ($cases as $name => $case) {
    $case += ['post' => [], 'files' => [], 'ini' => [], 'contentType' => $type, 'contentLength' => 1000, 'status' => 200];
    $command = [PHP_BINARY, '-d', "upload_tmp_dir=$tmpDir"];
    foreach ($case['ini'] as $key => $value) {
        array_push($command, '-d', "$key=$value");
    }
    array_push($command, __FILE__, '--request');
    $request = ['contentType' => $case['contentType'], 'contentLength' => $case['contentLength'],
        'post' => $case['post'], 'files' => $case['files']];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['TEST_REQUEST' => json_encode($request)]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start proxy process');
    }
    fclose($pipes[0]);
    $connection = stream_socket_accept($server, 5);
    if (!$connection) {
        throw new RuntimeException('Proxy did not connect to the test backend');
    }
    stream_set_timeout($connection, 5);
    // The whole request, or what the proxy sent before it gave up and closed the connection
    $received = '';
    $length = null;
    while (true) {
        $end = strpos($received, "\r\n\r\n");
        if ($end !== false && $length === null) {
            preg_match('/^Content-Length: (\d+)\r$/mi', substr($received, 0, $end), $match);
            $length = $end + 4 + (int)($match[1] ?? 0);
        }
        if ($length !== null && strlen($received) >= $length) {
            break;
        }
        $part = fread($connection, 65536);
        if ($part === false || $part === '') {
            break;
        }
        $received .= $part;
    }
    if ($length !== null) {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
    }
    fclose($connection);
    stream_set_timeout($pipes[1], 5);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $process = null;

    preg_match('/^status (\d+)$/m', $stderr, $match);
    $status = (int)($match[1] ?? 0);
    $end = strpos($received, "\r\n\r\n");
    $body = $end === false ? null : substr($received, $end + 4);
    if ($case['status'] === 200) {
        $passed = $status === 200 && $body === $case['body'];
    } else {
        $passed = $status === $case['status'] && $body === null && strpos($stderr, $case['message']) !== false;
    }
    if (!$passed) {
        $failures++;
    }
    printf("%s: %s (expected status %d, received %d)\n", $passed ? 'PASS' : 'FAIL', $name, $case['status'], $status);
    if (!$passed) {
        fwrite(STDERR, "body sent to coolwsd: " . var_export($body === null ? null : substr($body, 0, 300), true) . "\n$stderr");
    }
}
fclose($server);
exit($failures === 0 ? 0 : 1);
