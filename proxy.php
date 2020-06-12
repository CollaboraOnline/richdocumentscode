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
// http://localhost/richproxy/proxy.php?req=/loleaflet/dist/loleaflet.html?file_path=file:///opt/libreoffice/online/test/data/hello-world.odt

function debug_log($msg)
{
    // Disabled for production; enable for debugging
    //error_log($msg);
}

function errorExit($msg)
{
    print "<html><body>\n";
    print "<h1>Socket proxy error</h1>\n";
    print "<p>Error: " . $msg . "</p>\n";
    print "</body></html>\n";
    debug_log("Error exit: $msg");
    http_response_code(400);
    exit();
}

debug_log('Proxy v1');

// Let the webserver time us out in its own good time.
set_time_limit(0);

// Where the appimage is installed
$appImage = __DIR__ . '/collabora/Collabora_Online.AppImage';

function getLoolwsdPid()
{
    exec("pidof loolwsd", $output, $return);
    if ($return == 0 && count($output) > 0)
    {
        debug_log("Loolwsd server running with pid: " . implode(', ', $output));
        return $output;
    }

    debug_log("Loolwsd server is not running.");
    return 0;
}

function isLoolwsdRunning()
{
    return !empty(getLoolwsdPid());
}

function startLoolwsd()
{
    global $appImage;
    @chmod($appImage, 0744);

    // Extract the AppImage if FUSE is not available
    $launchCmd = "( $appImage || $appImage --appimage-extract-and-run ) >/dev/null & disown";

    debug_log("Launch the loolwsd server: $launchCmd");
    exec($launchCmd, $output, $return);
    if ($return)
    {
        debug_log("Failed to launch server at $appImage.");
        // errorExit("Server unavialble."); // disown: not found
    }
}

function stopLoolwsd()
{
    $pid = getLoolwsdPid();
    if (!empty($pid))
    {
        debug_log("Stopping the loolwsd server with pid: " . implode(', ', $pid));
        exec("kill -s TERM " . implode(' ', $pid));
    }
}

// Check that the setup is suitable for running the loolwsd.
// Returns the error ID if we find a problem.
function checkLoolwsdSetup()
{
    global $appImage;

    if (PHP_OS_FAMILY !== 'Linux')
        return 'not_linux';
    else if (php_uname('m') !== 'x86_64')
        return 'not_x86_64';
    else if (!file_exists($appImage))
        return 'appimage_missing';
    else if (!chmod($appImage, 0744))
        return 'chmod_failed';
    else if (!is_executable($appImage))
        return 'appimage_not_executable';
    else if (!is_callable('exec'))
        return 'exec_disabled';

    exec("ldd $appImage", $output, $return);
    if ($return)
        return 'no_glibc';

    exec('( /sbin/ldconfig -p || scanelf -l ) | grep fontconfig > /dev/null 2>&1', $output, $return);
    if ($return)
        return 'no_fontconfig';

    return '';
}

// parse and emit headers using 'header' ...
function parseLastHeader(&$chunk)
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
        header($h);
    }
    // keep looking for the next header.
    $chunk = substr($chunk, $chop);
    return $endOfHeaders;
}

function startsWith($string, $with) {
    return (substr($string, 0, strlen($with)) === $with);
}

if (!function_exists('getallheaders'))
{
	// polyfill, e.g. on PHP 7.2 setups with nginx.
	// Can be removed when 7.2 becomes unsupported
	function getallheaders()
	{
		$headers = [];
		if (!is_array($_SERVER)) {
			return $headers;
		}
		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) == 'HTTP_') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
		return $headers;
	}
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
    if (substr($request, 0, 1) != '/')
        errorExit("First ?req= param should be an absolute path: '" . $request . "'");
} else {
    errorExit("The param should be 'status' or 'req=...', but is: '" . $request . "'");
}

debug_log("get URI " . $request);

if ($request == '' && !$statusOnly)
    errorExit("Missing, required req= parameter");

// If we can't get a socket open in 3 seconds when that is backed by
// a dedicated thread, then we have a server missing in action.
$local = @fsockopen("localhost", 9982, $errno, $errstr, 3);

// Return the status and exit if it is a ?status request
if ($statusOnly) {
    header('Content-type: application/json');
    header('Cache-Control: no-store');
    if (!$local) {
        $err = checkLoolwsdSetup();
        if (!empty($err))
            print '{"status":"error","error":"' . $err . '"}';
        else {
            startLoolwsd();
            print '{"status":"starting"}';
        }
    } else if ($errno == 111) {
        print '{"status":"starting"}';
    } else {
        $response = file_get_contents("http://localhost:9982/hosting/capabilities", 0, stream_context_create(["http"=>["timeout"=>1]]));
        if ($response) {
            // Version check.
            $obj = json_decode($response);
            $actVer = $obj->{'productVersionHash'};
            $expVer = '%LOOLWSD_VERSION_HASH%';
            if ($actVer != $expVer && $expVer != '%' . 'LOOLWSD_VERSION_HASH' . '%') { // deliberately split so that sed does not touch this during build-time
                // Old/unexpected server version; restart.
                error_log("Old server found, restarting. Expected hash $expVer but found $actVer.");
                stopLoolwsd();
                // wait 10 seconds max
                for ($i = 0; isLoolwsdRunning() && ($i < 10); $i++)
                    sleep(1);

                // somebody else might have restarted it in the meantime
                if (!isLoolwsdRunning())
                    startLoolwsd();

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
if (isset($_SERVER['HTTPS'])) {
    $proxyURL = "https://";
} else {
    $proxyURL = "http://";
}

// Start the appimage if necessary
if (!$local)
{
    if (!isLoolwsdRunning())
        startLoolwsd();

    while (true) {
        $local = fsockopen("localhost", 9982, $errno, $errstr, 15);
        if ($errno == 111) {
            debug_log("Can't yet connect to socket so sleep");
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
if ($body == '' && count($_FILES) > 0) {
    debug_log("Oh dear - PHP's rfc1867 handling doesn't give any php://input to work with");
    $type = $headers['Content-Type'];
    $boundary = trim(explode('boundary=', $type)[1]);
    foreach ($_REQUEST as $key=>$value) {
        if ($key == 'req') {
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
        if ($file['tmp_name'] == '') {
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
    if ($multiBody != '' && $header == 'Content-Length')
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
$parsingHeaders = true;
do {
    $chunk = fread($local, 65536);
    if($chunk === false) {
        $error = socket_last_error($local);
        echo "ERROR ! $error\n";
        debug_log("error on chunk: $error");
        break;
    } elseif($chunk == '') {
        debug_log("empty chunk last data");
        if ($parsingHeaders)
            errorExit("No content in reply from loolwsd. Is SSL enabled in error ?");
        break;
    } elseif ($parsingHeaders) {
        $rest .= $chunk;
        debug_log("build headers to: $rest\n");
        if (parseLastHeader($rest)) {
            $parsingHeaders = false;

            $extOut = fopen("php://output", "w") or errorExit("fundamental error opening PHP output");
            fwrite($extOut, $rest);
            $rest = '';
            debug_log("passed last headers");
        }
    } else {
        fwrite($extOut, $chunk);
        debug_log("proxy : " . strlen($chunk) . " bytes \n");
    }
} while(true);

debug_log("closing local socket");
fclose($local);

?>
