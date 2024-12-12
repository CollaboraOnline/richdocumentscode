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
    print "<html><body>\n";
    print "<h1>Socket proxy error</h1>\n";
    print "<p>Error: " . htmlspecialchars($msg) . "</p>\n";
    print "</body></html>\n";
    error_log("richdocumentscode (proxy.php) error exit, PID: " . getmypid() . ", Message: $msg");
    http_response_code(400);
    exit();
}

debug_log('Proxy v1');

// Let the webserver time us out in its own good time.
set_time_limit(0);

// Where the appimage is installed
$appImage = __DIR__ . '/collabora/Collabora_Online.AppImage';

$tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
$lockfile = "$tmp_dir/coolwsd.lock";
$pidfile = "$tmp_dir/coolwsd.pid";

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

    return posix_kill($pid,0);
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
    debug_log("Testing disabled IPv6: $launchCmd");
    exec($launchCmd, $output, $return);
    if (implode("",$output)=="")
    {
        debug_log("IPv6 disabled. Will launch coolwsd with IPv4-only option.");
        $IPv4only = "--o:net.proto=IPv4";
    }

    // Extract the AppImage if FUSE is not available
    // net.lok_allow.host[14] is the next empty slot after the last element of the default list
    // when lok_allow does not contain the Nextcloud host, it is not possible to insert image from Nextloud
    // we have to set explicitely, because storage.wopi.alias_groups[@mode] is 'first' in case of richdocumentscode
    $launchCmd = "bash -c \"( $appImage $remoteFontConfig $IPv4only --o:net.lok_allow.host[14]=" . $_SERVER['HTTP_HOST'] . " --pidfile=$pidfile || $appImage --appimage-extract-and-run $remoteFontConfig $IPv4only --o:net.lok_allow.host[14]=" . $_SERVER['HTTP_HOST'] . " --pidfile=$pidfile) >/dev/null & disown\"";

    // Remove stale lock file (just in case)
    if (file_exists("$lockfile"))
        if (time() - filectime("$lockfile") > 60 * 5)
            unlink("$lockfile");

    // Prevent second start
    $lock = @fopen("$lockfile", "x");
    if ($lock)
    {
        // We start a new server, we don't need stale pidfile around
        if (file_exists("$pidfile"))
            unlink("$pidfile");

        debug_log("Launch the coolwsd server: $launchCmd");
        exec($launchCmd, $output, $return);
        if ($return)
            debug_log("Failed to launch server at $appImage.");

        fclose($lock);
    }

    while (!isCoolwsdRunning())
        sleep(1);

    if (file_exists("$lockfile"))
        unlink("$lockfile");
}

function stopCoolwsd()
{
    $pid = getCoolwsdPid();
    if (posix_kill($pid,0))
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

    exec("LD_TRACE_LOADED_OBJECTS=1 $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

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

    http_response_code(200);
    exit();
}

// If we can't get a socket open in 3 seconds when that is backed by
// a dedicated thread, then we have a server missing in action.
$local = @fsockopen("localhost", 9983, $errno, $errstr, 3);

// Return the status and exit if it is a ?status request
if ($statusOnly) {
    header('Content-type: application/json');
    header('Cache-Control: no-store');
    if (!$local) {
        $err = checkCoolwsdSetup();
        if (!empty($err))
            print '{"status":"error","error":"' . $err . '"}';
        else if (!isCoolwsdRunning()) {
            startCoolwsd();
            print '{"status":"starting"}';
        }
    } else if ($errno === 111) {
        print '{"status":"starting"}';
    } else {
        $response = file_get_contents("http://localhost:9983/hosting/capabilities", 0, stream_context_create(["http"=>["timeout"=>1]]));
        if ($response) {
            // Version check.
            $obj = json_decode($response);
            $expVer = '%COOLWSD_VERSION_HASH%';
            $actVer = substr($obj->{'productVersionHash'}, 0, strlen($expVer));
            if ($actVer !== $expVer && $expVer !== '%' . 'COOLWSD_VERSION_HASH' . '%') { // deliberately split so that sed does not touch this during build-time
                // Old/unexpected server version; restart.
                error_log("Old server found, restarting. Expected hash $expVer but found $actVer.");
                stopCoolwsd();
                // wait 10 seconds max
                for ($i = 0; isCoolwsdRunning() && ($i < 10); $i++)
                    sleep(1);

                // somebody else might have restarted it in the meantime
                if (!isCoolwsdRunning())
                    startCoolwsd();

                print '{"status":"restarting"}';
            }
            else
                print '{"status":"OK"}';
        }
        else
            print '{"status":"starting"}';
        fclose($local);
    }

    http_response_code(200);
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
if (!$local)
{
    $err = checkCoolwsdSetup();
    if (!empty($err))
        errorExit($err);
    else if (!isCoolwsdRunning())
        startCoolwsd();

    $logonce = true;
    while (true) {
        $local = @fsockopen("localhost", 9983, $errno, $errstr, 15);
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
    errorExit("Timed out opening local socket: $errno - $errstr");
}

// Fetch our headers for later
$headers = getallheaders();

$proxyURL .= $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?req=';
debug_log("ProxyPrefix: '$proxyURL'");

$realRequest = $_SERVER['REQUEST_METHOD'] . " " . $request . " " . $_SERVER['SERVER_PROTOCOL'];
debug_log("Onward request is: '$realRequest'");

$body = file_get_contents('php://input');
debug_log("request content: '$body'");

// Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with in this case.
$multiBody = '';
if ($body === '' && count($_FILES) > 0) {
    debug_log("Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with");
    $type = isset($headers['Content-Type']) ? $headers['Content-Type'] : $headers['content-type'];
    $boundary = trim(explode('boundary=', $type)[1]);
    foreach ($_REQUEST as $key=>$value) {
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

    debug_log("$body");
}

fwrite($local, $realRequest . "\r\n");
// Send the headers on ...
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
do {
    $chunk = fread($local, 65536);
    if($chunk === false) {
        $error = implode(' ', error_get_last());
        echo "ERROR ! $error\n";
        debug_log("error on chunk: $error");
        break;
    } elseif($chunk === '') {
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

} while(true);

debug_log("closing local socket");
fclose($local);

?>
