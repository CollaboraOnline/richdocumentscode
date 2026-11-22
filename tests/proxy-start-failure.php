<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/proxy-start-failure.php
 * Requires PHP 8, an x86_64 Linux host, proc_open, bash, libfontconfig.so.1, a temporary directory
 * that allows running programs, nothing listening on localhost TCP port 9983 and no process named
 * coolwsd of the user who runs it, as proxy.php stops those. Runs a copy of proxy.php whose AppImage is a script, and checks that a launch that
 * fails is reported at once and not tried again right away, and that the first request after an
 * app update, which unpacks the AppImage without its execute permission while the old coolwsd
 * still runs, gets an answer from the new coolwsd. The AppImage keeps the environment, with TMPDIR
 * set to the temp_directory setting when there is one.
 */

if (($argv[1] ?? '') === '--request') {
    if (!function_exists('getallheaders')) {
        function getallheaders() { return []; }
    }
    $_SERVER['QUERY_STRING'] = getenv('TEST_QUERY');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['SCRIPT_NAME'] = '/proxy.php';
    require $argv[2] . '/proxy.php';
    exit(0);
}

// The processes named coolwsd of this user
function coolwsd_pids(): array
{
    $pids = [];
    foreach ((array)@scandir('/proc') as $entry) {
        $stat = ctype_digit((string)$entry) ? @stat("/proc/$entry") : false;
        if ($stat !== false && $stat['uid'] === posix_geteuid()
            && rtrim((string)@file_get_contents("/proc/$entry/comm")) === 'coolwsd') {
            $pids[] = (int)$entry;
        }
    }
    return $pids;
}

if (@fsockopen('127.0.0.1', 9983, $errno, $error, 1) || coolwsd_pids() !== []) {
    fwrite(STDERR, "Something listens on localhost:9983 or a coolwsd of this user runs; not running the test, as proxy.php would stop it.\n");
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/richdocumentscode-test-' . getmypid();
$appDir = "$tmpDir/app";
$settingsDir = $tmpDir . '/richdocumentscode-' . posix_geteuid();
foreach ([$tmpDir, $appDir, "$appDir/collabora", $settingsDir] as $dir) {
    mkdir($dir, 0700);
}
// The coolwsd processes the test started, which it stops at the end
$started = [];
register_shutdown_function(function () use ($tmpDir, &$started) {
    foreach ($started as $pid) {
        posix_kill($pid, 9);
    }
    exec('rm -rf ' . escapeshellarg($tmpDir));
});

// The app with its own copy of proxy.php, as proxy.php finds the AppImage next to itself
copy(dirname(__DIR__) . '/proxy.php', "$appDir/proxy.php");
exec('cp -r ' . escapeshellarg(dirname(__DIR__) . '/lib') . ' ' . escapeshellarg($appDir));
touch("$tmpDir/enabled");
function write_settings(string $tmpDirectory): void
{
    global $tmpDir, $settingsDir;
    file_put_contents("$settingsDir/coolwsd-hosts.json", json_encode([
        'wopiHosts' => ['localhost'],
        'wopiGroupHost' => 'localhost',
        'lokAllowHosts' => [],
        'fontConfigUrl' => '',
        'enabledPath' => "$tmpDir/enabled",
        'tmpDirectory' => $tmpDirectory,
    ], JSON_UNESCAPED_SLASHES));
}
write_settings('');

// A coolwsd that listens on the port, writes the pid file it is given and answers every request
// with its own pid
$coolwsd = "$appDir/collabora/coolwsd";
file_put_contents($coolwsd, '#!' . PHP_BINARY . <<<'EOF'

<?php
foreach ($argv as $arg) {
    if (strpos($arg, '--pidfile=') === 0) {
        $pidFile = substr($arg, strlen('--pidfile='));
    }
}
$server = stream_socket_server('tcp://127.0.0.1:9983', $errno, $error) or exit(1);
if (isset($pidFile)) {
    file_put_contents($pidFile, getmypid());
}
while (true) {
    $connection = @stream_socket_accept($server, 3600);
    if (!$connection) {
        continue;
    }
    while (($line = fgets($connection)) !== false && rtrim($line) !== '') {
    }
    $body = '{"pid":' . getmypid() . '}';
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    fclose($connection);
}
EOF);
chmod($coolwsd, 0755);

// The AppImage passes the check for its libraries, then does what the case needs
$appImage = "$appDir/collabora/Collabora_Online.AppImage";
function write_appimage(string $behaviour, int $mode): void
{
    global $appImage;
    file_put_contents($appImage, "#!/bin/sh\n[ -n \"\$LD_TRACE_LOADED_OBJECTS\" ] && exit 0\n$behaviour\n");
    chmod($appImage, $mode);
    clearstatcache();
}

// Runs a request through proxy.php and returns its output, error output and duration, while it
// collects the processes given, which proxy.php may stop
function request(string $query, array $collect = []): array
{
    global $appDir, $tmpDir;
    $start = microtime(true);
    $process = proc_open([PHP_BINARY, '-d', "upload_tmp_dir=$tmpDir", __FILE__, '--request', $appDir], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['TEST_QUERY' => $query, 'TEST_MARKER' => 'kept', 'TMPDIR' => '/inherited'] + getenv());
    fclose($pipes[0]);
    $streams = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    while ($open && microtime(true) - $start < 60) {
        foreach ($collect as $other) {
            proc_get_status($other);
        }
        $read = array_values($open);
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 100000) === false) {
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
        proc_terminate($process, 9);
    }
    proc_close($process);
    return [$streams[1], $streams[2], microtime(true) - $start];
}

$checks = [];
$launchLog = "$tmpDir/launches";
$lockFile = "$settingsDir/coolwsd.lock";

// A launch that fails ends the request at once
write_appimage("echo launch >> $launchLog\necho \"\$TMPDIR \$TEST_MARKER\" > $tmpDir/environment\necho 'test AppImage cannot run' >&2\nexit 1", 0755);
[$output, $stderr, $seconds] = request('status');
$launches = count(file($launchLog));
$checks['failed launch: answered with launch_failed'] = $output === '{"status":"error","error":"launch_failed"}';
$checks['failed launch: answered within 10 seconds'] = $seconds < 10;
$checks['failed launch: logged that the AppImage ended'] = strpos($stderr, 'ended before coolwsd started') !== false;
$checks['failed launch: the AppImage error is in the log'] = strpos($stderr, 'test AppImage cannot run') !== false;
$checks['failed launch: no start lock left behind'] = !file_exists($lockFile);
$checks['failed launch: the AppImage kept the environment and its TMPDIR'] = trim((string)@file_get_contents("$tmpDir/environment")) === '/inherited kept';

// and is not tried again right away
[$output, $stderr, $seconds] = request('status');
$checks['next request: answered with launch_failed at once'] = $output === '{"status":"error","error":"launch_failed"}' && $seconds < 5;
$checks['next request: did not launch the AppImage again'] = count(file($launchLog)) === $launches;
@unlink("$settingsDir/coolwsd.failed");

// After an update: the old coolwsd runs, the host settings it was started with differ, and the new
// AppImage has no execute permission
$old = proc_open([$coolwsd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$oldPid = proc_get_status($old)['pid'];
$started[] = $oldPid;
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 9983); $i++) {
    usleep(100000);
}
@unlink("$settingsDir/coolwsd-hosts-started.json");
mkdir("$tmpDir/office");
write_settings("$tmpDir/office");
write_appimage("echo \"\$TMPDIR \$TEST_MARKER\" > $tmpDir/environment\nexec \"\$(dirname \"\$0\")/coolwsd\" \"\$@\"", 0644);
[$output, $stderr, $seconds] = request('req=/hosting/discovery', [$old]);
$answer = json_decode($output, true);
if (isset($answer['pid'])) {
    $started[] = $answer['pid'];
}
$checks['after update: the old coolwsd was stopped'] = !proc_get_status($old)['running'];
$checks['after update: answered by the new coolwsd'] = isset($answer['pid']) && $answer['pid'] !== $oldPid;
$checks['after update: the AppImage was made executable'] = is_executable($appImage);
$checks['after update: the AppImage got the temp_directory setting as TMPDIR'] = trim((string)@file_get_contents("$tmpDir/environment")) === "$tmpDir/office kept";
proc_close($old);

$failures = 0;
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failures++;
    }
    printf("%s: %s\n", $passed ? 'PASS' : 'FAIL', $name);
}
if ($failures) {
    fwrite(STDERR, sprintf("last request after %.1f s, output: %s\n%s", $seconds, var_export($output, true), $stderr));
}
exit($failures === 0 ? 0 : 1);
