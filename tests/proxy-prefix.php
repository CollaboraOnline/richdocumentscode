<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/proxy-prefix.php
 * Requires PHP 8.1, proc_open and an unused localhost TCP port 9983.
 * Each request executes the real proxy.php, with the host settings of the case in a temporary
 * directory of its own, against a fake local CODE server that records the ProxyPrefix header.
 */

if (($argv[1] ?? '') === '--request') {
    if (!function_exists('getallheaders')) {
        function getallheaders() { return []; }
    }
    $_SERVER['QUERY_STRING'] = 'req=/test';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = getenv('TEST_REMOTE_ADDR');
    $_SERVER['SCRIPT_NAME'] = '/proxy.php';
    if (getenv('TEST_HTTPS') === 'on') {
        $_SERVER['HTTPS'] = 'on';
    }
    require dirname(__DIR__) . '/proxy.php';
    exit(0);
}

$server = stream_socket_server('tcp://127.0.0.1:9983', $errno, $error);
if (!$server) {
    fwrite(STDERR, "Cannot start test backend on localhost:9983: $error\n");
    exit(1);
}

// The PHP temporary directory of the proxy, which holds the settings directory
$tmpDir = sys_get_temp_dir() . '/richdocumentscode-test-' . getmypid();
$settingsDir = $tmpDir . '/richdocumentscode-' . posix_geteuid();
mkdir($tmpDir, 0700);
mkdir($settingsDir, 0700);
touch($tmpDir . '/enabled');

$settings = [
    'wopiHosts' => ['cloud\\.example\\.com'],
    'wopiGroupHost' => 'cloud.example.com',
    'lokAllowHosts' => [],
    'fontConfigUrl' => '',
    'enabledPath' => $tmpDir . '/enabled',
];
$overwrite = [
    'overwriteProtocol' => 'https',
    'overwriteHost' => 'cloud.example.com:8443',
    'overwriteCondAddr' => '',
    'proxyPath' => '/nc/apps/richdocumentscode/proxy.php',
];
$overwritten = 'https://cloud.example.com:8443/nc/apps/richdocumentscode/proxy.php?req=';
$plain = 'http://localhost/proxy.php?req=';

// name, settings file (null for none), HTTPS, client address, expected ProxyPrefix
$cases = [
    ['no settings file', null, false, '127.0.0.1', $plain],
    ['settings of an earlier release', $settings, false, '127.0.0.1', $plain],
    ['https request', $settings + ['overwriteProtocol' => ''], true, '127.0.0.1', 'https://localhost/proxy.php?req='],
    ['overwrite settings', $settings + $overwrite, false, '127.0.0.1', $overwritten],
    ['overwrite settings for other clients', $settings + ['overwriteCondAddr' => '^10\\.'] + $overwrite, false, '127.0.0.1', $plain],
    ['overwrite settings for this client', $settings + ['overwriteCondAddr' => '^127\\.'] + $overwrite, false, '127.0.0.1', $overwritten],
    ['overwritten host only', $settings + ['overwriteHost' => 'cloud.example.com'], true, '127.0.0.1', 'https://cloud.example.com/proxy.php?req='],
    ['overwritten path only', $settings + ['proxyPath' => '/nc/apps/richdocumentscode/proxy.php'], false, '127.0.0.1', 'http://localhost/nc/apps/richdocumentscode/proxy.php?req='],
];
$failures = 0;

foreach ($cases as [$name, $fileSettings, $https, $address, $expected]) {
    $settingsPath = $settingsDir . '/coolwsd-hosts.json';
    if ($fileSettings === null) {
        @unlink($settingsPath);
    } else {
        file_put_contents($settingsPath, json_encode($fileSettings, JSON_UNESCAPED_SLASHES));
    }
    $process = proc_open([PHP_BINARY, '-d', "upload_tmp_dir=$tmpDir", __FILE__, '--request'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['TEST_HTTPS' => $https ? 'on' : 'off', 'TEST_REMOTE_ADDR' => $address]);
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
    $actual = preg_match('/^ProxyPrefix: (.*)\r$/m', $request, $match) ? $match[1] : '(no ProxyPrefix header)';
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
    fclose($connection);
    stream_set_timeout($pipes[1], 5);
    stream_get_contents($pipes[1]);
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
    printf("%s: %s (expected %s, received %s)\n", $passed ? 'PASS' : 'FAIL', $name, $expected, $actual);
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
}
fclose($server);
array_map('unlink', glob("$settingsDir/*"));
rmdir($settingsDir);
unlink($tmpDir . '/enabled');
rmdir($tmpDir);
exit($failures === 0 ? 0 : 1);
