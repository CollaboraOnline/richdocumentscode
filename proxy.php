<?php
/*
 * Copyright (C) 2020 Collabora Productivity, Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// test with:
// http://localhost/richproxy/proxy.php?req=/browser/dist/cool.html?file_path=file:///opt/libreoffice/online/test/data/hello-world.odt

function debug_log($msg)
{
    // Disabled for production; enable for debugging
    // error_log("richdocumentscode (proxy.php) debug, PID: " . getmypid() . ", Message: $msg");
}

function errorExit($msg)
{
    http_response_code(400);
    print "<html><body>\n";
    print "<h1>Socket proxy error</h1>\n";
    print "<p>Error: " . htmlspecialchars($msg) . "</p>\n";
    print "</body></html>\n";
    error_log("richdocumentscode (proxy.php) error exit, PID: " . getmypid() . ", Message: $msg");
    exit();
}

debug_log('Proxy');

// Let the webserver time us out in its own good time.
set_time_limit(0);

// Where the appimage is installed
$appImage = __DIR__ . '/collabora/Collabora_Online.AppImage';

$tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
$lockfile = "$tmp_dir/coolwsd.lock";
$pidfile = "$tmp_dir/coolwsd.pid";
$startingfile = "$tmp_dir/coolwsd.starting";

const COOLWSD_STARTING_TTL = 180;
const COOLWSD_CONNECT_WAIT = 3;
const COOLWSD_RETRY_DELAY_US = 100000;

function getCoolwsdPid()
{
    global $pidfile;

    clearstatcache();
    if (file_exists($pidfile))
    {
        $pid = rtrim(file_get_contents($pidfile));
        debug_log("Coolwsd server running with pid: " . $pid);
        return $pid;
    }

    debug_log("Coolwsd server is not running.");
    return 0;
}

function isCoolwsdRunning()
{
    $pid = getCoolwsdPid();
    if ($pid === 0)
        return 0;

    return posix_kill($pid, 0);
}

function isCoolwsdReachable()
{
    $local = @fsockopen("localhost", 9983, $errno, $errstr, 1);
    if ($local) {
        fclose($local);
        return true;
    }

    return false;
}

function markCoolwsdStarting()
{
    global $startingfile;
    file_put_contents($startingfile, (string)time());
}

function clearCoolwsdStarting()
{
    global $startingfile;
    if (file_exists($startingfile))
        unlink($startingfile);
}

function getCoolwsdStartingSince()
{
    global $startingfile;

    clearstatcache();
    if (!file_exists($startingfile))
        return 0;

    $ts = (int)trim(@file_get_contents($startingfile));
    if ($ts <= 0)
        $ts = (int)@filemtime($startingfile);

    return $ts ?: 0;
}

function isCoolwsdStartupInProgress()
{
    $since = getCoolwsdStartingSince();
    if ($since === 0)
        return false;

    return (time() - $since) < COOLWSD_STARTING_TTL;
}

function isCoolwsdStartupStale()
{
    $since = getCoolwsdStartingSince();
    if ($since === 0)
        return false;

    return (time() - $since) >= COOLWSD_STARTING_TTL;
}

function clearStaleCoolwsdStartupState()
{
    if (isCoolwsdStartupStale() && !isCoolwsdRunning() && !isCoolwsdReachable()) {
        debug_log("Clearing stale coolwsd startup marker");
        clearCoolwsdStarting();
    }
}

function waitForCoolwsdReady($timeoutSec)
{
    $deadline = microtime(true) + $timeoutSec;

    while (microtime(true) < $deadline) {
        if (isCoolwsdReachable()) {
            clearCoolwsdStarting();
            return true;
        }

        usleep(COOLWSD_RETRY_DELAY_US);
    }

    return false;
}

function startCoolwsd()
{
    global $appImage;
    global $pidfile;
    global $lockfile;

    // Remote font config URL (HTTPS only)
    $remoteFontConfig = "";
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    {
        $remoteFontConfigUrl = escapeshellarg("https://" . $_SERVER['HTTP_HOST'] . preg_replace("/richdocumentscode.*$/", "richdocuments/settings/fonts.json", $_SERVER['REQUEST_URI']));
        $remoteFontConfig = "--o:remote_font_config.url=" . $remoteFontConfigUrl;
    }

    // Check if IPv6 has been disabled
    $IPv4only = "";
    $launchCmd = "ip -6 addr";
    $output = [];
    $return = 0;
    debug_log("Testing disabled IPv6: $launchCmd");
    exec($launchCmd, $output, $return);
    if (implode("", $output) == "")
    {
        debug_log("IPv6 disabled. Will launch coolwsd with IPv4-only option.");
        $IPv4only = "--o:net.proto=IPv4";
    }

    // net.lok_allow.host[14] is the next empty slot after the last element of the default list
    // when lok_allow does not contain the Nextcloud host, it is not possible to insert image from Nextcloud
    // we have to set explicitly, because storage.wopi.alias_groups[@mode] is 'first' in case of richdocumentscode
    $lok_allow = "--o:net.lok_allow.host[14]=" . escapeshellarg($_SERVER['HTTP_HOST']);

    // Launch detached so startup survives the PHP request ending.
	// Extracts the AppImage if FUSE is not available
    $launchCmd = "bash -c '( $appImage $remoteFontConfig $IPv4only $lok_allow --pidfile=$pidfile || " .
                 "$appImage --appimage-extract-and-run $remoteFontConfig $IPv4only $lok_allow --pidfile=$pidfile" .
                 " ) >/dev/null 2>&1 < /dev/null &'";

    // Remove stale lock file (just in case)
    if (file_exists($lockfile))
        if (time() - filectime($lockfile) > 60 * 5)
            unlink($lockfile);

    // Prevent second start
    $lock = @fopen($lockfile, "x");
    if (!$lock)
    {
        debug_log("coolwsd startup already in progress");
        return;
    }

    try {
        if (file_exists($pidfile))
            unlink($pidfile);

        markCoolwsdStarting();

        debug_log("Launch the coolwsd server: $launchCmd");
        $output = [];
        $return = 0;
        exec($launchCmd, $output, $return);
        if ($return)
            debug_log("Failed to launch server at $appImage.");
    } finally {
        fclose($lock);
        if (file_exists($lockfile))
            unlink($lockfile);
    }
}

function stopCoolwsd()
{
    $pid = getCoolwsdPid();
    if ($pid && posix_kill($pid, 0))
    {
        debug_log("Stopping the coolwsd server with pid: $pid");
        posix_kill($pid, 15 /*SIGTERM*/);
    }
}

// Check that the setup is suitable for running the coolwsd.
// Returns the error ID if we find a problem.
function checkCoolwsdSetup()
{
    global $appImage;

    if (PHP_OS_FAMILY !== 'Linux')
        return 'not_linux';

    if (php_uname('m') !== 'x86_64')
        return 'not_x86_64';

    if (!file_exists($appImage))
        return 'appimage_missing';

    @chmod($appImage, 0744);
    clearstatcache(); // effect of chmod() won't be detected without this call

    if (!is_executable($appImage))
        return 'appimage_not_executable';

    $disabledFunctions = explode(',', ini_get('disable_functions'));
    if (in_array('exec', $disabledFunctions) || @exec('echo EXEC') !== "EXEC")
        return 'exec_disabled';

    $output = [];
    $return = 0;
    exec("LD_TRACE_LOADED_OBJECTS=1 $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

    $output = [];
    $return = 0;
    exec('( /sbin/ldconfig -p || scanelf -l ) | grep fontconfig > /dev/null 2>&1', $output, $return);
    if ($return)
        return 'no_fontconfig';

    return '';
}

function startsWith($string, $with) {
    return (substr($string, 0, strlen($with)) === $with);
}

// parse and emit headers using 'header' ...
function parseLastHeader(&$chunk, &$contentLength)
{
    $headers = explode("\r\n", $chunk);
    debug_log("Headers: $chunk");
    $chop = 0;
    $endOfHeaders = false;
    foreach ($headers as $h)
    {
        debug_log("send: $h");
        $chop += strlen($h) + 2;
        if ($h === '')
        {
            $endOfHeaders = true;
            break;
        }
        if (startsWith($h, 'Content-Length:'))
        {
            $contentLength = (int)trim(substr($h, strlen('Content-Length:')));
        }
        header($h);
    }
    // keep looking for the next header.
    $chunk = substr($chunk, $chop);
    return $endOfHeaders;
}

// avoid unwanted escaping of req= parameter
$request = $_SERVER['QUERY_STRING'];
// only asking for status?
$statusOnly = false;

// handle parameters
if (startsWith($request, 'status')) {
    $request = '';
    $statusOnly = true;
} else if (startsWith($request, 'req=')) {
    $request = substr($request, strlen('req='));
    if (substr($request, 0, 1) !== '/')
        errorExit("First ?req= param should be an absolute path: '" . $request . "'");
} else {
    errorExit("The param should be 'status' or 'req=...', but is: '" . $request . "'");
}

debug_log("get URI " . $request);

if ($request === '' && !$statusOnly)
    errorExit("Missing, required req= parameter");

if (startsWith($request, '/hosting/capabilities') && !isCoolwsdRunning()) {
    header('Content-type: application/json');
    header('Cache-Control: no-store');

    print '{
        "convert-to":{"available":true},
        "hasMobileSupport":true,
        "hasProxyPrefix":false,
        "hasTemplateSaveAs":false,
        "hasTemplateSource":true
    }';

    exit();
}

clearStaleCoolwsdStartupState();

// Return the status and exit if it is a ?status request
if ($statusOnly) {
    header('Content-type: application/json');
    header('Cache-Control: no-store');

    if (!isCoolwsdReachable()) {
        $err = checkCoolwsdSetup();
        if (!empty($err)) {
            print '{"status":"error","error":"' . $err . '"}';
            exit();
        }

        if (!isCoolwsdStartupInProgress())
            startCoolwsd();

        if (!waitForCoolwsdReady(COOLWSD_CONNECT_WAIT)) {
            $elapsed = getCoolwsdStartingSince();
            $elapsed = $elapsed ? max(0, time() - $elapsed) : 0;
            http_response_code(202);
            print '{"status":"starting","elapsed":' . $elapsed . '}';
            exit();
        }
    }

    $response = @file_get_contents(
        "http://localhost:9983/hosting/capabilities",
        false,
        stream_context_create(["http" => ["timeout" => 1]])
    );

    if ($response) {
        $obj = json_decode($response);
        $expVer = '%COOLWSD_VERSION_HASH%';
        $actVer = substr($obj->{'productVersionHash'}, 0, strlen($expVer));
        if ($actVer !== $expVer && $expVer !== '%' . 'COOLWSD_VERSION_HASH' . '%') { // deliberately split so that sed does not touch this during build-time
            // Old server found, restart.
            error_log("Old server found, restarting. Expected hash $expVer but found $actVer.");
            stopCoolwsd();
            clearCoolwsdStarting();
            startCoolwsd();

            $elapsed = getCoolwsdStartingSince();
            $elapsed = $elapsed ? max(0, time() - $elapsed) : 0;
            http_response_code(202);
            print '{"status":"restarting","elapsed":' . $elapsed . '}';
        }
        else {
            clearCoolwsdStarting();
            print '{"status":"OK"}';
        }
    }
    else {
        $elapsed = getCoolwsdStartingSince();
        $elapsed = $elapsed ? max(0, time() - $elapsed) : 0;
        http_response_code(202);
        print '{"status":"starting","elapsed":' . $elapsed . '}';
    }

    exit();
}

// URL into this server of the proxy script.
if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' )
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
) {
    $proxyURL = "https://";
} else {
    $proxyURL = "http://";
}

// Start the appimage if necessary
$local = @fsockopen("localhost", 9983, $errno, $errstr, 15);
while (!$local)
{
    $err = checkCoolwsdSetup();
    if (!empty($err))
        errorExit($err);

    startCoolwsd();
    $local = @fsockopen("localhost", 9983, $errno, $errstr, 15);
}

$proxyURL .= $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?req=';
debug_log("ProxyPrefix: '$proxyURL'");

$headers = getallheaders();
$realRequest = $_SERVER['REQUEST_METHOD'] . " " . $request . " " . $_SERVER['SERVER_PROTOCOL'];
debug_log("Onward request is: '$realRequest'");

$body = file_get_contents('php://input');
debug_log("request content: '$body'");

function isMultipartRequest($headers)
{
    $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    return strpos(strtolower($contentType), 'multipart/form-data') !== false;
}

$multiBody = '';
if ($body === '' && isMultipartRequest($headers)) {
    debug_log("Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with");
    debug_log("Reconstructing body - Files: " . count($_FILES) . ", Form fields: " . count($_POST));

    $type = isset($headers['Content-Type']) ? $headers['Content-Type'] : $headers['content-type'];
    $boundary = trim(explode('boundary=', $type)[1]);
    foreach ($_REQUEST as $key => $value) {
        if ($key === 'req') {
            continue;
        }
        $multiBody .= "--" . $boundary . "\r\n";
        $multiBody .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
        $multiBody .= "$value\r\n";
    }
    foreach ($_FILES as $file) {
        $multiBody .= "--" . $boundary . "\r\n";
        $multiBody .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . $file['name'] . "\"\r\n";
        $multiBody .= "Content-Type: " . $file['type'] . "\r\n\r\n";
        if ($file['tmp_name'] === '') {
            errorExit("File " . $file['name'] . " is larger than maximum up-load file-size");
        }
        $multiBody .= file_get_contents($file['tmp_name']) . "\r\n";
    }
    $multiBody .= "--" . $boundary . "--\r\n";
    $body = $multiBody;

    debug_log("Reconstructed body: $body");
}

fwrite($local, $realRequest . "\r\n");
foreach ($headers as $header => $value) {
    debug_log("$header: $value\n");
    if ($multiBody !== '' && $header === 'Content-Length')
    {
        debug_log("Substitute Content-Length of " . $value . " with " . strlen($body));
        $value = strlen($body);
    }

    fwrite($local, "$header: $value\r\n");
}
fwrite($local, "ProxyPrefix: " . $proxyURL . "\r\n");
fwrite($local, "\r\n");
fwrite($local, $body);

debug_log("waiting for response");

$rest = '';
$contentLength = -1;
$contentWritten = 0;
$parsingHeaders = true;
$extOut = null;
do {
    $chunk = fread($local, 65536);
    if ($chunk === false) {
        $error = error_get_last();
        $errorMessage = $error ? implode(' ', $error) : 'No error';
        echo "ERROR ! $errorMessage\n";
        debug_log("error on chunk: $errorMessage");
        break;
    } elseif ($chunk === '') {
        debug_log("empty chunk last data");
        if ($parsingHeaders)
            errorExit("No content in reply from coolwsd. Is SSL enabled in error ?");
        break;
    } elseif ($parsingHeaders) {
        $rest .= $chunk;
        debug_log("build headers to: $rest\n");
        if (parseLastHeader($rest, $contentLength)) {
            $parsingHeaders = false;

            $extOut = fopen("php://output", "w") or errorExit("fundamental error opening PHP output");
            fwrite($extOut, $rest);
            $contentWritten += strlen($rest);
            $rest = '';
            debug_log("passed last headers");
        }
    } else {
        fwrite($extOut, $chunk);
        $contentWritten += strlen($chunk);
        debug_log("proxy : " . strlen($chunk) . " bytes");
    }

    if ($contentLength != -1 && $contentWritten == $contentLength)
    {
        debug_log("reached ContentLength of $contentLength bytes");
        break;
    }

} while (true);

debug_log("closing local socket");
fclose($local);

?>
