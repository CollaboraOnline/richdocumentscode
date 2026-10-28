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

function errorExit($msg, $code = 400)
{
    http_response_code($code);
    print "<html><body>\n";
    print "<h1>Socket proxy error</h1>\n";
    print "<p>Error: " . htmlspecialchars($msg) . "</p>\n";
    print "</body></html>\n";
    error_log("richdocumentscode (proxy.php) error exit, PID: " . getmypid() . ", Message: $msg");
    exit();
}

debug_log('Proxy v1');

// Let the webserver time us out in its own good time.
set_time_limit(0);

// Where the appimage is installed
$appImage = __DIR__ . '/collabora/Collabora_Online.AppImage';

require_once __DIR__ . '/lib/HostFile.php';

// The lock and pid files are in the settings directory, which only this user can write to
$lockfile = \OCA\RichDocumentsCODE\HostFile::directory() . '/coolwsd.lock';
$pidfile = \OCA\RichDocumentsCODE\HostFile::directory() . '/coolwsd.pid';
// The pid file of a coolwsd started by an earlier release, which kept it in the temporary
// directory itself
$tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
$oldPidfile = "$tmp_dir/coolwsd.pid";
// The longest a start of coolwsd may take, in seconds, as on a small host unpacking the AppImage
$startTimeout = 60 * 5;

function getCoolwsdPid()
{
    global $pidfile;
    global $oldPidfile;

    clearstatcache();
    foreach ([$pidfile, $oldPidfile] as $path)
    {
        if (file_exists($path))
        {
            $pid = (int)file_get_contents($path);
            debug_log("Coolwsd server running with pid: " . $pid);
            return $pid;
        }
    }

    debug_log("Coolwsd server is not running.");
    return 0;
}

// Whether the pid is a live process of this user that the kernel knows as coolwsd
function isCoolwsdPid($pid)
{
    if (!$pid || !posix_kill($pid, 0))
        return false;
    return rtrim(@file_get_contents("/proc/$pid/comm")) === 'coolwsd';
}

function isCoolwsdRunning()
{
    return isCoolwsdPid(getCoolwsdPid());
}

// Starts coolwsd unless it is already starting. Returns false, starting nothing, when Nextcloud has
// not written the host settings yet.
function startCoolwsd()
{
    global $appImage;
    global $pidfile;
    global $oldPidfile;
    global $lockfile;
    global $startTimeout;
    global $statusOnly;

    $settingsText = \OCA\RichDocumentsCODE\HostFile::readCurrent();
    if ($settingsText === "")
        return false;
    $settings = json_decode($settingsText, true);

    // Options for coolwsd, one argument each
    $options = [];

    if ($settings['fontConfigUrl'] !== "")
        $options[] = "--o:remote_font_config.url=" . $settings['fontConfigUrl'];

    // This proxy is the only client of coolwsd, and it connects to 127.0.0.1
    $options[] = "--o:net.proto=IPv4";
    $options[] = "--o:net.listen=loopback";

    // Documents may fetch data from Nextcloud and from nowhere else. The bundled coolwsd.xml has
    // its own list, so each index it may use is given a Nextcloud name instead; the first entry is
    // keyed without an index. coolwsd treats an empty list as no limit, so every slot is filled.
    $lokAllowHosts = $settings['lokAllowHosts'] ?: $settings['wopiHosts'];
    $options[] = "--o:net.lok_allow.host=" . $lokAllowHosts[0];
    for ($i = 1; $i < 16; $i++)
        $options[] = "--o:net.lok_allow.host[$i]=" . $lokAllowHosts[$i % count($lokAllowHosts)];
    // The addresses allowed to call convert-to are not data sources
    $options[] = "--o:net.post_allow[@allow]=false";

    // Nextcloud is the only WOPI host. Its names are the aliases of one group, which coolwsd matches
    // as the regular expressions they are, and one of them is the group host.
    $options[] = "--o:storage.wopi.alias_groups[@mode]=groups";
    $options[] = "--o:storage.wopi.alias_groups.group[0].host=" . $settings['wopiGroupHost'];
    $options[] = "--o:storage.wopi.alias_groups.group[0].host[@allow]=true";
    foreach ($settings['wopiHosts'] as $i => $pattern)
        $options[] = "--o:storage.wopi.alias_groups.group[0].alias[$i]=" . $pattern;

    $options[] = "--pidfile=" . $pidfile;

    // bash runs this fixed script with the AppImage as $0 and the options as "$@", so it never
    // parses the values themselves. A second attempt with --appimage-extract-and-run follows when
    // the AppImage fails, as it does without FUSE. The script opens /dev/null itself, as PHP may only
    // open the files open_basedir allows.
    $script = '( "$0" "$@" || "$0" --appimage-extract-and-run "$@" ) </dev/null >/dev/null & disown';
    $launchCmd = array_merge(['bash', '-c', $script, $appImage], $options);

    // Remove stale lock file (just in case)
    if (file_exists("$lockfile"))
        if (time() - filectime("$lockfile") > $startTimeout)
            unlink("$lockfile");

    // Prevent second start
    $lock = @fopen("$lockfile", "x");
    if ($lock)
    {
        // We start a new server, we don't need stale pidfile around
        if (file_exists("$pidfile"))
            unlink("$pidfile");
        if (file_exists("$oldPidfile"))
            @unlink("$oldPidfile");

        \OCA\RichDocumentsCODE\HostFile::writeText(\OCA\RichDocumentsCODE\HostFile::startedPath(), $settingsText);

        debug_log("Launch the coolwsd server: " . implode(' ', $launchCmd));
        $process = proc_open($launchCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $launched = $process !== false;
        if ($launched)
        {
            foreach ($pipes as $pipe)
                fclose($pipe);
            $launched = proc_close($process) === 0;
        }

        fclose($lock);

        if (!$launched)
        {
            // The next request can then try again
            unlink("$lockfile");
            // A status request is answered in JSON
            if ($statusOnly)
            {
                error_log("richdocumentscode (proxy.php) failed to launch $appImage");
                print '{"status":"error","error":"launch_failed"}';
                exit();
            }
            errorExit("The built-in office server could not be launched.", 500);
        }
    }

    $deadline = time() + $startTimeout;
    while (!isCoolwsdRunning())
    {
        if (time() > $deadline)
        {
            // The next request can then try again
            if ($lock)
                unlink("$lockfile");
            // A status request is answered in JSON
            if ($statusOnly)
            {
                print '{"status":"error","error":"start_timeout"}';
                exit();
            }
            errorExit("The built-in office server did not start in time.", 503);
        }
        sleep(1);
    }

    if (file_exists("$lockfile"))
        unlink("$lockfile");

    return true;
}

// Whether Nextcloud's host settings differ from those coolwsd was started with
function hostSettingsChanged()
{
    $current = \OCA\RichDocumentsCODE\HostFile::readCurrent();
    $started = \OCA\RichDocumentsCODE\HostFile::readText(\OCA\RichDocumentsCODE\HostFile::startedPath());
    return $current !== $started;
}

// Stops coolwsd, and starts it again unless another request already has
function restartCoolwsd()
{
    stopCoolwsd();
    // wait 10 seconds max
    for ($i = 0; isCoolwsdRunning() && ($i < 10); $i++)
        sleep(1);

    // somebody else might have restarted it in the meantime
    if (!isCoolwsdRunning())
        startCoolwsd();
}

function stopCoolwsd()
{
    $pid = getCoolwsdPid();
    if (isCoolwsdPid($pid))
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

    $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('exec', $disabledFunctions) || in_array('proc_open', $disabledFunctions) || @exec('echo EXEC') !== "EXEC")
        return 'exec_disabled';

    exec("LD_TRACE_LOADED_OBJECTS=1 $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

    exec('( /sbin/ldconfig -p || scanelf -l ) | grep fontconfig > /dev/null 2>&1', $output, $return);
    if ($return)
        return 'no_fontconfig';

    if (!\OCA\RichDocumentsCODE\HostFile::isDirectoryUsable())
        return 'settings_directory_not_owned';

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

// If we can't get a socket open in 3 seconds when that is backed by
// a dedicated thread, then we have a server missing in action.
$local = @fsockopen("127.0.0.1", 9983, $errno, $errstr, 3);

// Return the status and exit if it is a ?status request
if ($statusOnly) {
    header('Content-type: application/json');
    header('Cache-Control: no-store');
    if (!$local) {
        $err = checkCoolwsdSetup();
        if (!empty($err))
            print '{"status":"error","error":"' . $err . '"}';
        else {
            // The pid file can already name a coolwsd that is not listening yet
            if (!isCoolwsdRunning())
                startCoolwsd();
            print '{"status":"starting"}';
        }
    } else {
        $response = file_get_contents("http://127.0.0.1:9983/hosting/capabilities", 0, stream_context_create(["http"=>["timeout"=>1]]));
        if ($response) {
            // Version check.
            $obj = json_decode($response);
            $expVer = '%COOLWSD_VERSION_HASH%';
            $actVer = substr($obj->{'productVersionHash'}, 0, strlen($expVer));
            $oldVersion = $actVer !== $expVer && $expVer !== '%' . 'COOLWSD_VERSION_HASH' . '%'; // deliberately split so that sed does not touch this during build-time
            if ($oldVersion || hostSettingsChanged()) {
                // Old/unexpected server version, or Nextcloud's host settings changed; restart.
                if ($oldVersion)
                    error_log("Old server found, restarting. Expected hash $expVer but found $actVer.");
                else
                    error_log("Nextcloud's host settings changed, restarting.");
                restartCoolwsd();

                print '{"status":"restarting"}';
            }
            else
                print '{"status":"OK"}';
        }
        else
            print '{"status":"starting"}';
        fclose($local);
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

$notStartedMessage = "The built-in office server starts once its app is enabled and Nextcloud has written its settings. Open any Nextcloud page, then try again.";

// A disabled app stops coolwsd on its next request
if ($local && \OCA\RichDocumentsCODE\HostFile::isDisabled())
{
    fclose($local);
    stopCoolwsd();
    errorExit($notStartedMessage, 503);
}

// Discovery is asked for regularly, so a change of Nextcloud's host settings takes effect here too
if ($local && startsWith($request, '/hosting/discovery') && hostSettingsChanged())
{
    error_log("Nextcloud's host settings changed, restarting.");
    fclose($local);
    $local = false;
    restartCoolwsd();
}

// Start the appimage if necessary
if (!$local)
{
    $err = checkCoolwsdSetup();
    if (!empty($err))
        errorExit($err, 500);

    $logonce = true;
    $deadline = time() + $startTimeout;
    while (time() <= $deadline) {
        // A restart may have given up while coolwsd was still stopping, so start it once it is gone
        if (!isCoolwsdRunning() && !startCoolwsd())
            errorExit($notStartedMessage, 503);
        $local = @fsockopen("127.0.0.1", 9983, $errno, $errstr, 15);
        if ($errno === 111) {
            if($logonce) {
               debug_log("Can't yet connect to socket so sleep");
               $logonce = false;
            }
            usleep(50 * 1000); // 50ms.
        } else {
            debug_log("connected?");
            break;
        }
    }
}

if (!$local) {
    errorExit("Timed out opening local socket: $errno - $errstr", 500);
}

// Fetch our headers for later
$headers = getallheaders();

$proxyURL .= $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?req=';
debug_log("ProxyPrefix: '$proxyURL'");

$realRequest = $_SERVER['REQUEST_METHOD'] . " " . $request . " " . $_SERVER['SERVER_PROTOCOL'];
debug_log("Onward request is: '$realRequest'");

$body = file_get_contents('php://input');
debug_log("request content: '$body'");

function isMultipartRequest($headers)
{
    $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    return strpos(strtolower($contentType), 'multipart/form-data') !== false;
}

// Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with in this case.
$multiBody = '';
if ($body === '' && isMultipartRequest($headers)) {
    debug_log("Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with");
	debug_log("Reconstructing multipart body - Files: " . count($_FILES) . ", Form fields: " . count($_POST));

    $type = isset($headers['Content-Type']) ? $headers['Content-Type'] : $headers['content-type'];
    $boundary = trim(explode('boundary=', $type)[1]);
    // Only the posted fields: $_REQUEST would add the query string parameters too.
    foreach ($_POST as $key=>$value) {
        $multiBody .= "--" . $boundary . "\r\n";
        $multiBody .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
        $multiBody .= "$value\r\n";
    }
    foreach ($_FILES as $file) {
        $multiBody .= "--" . $boundary . "\r\n";
        $multiBody .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . $file['name'] . "\"\r\n";
        $multiBody .= "Content-Type: " . $file['type'] . "\r\n\r\n";
        if ($file['tmp_name'] === '') {
            errorExit("File " . $file['name'] . " is larger than maximum up-load file-size", 413);
        }
        $multiBody .= file_get_contents($file['tmp_name']) . "\r\n";
    }
    $multiBody .= "--" . $boundary . "--\r\n";
    $body = $multiBody;

    debug_log("Reconstructed body: $body");
}

// coolwsd sees every request come from this proxy, so the address of our own client goes at the
// end of X-Forwarded-For, as a reverse proxy adds it. A request from this machine's own address is
// from a local process, such as Nextcloud itself, and counts as loopback.
$clientAddress = $_SERVER['REMOTE_ADDR'];
if ($clientAddress === ($_SERVER['SERVER_ADDR'] ?? ''))
    $clientAddress = '127.0.0.1';
$forwardedFor = [];

fwrite($local, $realRequest . "\r\n");
// Send the headers on ...
foreach ($headers as $header => $value) {
    debug_log("$header: $value\n");
    if (strcasecmp($header, 'X-Forwarded-For') === 0)
    {
        if (trim($value) !== '')
            $forwardedFor[] = $value;
        continue;
    }
    // Header names are case-insensitive, and arrive in lower case over HTTP/2.
    if ($multiBody !== '' && strcasecmp($header, 'Content-Length') === 0)
    {
        debug_log("Substitute Content-Length of " . $value . " with " . strlen($body));
        $value = strlen($body);
    }

    fwrite($local, "$header: $value\r\n");
}
$forwardedFor[] = $clientAddress;
fwrite($local, "X-Forwarded-For: " . implode(', ', $forwardedFor) . "\r\n");
fwrite($local, "ProxyPrefix: " . $proxyURL . "\r\n");
fwrite($local, "\r\n");
fwrite($local, $body);

debug_log("waiting for response");

$rest = '';
$contentLength = -1;
$contentWritten = 0;
$parsingHeaders = true;
do {
    $chunk = fread($local, 65536);
    if($chunk === false) {
        $error = error_get_last();
        $errorMessage = $error ? implode(' ', $error) : 'No error';
        echo "ERROR ! $errorMessage\n";
        debug_log("error on chunk: $errorMessage");
        break;
    } elseif($chunk === '') {
        debug_log("empty chunk last data");
        if ($parsingHeaders)
            errorExit("No content in reply from coolwsd. Is SSL enabled in error ?", 500);
        break;
    } elseif ($parsingHeaders) {
        $rest .= $chunk;
        debug_log("build headers to: $rest\n");
        if (parseLastHeader($rest, $contentLength)) {
            $parsingHeaders = false;

            $extOut = fopen("php://output", "w") or errorExit("fundamental error opening PHP output", 500);
            // A read may also contain bytes from the next HTTP response.
            if ($contentLength >= 0)
                $rest = substr($rest, 0, $contentLength);
            fwrite($extOut, $rest);
            $contentWritten += strlen($rest);
            $rest = '';
            debug_log("passed last headers");
        }
    } else {
        if ($contentLength >= 0)
            $chunk = substr($chunk, 0, max(0, $contentLength - $contentWritten));
        fwrite($extOut, $chunk);
        $contentWritten += strlen($chunk);
        debug_log("proxy : " . strlen($chunk) . " bytes");
    }

    if ($contentLength != -1 && $contentWritten == $contentLength)
    {
        debug_log("reached ContentLength of $contentLength bytes");
        break;
    }

} while(true);

debug_log("closing local socket");
fclose($local);

?>
