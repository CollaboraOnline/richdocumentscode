<?php
/*
 * Licensed under the Apache License, Version 2.0.
 * Run with: php tests/hostfile-valid.php
 * Requires PHP 8. Checks which tmpDirectory values HostFile::isValid accepts in the host settings.
 */

require dirname(__DIR__) . '/lib/HostFile.php';

$base = [
    'wopiHosts' => ['localhost'],
    'wopiGroupHost' => 'localhost',
    'lokAllowHosts' => [],
    'fontConfigUrl' => '',
    'enabledPath' => '/var/www/data/enabled',
];

// name, tmpDirectory (null to leave the key out), whether the settings are valid
$cases = [
    ['settings of an earlier release', null, true],
    ['no directory', '', true],
    ['absolute path', '/srv/office', true],
    ['path with a space and UTF-8', '/srv/irodai munkák', true],
    ['relative path', 'srv/office', false],
    ['control character', "/srv/office\n", false],
    ['DEL character', "/srv/office\x7f", false],
    ['path that is not UTF-8', "/srv/latin1-\xe9", false],
    ['array', ['/srv/office'], false],
];
$failures = 0;
foreach ($cases as [$name, $directory, $expected]) {
    $settings = $base;
    if ($directory !== null) {
        $settings['tmpDirectory'] = $directory;
    }
    $valid = \OCA\RichDocumentsCODE\HostFile::isValid($settings);
    // A valid settings file has to survive the JSON round trip
    $roundTrip = !$valid || json_decode(json_encode($settings, JSON_UNESCAPED_SLASHES), true) === $settings;
    $passed = $valid === $expected && $roundTrip;
    if (!$passed) {
        $failures++;
    }
    printf("%s: %s (expected %s)\n", $passed ? 'PASS' : 'FAIL', $name, $expected ? 'valid' : 'invalid');
}
exit($failures === 0 ? 0 : 1);
