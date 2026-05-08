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

// Example local test URL:
// http://localhost/richproxy/proxy.php?req=/browser/dist/cool.html?file_path=file:///opt/libreoffice/online/test/data/hello-world.odt

// ------------------------------------------------------------
// Helper functions
// ------------------------------------------------------------

function debugLog(string $msg): void
{
    // Disabled for production; enable for debugging
    // error_log("richdocumentscode (proxy.php) debug, PID: " . getmypid() . ", Message: $msg");
}

function exitWithError(string $msg): void
{
    http_response_code(400);
    print "<html><body>\n";
    print "<h1>Socket proxy error</h1>\n";
    print "<p>Error: " . htmlspecialchars($msg) . "</p>\n";
    print "</body></html>\n";
    error_log("richdocumentscode (proxy.php) error exit, PID: " . getmypid() . ", Message: $msg");
    exit();
}

/**
 * @return string|int Returns the process ID as a string, or 0 if the server is not running.
 */
function getCoolwsdPid()
{
    global $pidFile;

    clearstatcache();
    if (file_exists($pidFile))
    {
        $pid = rtrim(file_get_contents($pidFile));
        debugLog("Coolwsd server running with pid: " . $pid);
        return $pid;
    }

    debugLog("Coolwsd server is not running.");
    return 0;
}

/**
 * @return bool|int Returns true if the process is running, false if not, or 0 if no PID file exists.
 */
function isCoolwsdRunning()
{
    $pid = getCoolwsdPid();
    if ($pid === 0)
        return 0;

    return posix_kill($pid,0);
}

function startCoolwsd(): void
{
    global $appImage;
    global $pidFile;
    global $lockFile;

    // Remote font config URL (HTTPS only)
    $remoteFontConfig = "";
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    {
        $remoteFontConfigUrl = escapeshellarg("https://" . $_SERVER['HTTP_HOST'] . preg_replace("/richdocumentscode.*$/", "richdocuments/settings/fonts.json", $_SERVER['REQUEST_URI']));
        $remoteFontConfig = "--o:remote_font_config.url=" . $remoteFontConfigUrl;
    }

    // Check whether IPv6 has been disabled.
    $IPv4only = "";
    $launchCmd = "ip -6 addr";
    debugLog("Testing disabled IPv6: $launchCmd");
    exec($launchCmd, $output, $return);
    if (implode("",$output)=="")
    {
        debugLog("IPv6 disabled. Will launch coolwsd with IPv4-only option.");
        $IPv4only = "--o:net.proto=IPv4";
    }

    // Launch the AppImage normally, with a fallback for environments without FUSE.
    // net.lok_allow.host[14] is the next empty slot after the last element of the default list.
    // If lok_allow does not contain the Nextcloud host, it is not possible to insert images from Nextcloud.
    // We have to set it explicitly because storage.wopi.alias_groups[@mode] is 'first' in case of richdocumentscode.
    $lok_allow = "--o:net.lok_allow.host[14]=" . escapeshellarg($_SERVER['HTTP_HOST']);
    $launchCmd = "bash -c \"( $appImage $remoteFontConfig $IPv4only $lok_allow --pidfile=$pidFile || $appImage --appimage-extract-and-run $remoteFontConfig $IPv4only $lok_allow --pidfile=$pidFile) >/dev/null & disown\"";

    // Remove a stale lock file if one exists.
    if (file_exists("$lockFile"))
        if (time() - filectime("$lockFile") > 60 * 5)
            unlink("$lockFile");

    // Prevent a second concurrent start.
    $lock = @fopen("$lockFile", "x");
    if ($lock)
    {
        // We are starting a new server, so we do not need a stale pidfile.
        if (file_exists("$pidFile"))
            unlink("$pidFile");

        debugLog("Launch the coolwsd server: $launchCmd");
        exec($launchCmd, $output, $return);
        if ($return)
            debugLog("Failed to launch server at $appImage.");

        fclose($lock);
    }

    while (!isCoolwsdRunning())
        sleep(1);

    if (file_exists("$lockFile"))
        unlink("$lockFile");
}

function stopCoolwsd(): void
{
    $pid = getCoolwsdPid();
    if (posix_kill($pid,0))
    {
        debugLog("Stopping the coolwsd server with pid: $pid");
        posix_kill($pid, 15 /*SIGTERM*/);
    }
}

// Check whether the environment is suitable for running coolwsd.
// Returns an error ID if a problem is found.
function checkCoolwsdSetup(): string
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

    exec("LD_TRACE_LOADED_OBJECTS=1 $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

    exec('( /sbin/ldconfig -p || scanelf -l ) | grep fontconfig > /dev/null 2>&1', $output, $return);
    if ($return)
        return 'no_fontconfig';

    return '';
}

// Parse upstream response headers, forward them with header(), and detect the end of the header block.
function parseAndForwardHeaders(&$chunk, &$contentLength): bool
{
    $headers = explode("\r\n", $chunk);
    debugLog("Headers: $chunk");
    $chop = 0;
    $endOfHeaders = false;
    foreach ($headers as $h)
    {
        debugLog("send: $h");
        $chop += strlen($h) + 2;
        if ($h === '')
        {
            $endOfHeaders = true;
            break;
        }
        if (str_starts_with($h, 'Content-Length:'))
        {
            $contentLength = (int)trim(substr($h, strlen('Content-Length:')));
        }
        header($h);
    }
    // Keep looking for the next header fragment.
    $chunk = substr($chunk, $chop);
    return $endOfHeaders;
}

function isMultipartRequest(array $headers): bool
{
    $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    return strpos(strtolower($contentType), 'multipart/form-data') !== false;
}

function parseRequestMode(string $queryString): array
{
    if (str_starts_with($queryString, 'status')) {
        return ['statusOnly' => true, 'request' => ''];
    }

    if (str_starts_with($queryString, 'req=')) {
        $request = substr($queryString, strlen('req='));
        if (substr($request, 0, 1) !== '/') {
            exitWithError("First ?req= param should be an absolute path: '" . $request . "'");
        }
        return ['statusOnly' => false, 'request' => $request];
    }

    exitWithError("The param should be 'status' or 'req=...', but is: '" . $queryString . "'");
}

function getRequestScheme(): string
{
    if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    ) {
        return 'https://';
    }

    return 'http://';
}

function handleStatusRequest($backendSocket, $errno): void
{
    header('Content-type: application/json');
    header('Cache-Control: no-store');

    if (!$backendSocket) {
        $err = checkCoolwsdSetup();
        if (!empty($err)) {
            print '{"status":"error","error":"' . $err . '"}';
            return;
        }

        if (!isCoolwsdRunning()) {
            startCoolwsd();
            print '{"status":"starting"}';
            return;
        }
    } elseif ($errno === 111) {
        print '{"status":"starting"}';
        return;
    }

    $response = file_get_contents(
        "http://localhost:9983/hosting/capabilities",
        0,
        stream_context_create(["http" => ["timeout" => 1]])
    );

    if ($response) {
        // Version check.
        $obj = json_decode($response);
        $expVer = '%COOLWSD_VERSION_HASH%';
        $actVer = substr($obj->{'productVersionHash'}, 0, strlen($expVer));

        // Deliberately split so that sed does not touch this during build-time.
        if ($actVer !== $expVer && $expVer !== '%' . 'COOLWSD_VERSION_HASH' . '%') {
            // Old or unexpected server version; restart.
            error_log("Old server found, restarting. Expected hash $expVer but found $actVer.");
            stopCoolwsd();

            // Wait up to 10 seconds for shutdown.
            for ($i = 0; isCoolwsdRunning() && ($i < 10); $i++) {
                sleep(1);
            }

            // Another process may have restarted it in the meantime.
            if (!isCoolwsdRunning()) {
                startCoolwsd();
            }

            print '{"status":"restarting"}';
        } else {
            print '{"status":"OK"}';
        }
    } else {
        print '{"status":"starting"}';
    }

    if ($backendSocket) {
        fclose($backendSocket);
    }
}

function rebuildMultipartBody(array $headers): string
{
    debugLog("PHP's RFC 1867 upload handling leaves php://input empty for multipart requests.");
    debugLog("Reconstructing multipart body - Files: " . count($_FILES) . ", Form fields: " . count($_POST));

    $type = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    $boundary = trim(explode('boundary=', $type)[1]);

    $multipartBody = '';

    foreach ($_REQUEST as $key => $value) {
        if ($key === 'req') {
            continue;
        }
        $multipartBody .= "--" . $boundary . "\r\n";
        $multipartBody .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
        $multipartBody .= "$value\r\n";
    }

    foreach ($_FILES as $file) {
        $multipartBody .= "--" . $boundary . "\r\n";
        $multipartBody .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . $file['name'] . "\"\r\n";
        $multipartBody .= "Content-Type: " . $file['type'] . "\r\n\r\n";
        if ($file['tmp_name'] === '') {
            exitWithError("File " . $file['name'] . " is larger than maximum upload file size");
        }
        $multipartBody .= file_get_contents($file['tmp_name']) . "\r\n";
    }

    $multipartBody .= "--" . $boundary . "--\r\n";

    debugLog("Reconstructed body: $multipartBody");
    return $multipartBody;
}

function forwardRequestHeaders($backendSocket, array $headers, string $body, string $multipartBody): void
{
    foreach ($headers as $header => $value) {
        debugLog("$header: $value\n");

        if ($multipartBody !== '' && $header === 'Content-Length') {
            debugLog("Substitute Content-Length of " . $value . " with " . strlen($body));
            $value = strlen($body);
        }

        fwrite($backendSocket, "$header: $value\r\n");
    }
}

function streamCoolwsdResponse($backendSocket): void
{
    $buffer = '';
    $contentLength = -1;
    $contentWritten = 0;
    $parsingHeaders = true;

    do {
        $chunk = fread($backendSocket, 65536);
        if ($chunk === false) {
            $error = error_get_last();
            $errorMessage = $error ? implode(' ', $error) : 'No error';
            echo "ERROR ! $errorMessage\n";
            debugLog("error on chunk: $errorMessage");
            break;
        } elseif ($chunk === '') {
            debugLog("empty chunk last data");
            if ($parsingHeaders)
                exitWithError("No content in reply from coolwsd. Is SSL enabled in error ?");
            break;
        } elseif ($parsingHeaders) {
            $buffer .= $chunk;
            debugLog("build headers to: $buffer\n");
            if (parseAndForwardHeaders($buffer, $contentLength)) {
                $parsingHeaders = false;

                $extOut = fopen("php://output", "w") or exitWithError("fundamental error opening PHP output");
                fwrite($extOut, $buffer);
                $contentWritten += strlen($buffer);
                $buffer = '';
                debugLog("passed last headers");
            }
        } else {
            fwrite($extOut, $chunk);
            $contentWritten += strlen($chunk);
            debugLog("proxy : " . strlen($chunk) . " bytes");
        }

        if ($contentLength != -1 && $contentWritten == $contentLength)
        {
            debugLog("reached ContentLength of $contentLength bytes");
            break;
        }

    } while(true);
}

// ------------------------------------------------------------
// Main script flow
// ------------------------------------------------------------

debugLog('Proxy v1');

// Let the webserver time us out in its own good time.
set_time_limit(0);

// Where the AppImage is installed.
$appImage = __DIR__ . '/collabora/Collabora_Online.AppImage';

$tmpDir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
$lockFile = "$tmpDir/coolwsd.lock";
$pidFile = "$tmpDir/coolwsd.pid";

// Avoid unwanted escaping of the req= parameter.
$request = $_SERVER['QUERY_STRING'];
[$statusOnly, $request] = parseRequestMode($request);

debugLog("get URI " . $request);

if ($request === '' && !$statusOnly)
    exitWithError("Missing, required req= parameter");

if (str_starts_with($request, '/hosting/capabilities') && !isCoolwsdRunning()) {
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

// If localhost:9983 does not accept connections within 3 seconds, treat the backend as unavailable.
$backendSocket = @fsockopen("localhost", 9983, $errno, $errstr, 3);

// Return the status response immediately if this is a ?status request.
if ($statusOnly) {
    handleStatusRequest($backendSocket, $errno);
    exit();
}

// Base URL for proxying back into this script.
$proxyURL = getRequestScheme();

// Start coolwsd if necessary.
if (!$backendSocket)
{
    $err = checkCoolwsdSetup();
    if (!empty($err))
        exitWithError($err);
    else if (!isCoolwsdRunning())
        startCoolwsd();

    $logonce = true;
    while (true) {
        $backendSocket = @fsockopen("localhost", 9983, $errno, $errstr, 15);
        if ($errno === 111) {
            if($logonce) {
               debugLog("Can't yet connect to socket so sleep");
               $logonce = false;
            }
            usleep(50 * 1000); // 50ms.
        } else {
            debugLog("Connected to the backend socket.");
            break;
        }
    }
}

if (!$backendSocket) {
    exitWithError("Timed out opening local socket: $errno - $errstr");
}

// Read request headers so they can be forwarded to coolwsd.
$headers = getallheaders();

$proxyURL .= $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?req=';
debugLog("ProxyPrefix: '$proxyURL'");

$realRequest = $_SERVER['REQUEST_METHOD'] . " " . $request . " " . $_SERVER['SERVER_PROTOCOL'];
debugLog("Onward request is: '$realRequest'");

$body = file_get_contents('php://input');
debugLog("request content: '$body'");

// PHP's RFC 1867 upload handling leaves php://input empty for multipart requests.
$multipartBody = '';
if ($body === '' && isMultipartRequest($headers)) {
    $body = rebuildMultipartBody($headers);
    $multipartBody = $body;
}

fwrite($backendSocket, $realRequest . "\r\n");

// Forward request headers to the backend.
forwardRequestHeaders($backendSocket, $headers, $body, $multipartBody);

fwrite($backendSocket, "ProxyPrefix: " . $proxyURL . "\r\n");
fwrite($backendSocket, "\r\n");
fwrite($backendSocket, $body);

debugLog("waiting for response");

streamCoolwsdResponse($backendSocket);

debugLog("closing local socket");
fclose($backendSocket);

?>
