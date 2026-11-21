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
    // The headers already taken over from coolwsd go, and its status line goes with them, as a
    // status code given to header() replaces it, which one given to http_response_code() does not
    header_remove();
    header('Content-Type: text/html; charset=UTF-8', true, $code);
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
require_once __DIR__ . '/lib/Coolwsd.php';

// The lock file is in the settings directory, which only this user can write to
$lockfile = \OCA\RichDocumentsCODE\HostFile::directory() . '/coolwsd.lock';
// The longest a start of coolwsd may take, in seconds, as on a small host unpacking the AppImage
$startTimeout = 60 * 5;
// A start that failed leaves this stamp, and the next one is tried only after $retryDelay seconds,
// as each try may unpack the whole AppImage again
$failedStamp = \OCA\RichDocumentsCODE\HostFile::directory() . '/coolwsd.failed';
$retryDelay = 30;

// Whether a coolwsd of this user runs, known by its pid file or else by its name in /proc
function isCoolwsdRunning()
{
    return \OCA\RichDocumentsCODE\Coolwsd::isRunning();
}

// Whether something accepts connections on coolwsd's port
function isCoolwsdListening()
{
    $socket = @fsockopen("127.0.0.1", 9983, $errno, $errstr, 1);
    if (!$socket)
        return false;
    fclose($socket);
    return true;
}

// An app update from the app store unpacks the AppImage without its execute permission
function makeAppImageExecutable()
{
    global $appImage;

    @chmod($appImage, 0744);
    clearstatcache(); // effect of chmod() won't be detected without this call
}

// Whether the process has ended. An orphan that its new parent has not collected yet, as when that
// parent is a PHP process that runs as pid 1 of a container, is left as a zombie, which a signal
// still reaches; /proc tells it apart where it can be read, and elsewhere the zombie counts as
// running.
function processEnded($pid)
{
    if (!posix_kill($pid, 0))
        return true;
    $stat = @file_get_contents("/proc/$pid/stat");
    // The state follows the command name, which is in parentheses and may contain any character
    return $stat !== false && substr($stat, strrpos($stat, ')') + 2, 1) === 'Z';
}

// Ends a request for which coolwsd could not be started; a status request is answered in JSON
function startFailed($error, $message, $code)
{
    global $statusOnly;

    if ($statusOnly)
    {
        error_log("richdocumentscode (proxy.php) $message");
        print '{"status":"error","error":"' . $error . '"}';
        exit();
    }
    errorExit($message, $code);
}

// Starts coolwsd unless it is already starting. Returns false, starting nothing, when Nextcloud has
// not written the host settings yet.
function startCoolwsd()
{
    global $appImage;
    global $lockfile;
    global $startTimeout;
    global $failedStamp;
    global $retryDelay;

    $settingsText = \OCA\RichDocumentsCODE\HostFile::readCurrent();
    if ($settingsText === "")
        return false;

    // A stamp from the future, as after the clock was set back, is ignored
    $failedAge = time() - (int)@filemtime($failedStamp);
    if (file_exists($failedStamp) && $failedAge >= 0 && $failedAge < $retryDelay)
        startFailed('launch_failed', "The built-in office server failed to start less than $retryDelay seconds ago. The web server's error log has the reason.", 503);
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

    $options[] = "--pidfile=" . \OCA\RichDocumentsCODE\Coolwsd::pidPath();

    // bash runs this fixed script with the AppImage as $0, the temporary directory as $1 and the
    // options after it, so it never parses the values themselves. The AppImage unpacks itself, when
    // it has to, and coolwsd keeps its files in TMPDIR, which the script sets only when the app's
    // temp_directory setting gives one, and the environment is inherited as it is otherwise. A
    // second attempt with --appimage-extract-and-run follows when the AppImage fails, as it does
    // without FUSE. The script opens /dev/null itself, as PHP may only open the files open_basedir
    // allows. It prints the pid of the subshell that runs the AppImage, which lives as long as the
    // AppImage does.
    $script = 'if [ -n "$1" ]; then export TMPDIR="$1"; fi; shift; ( "$0" "$@" || "$0" --appimage-extract-and-run "$@" ) </dev/null >/dev/null & echo $!; disown';
    $launchCmd = array_merge(['bash', '-c', $script, $appImage, $settings['tmpDirectory'] ?? ""], $options);

    // Remove stale lock file (just in case)
    if (file_exists("$lockfile"))
        if (time() - filectime("$lockfile") > $startTimeout)
            @unlink("$lockfile");

    // Prevent second start
    $lock = @fopen("$lockfile", "x");
    // Another request is starting coolwsd, and gives up by removing its lock
    $lockSeen = !$lock && file_exists("$lockfile");
    $launcherPid = 0;
    if ($lock)
    {
        // We start a new server, we don't need stale pidfile around
        \OCA\RichDocumentsCODE\Coolwsd::removePidFiles();

        \OCA\RichDocumentsCODE\HostFile::writeText(\OCA\RichDocumentsCODE\HostFile::startedPath(), $settingsText);

        makeAppImageExecutable();

        debug_log("Launch the coolwsd server: " . implode(' ', $launchCmd));
        $process = proc_open($launchCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $launched = $process !== false;
        if ($launched)
        {
            $launcherPid = (int)trim(stream_get_contents($pipes[1]));
            foreach ($pipes as $pipe)
                fclose($pipe);
            $launched = proc_close($process) === 0;
        }

        fclose($lock);

        if (!$launched)
        {
            touch($failedStamp);
            @unlink("$lockfile");
            startFailed('launch_failed', "The built-in office server could not be launched.", 500);
        }
    }

    // The new coolwsd writes the pid file once it runs. The process itself would show up in /proc
    // at once, and a coolwsd that dies while starting would then be launched again and again.
    $deadline = time() + $startTimeout;
    while (!\OCA\RichDocumentsCODE\Coolwsd::pidFileIsLive())
    {
        // The AppImage ended without a coolwsd, as when it cannot be run at all, or the request that
        // launched it gave up; the web server's error log has the reason
        $launcherEnded = $lock ? $launcherPid > 0 && processEnded($launcherPid) : $lockSeen && !file_exists("$lockfile");
        if ($launcherEnded && !\OCA\RichDocumentsCODE\Coolwsd::pidFileIsLive()
            && \OCA\RichDocumentsCODE\Coolwsd::findPids() === [])
        {
            if ($lock)
            {
                touch($failedStamp);
                @unlink("$lockfile");
                error_log("richdocumentscode (proxy.php) $appImage ended before coolwsd started");
            }
            startFailed('launch_failed', "The built-in office server stopped while it was starting.", 500);
        }
        if (time() > $deadline)
        {
            // The next request can then try again
            if ($lock)
                @unlink("$lockfile");
            startFailed('start_timeout', "The built-in office server did not start in time.", 503);
        }
        sleep(1);
    }

    @unlink("$lockfile");
    @unlink($failedStamp);

    return true;
}

// The URL of this script as the browser reaches it, with ?req= for the request, which coolwsd puts
// in front of the URLs it hands out. A reverse proxy may change the protocol, host or path on the
// way, and Nextcloud's overwrite settings say so; they apply here as they do in Nextcloud, to a
// client address the overwritecondaddr setting matches, or to all when it is not set.
function proxyPrefix()
{
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $protocol = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = $_SERVER['SCRIPT_NAME'];

    // The settings richdocuments may just have dropped still serve the requests under way
    $text = \OCA\RichDocumentsCODE\HostFile::readCurrent()
        ?: \OCA\RichDocumentsCODE\HostFile::readText(\OCA\RichDocumentsCODE\HostFile::startedPath());
    $settings = $text !== "" ? json_decode($text, true) : [];
    $condition = $settings['overwriteCondAddr'] ?? "";
    if ($condition === "" || @preg_match("/$condition/", $_SERVER['REMOTE_ADDR'] ?? "") === 1)
    {
        $protocol = ($settings['overwriteProtocol'] ?? "") ?: $protocol;
        $host = ($settings['overwriteHost'] ?? "") ?: $host;
        $path = ($settings['proxyPath'] ?? "") ?: $path;
    }
    return "$protocol://$host$path?req=";
}

// Whether Nextcloud's host settings differ from those coolwsd was started with
function hostSettingsChanged()
{
    $current = \OCA\RichDocumentsCODE\HostFile::readCurrent();
    $started = \OCA\RichDocumentsCODE\HostFile::readText(\OCA\RichDocumentsCODE\HostFile::startedPath());
    return $current !== $started;
}

// Stops coolwsd, and starts it again unless another request already has. Returns false when a
// coolwsd this proxy cannot find keeps the port, as when its pid file is gone and /proc cannot be
// read: a new coolwsd would only fail to bind the port, and the old one has to be stopped by hand.
function restartCoolwsd()
{
    stopCoolwsd();
    // wait 10 seconds max
    for ($i = 0; isCoolwsdRunning() && ($i < 10); $i++)
        sleep(1);

    // somebody else might have restarted it in the meantime
    if (!isCoolwsdRunning())
    {
        if (isCoolwsdListening())
        {
            error_log("richdocumentscode (proxy.php) a coolwsd of another release or user keeps port 9983, and no pid file or /proc entry names it. Stop it by hand.");
            return false;
        }
        startCoolwsd();
    }
    return true;
}

function stopCoolwsd()
{
    debug_log("Stopping the coolwsd server");
    \OCA\RichDocumentsCODE\Coolwsd::stop();
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

    makeAppImageExecutable();

    if (!is_executable($appImage))
        return 'appimage_not_executable';

    $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('exec', $disabledFunctions) || in_array('proc_open', $disabledFunctions) || @exec('echo EXEC') !== "EXEC")
        return 'exec_disabled';

    exec("LD_TRACE_LOADED_OBJECTS=1 $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

    if (!hasFontconfig())
        return 'no_fontconfig';

    if (!\OCA\RichDocumentsCODE\HostFile::isDirectoryUsable())
        return 'settings_directory_not_owned';

    return '';
}

// Whether libfontconfig.so.1, which the AppImage loads from the system, is installed. ldconfig
// lists the libraries it knows, but a shared host or a chroot may have no ld.so.cache, or no
// ldconfig the web server user may run, so the usual library directories are searched as well.
function hasFontconfig()
{
    exec('( /sbin/ldconfig -p || scanelf -l ) 2>/dev/null | grep libfontconfig.so.1 > /dev/null', $output, $return);
    if ($return === 0)
        return true;

    foreach (['/lib', '/lib64', '/usr/lib', '/usr/lib64', '/usr/local/lib', '/usr/local/lib64'] as $dir)
    {
        // Debian and its derivatives keep the libraries in a per-architecture subdirectory
        if (@file_exists("$dir/libfontconfig.so.1") || @glob("$dir/*-linux-gnu*/libfontconfig.so.1"))
            return true;
    }
    return false;
}

function startsWith($string, $with) {
    return (substr($string, 0, strlen($with)) === $with);
}

// The request for the log: its method and path, where only the known words stay, as the other
// path segments may be a document URL with its access token, a session id or a download id, and
// the command of a compat tunnel request, which is in the query string, itself left out
function requestForLog($request)
{
    $words = ['cool', 'ws', 'browser', 'dist', 'hosting', 'discovery', 'capabilities', 'clipboard',
        'convert-to', 'download', 'media', 'contents', 'insertfile', 'open', 'write', 'wait', 'close'];
    $segments = explode('/', strtok($request, '?'));
    foreach ($segments as &$segment)
    {
        if ($segment !== '' && !in_array($segment, $words, true))
            $segment = '...';
    }
    unset($segment);
    $text = $_SERVER['REQUEST_METHOD'] . ' ' . implode('/', $segments);
    if (preg_match('#[?&]compat=(?:/ws)?/[^/&]*/(open|write|wait|close)(?:[/&]|$)#', rawurldecode($request), $match))
        $text .= " (compat $match[1])";
    return $text;
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
                if (restartCoolwsd())
                    print '{"status":"restarting"}';
                else
                    print '{"status":"error","error":"stop_failed"}';
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
    if (!restartCoolwsd())
        // The old coolwsd keeps serving until it is stopped by hand
        $local = @fsockopen("127.0.0.1", 9983, $errno, $errstr, 3);
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

$proxyURL = proxyPrefix();
debug_log("ProxyPrefix: '$proxyURL'");

$realRequest = $_SERVER['REQUEST_METHOD'] . " " . $request . " HTTP/1.1";
debug_log("Onward request is: '$realRequest'");

$body = file_get_contents('php://input');
debug_log("request content: '$body'");

function isMultipartRequest()
{
    return stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
}

// A name or file name in a Content-Disposition header, quoted as browsers quote it
function formDataQuote($text)
{
    return '"' . str_replace(["\r", "\n", '"'], ['%0D', '%0A', '%22'], $text) . '"';
}

// PHP reads a multipart/form-data body into $_POST and $_FILES itself and leaves php://input empty,
// so the body for coolwsd is built again from them, with the boundary of the Content-Type header
// that goes on to coolwsd. When a PHP setting made it drop the files, such as the content of a
// paste, coolwsd would get the request without them and refuse it with an empty 400 response, so
// the setting is named in the log here instead.
function rebuildMultipartBody()
{
    debug_log("Rebuilding the multipart body from " . count($_POST) . " fields and " . count($_FILES) . " files");

    // ini_get() gives the value as written, which may be a word like "off"
    $fileUploads = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
    if (empty($_POST) && empty($_FILES))
    {
        // A request without a body goes on as it came
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) === 0)
            return '';
        if (!$fileUploads)
            errorExit("PHP's file_uploads setting is off, so the files of an upload, such as the content of a paste, do not reach the built-in office server.", 500);
        if ((int)ini_get('max_file_uploads') === 0)
            errorExit("PHP's max_file_uploads setting is 0, so the files of an upload, such as the content of a paste, do not reach the built-in office server.", 500);
    }
    else if (!$fileUploads)
        error_log("richdocumentscode (proxy.php) PHP's file_uploads setting is off, so only the fields of this upload reach the built-in office server, and none of its files.");

    if (!preg_match('/(?:^|;)\s*boundary=(?:"([^"]+)"|([^;,\s]+))/i', $_SERVER['CONTENT_TYPE'], $match))
        errorExit("The multipart/form-data request has no boundary.");
    $boundary = $match[2] ?? $match[1];

    $body = '';
    // Only the posted fields: $_REQUEST would add the query string parameters too.
    foreach ($_POST as $name => $value)
    {
        // PHP makes an array of the fields named like a[] or a[b]
        if (is_array($value))
            errorExit("The built-in office server takes no fields named like $name" . "[], as PHP keeps no record of their original names.");
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=" . formDataQuote($name) . "\r\n\r\n";
        $body .= "$value\r\n";
    }
    foreach ($_FILES as $name => $file)
    {
        if (is_array($file['error']))
            errorExit("The built-in office server takes no files named like $name" . "[], as PHP keeps no record of their original names.");
        switch ($file['error'])
        {
            case UPLOAD_ERR_OK:
                $content = @file_get_contents($file['tmp_name']);
                if ($content === false)
                    errorExit("PHP stored the uploaded file " . $file['name'] . " where the built-in office server cannot read it. Check the upload_tmp_dir and open_basedir settings.", 500);
                break;
            case UPLOAD_ERR_NO_FILE:
                // An empty file input
                $content = '';
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                errorExit("File " . $file['name'] . " is larger than maximum up-load file-size", 413);
            case UPLOAD_ERR_PARTIAL:
                errorExit("The upload of file " . $file['name'] . " ended before the whole file arrived.");
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                errorExit("PHP could not store the uploaded file " . $file['name'] . " (error " . $file['error'] . "). Check the upload_tmp_dir setting.", 500);
            default:
                errorExit("PHP could not store the uploaded file " . $file['name'] . " (error " . $file['error'] . "), as when a PHP extension stops an upload.", 500);
        }
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=" . formDataQuote($name) . "; filename=" . formDataQuote($file['name']) . "\r\n";
        if ($file['type'] !== '')
            $body .= "Content-Type: " . $file['type'] . "\r\n";
        $body .= "\r\n$content\r\n";
    }
    $body .= "--$boundary--\r\n";
    return $body;
}

if ($body === '' && isMultipartRequest())
{
    $body = rebuildMultipartBody();
    debug_log("Rebuilt body: $body");
}

// coolwsd sees every request come from this proxy, so the address of our own client goes at the
// end of X-Forwarded-For, as a reverse proxy adds it. A request from this machine's own address is
// from a local process, such as Nextcloud itself, and counts as loopback.
$clientAddress = $_SERVER['REMOTE_ADDR'];
if ($clientAddress === ($_SERVER['SERVER_ADDR'] ?? ''))
    $clientAddress = '127.0.0.1';
$forwardedFor = [];

// Header names are case-insensitive, and arrive in lower case over HTTP/2.
$ownHeaders = ['connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'te', 'trailer',
    'transfer-encoding', 'upgrade', 'expect', 'content-length', 'x-forwarded-for', 'proxyprefix'];
foreach ($headers as $header => $value)
{
    if (strcasecmp($header, 'Connection') === 0)
        foreach (explode(',', $value) as $name)
            $ownHeaders[] = strtolower(trim($name));
    else if (strcasecmp($header, 'X-Forwarded-For') === 0 && trim($value) !== '')
        $forwardedFor[] = $value;
}
$forwardedFor[] = $clientAddress;

fwrite($local, $realRequest . "\r\n");
fwrite($local, "Connection: close\r\n");
fwrite($local, "X-Forwarded-For: " . implode(', ', $forwardedFor) . "\r\n");
fwrite($local, "ProxyPrefix: " . $proxyURL . "\r\n");
fwrite($local, "Content-Length: " . strlen($body) . "\r\n");
// Send the headers on ...
foreach ($headers as $header => $value) {
    debug_log("$header: $value\n");
    if (in_array(strtolower($header), $ownHeaders))
        continue;

    fwrite($local, "$header: $value\r\n");
}
fwrite($local, "\r\n");
fwrite($local, $body);

debug_log("waiting for response");

$rest = '';
$contentLength = -1;
$contentWritten = 0;
$parsingHeaders = true;
do {
    $chunk = fread($local, 65536);
    // The end of the answer, or a read that failed, as one that timed out does with false
    if ($chunk === false || $chunk === '') {
        debug_log("empty chunk last data");
        $timedOut = stream_get_meta_data($local)['timed_out'];
        $cutShort = $chunk === false || ($contentLength >= 0 && $contentWritten < $contentLength);
        // While no byte of the answer has gone out, the client gets an error status instead
        if ($parsingHeaders || ($cutShort && $contentWritten === 0))
        {
            if ($timedOut)
                errorExit("The built-in office server did not answer " . requestForLog($request) . " in time.", 504);
            errorExit("The built-in office server closed the connection without answering " . requestForLog($request) . ".", 502);
        }
        // Otherwise the client gets the answer cut short
        if ($cutShort)
            error_log("richdocumentscode (proxy.php) the answer of the built-in office server to " . requestForLog($request) . ($timedOut ? " stopped coming in time." : " ended early."));
        break;
    } elseif ($parsingHeaders) {
        $rest .= $chunk;
        debug_log("build headers to: $rest\n");
        // The headers go out once all of them are in, so that an answer cut short among them still
        // ends in an error status
        if (strpos($rest, "\r\n\r\n") !== false) {
            // The answer to a HEAD request and a 204 or 304 answer have no body, whatever their
            // Content-Length says
            $bodyless = $_SERVER['REQUEST_METHOD'] === 'HEAD' || preg_match('#^HTTP/\S+ (204|304)\b#', $rest);
            parseLastHeader($rest, $contentLength);
            if ($bodyless)
                $contentLength = 0;
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
