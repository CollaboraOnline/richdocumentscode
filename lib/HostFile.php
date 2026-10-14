<?php
/*
 * Copyright (C) 2026 Collabora Productivity, Ltd.
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

namespace OCA\RichDocumentsCODE;

// The coolwsd settings that come from Nextcloud's configuration, kept as JSON in a directory of
// the PHP temporary directory that only the web server user can read or write:
//   lokAllowHosts  regular expressions for the hosts documents may fetch data from
//   fontConfigUrl  the URL of richdocuments' font list, or "" for none
// It uses plain PHP only, so it works without Nextcloud loaded.
class HostFile
{
    public static function directory(): string
    {
        $tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        return "$tmpDir/richdocumentscode-" . posix_geteuid();
    }

    public static function path(): string
    {
        return self::directory() . '/coolwsd-hosts.json';
    }

    // A directory or file in the temporary directory is used only when this user owns it and it is
    // not a symbolic link, because other users can create names there first
    private static function isOwnedPlain(string $path, bool $directory): bool
    {
        $stat = @lstat($path);
        if ($stat === false || $stat['uid'] !== posix_geteuid())
            return false;
        $type = $stat['mode'] & 0170000;
        return $type === ($directory ? 0040000 : 0100000);
    }

    private static function ensureDirectory(): bool
    {
        $dir = self::directory();
        if (!file_exists($dir) && !is_link($dir))
            @mkdir($dir, 0700);
        if (!self::isOwnedPlain($dir, true))
        {
            error_log("richdocumentscode: $dir is not a directory owned by this user, not using it");
            return false;
        }
        // mkdir applies the umask, and an older directory may have wider permissions
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0777) !== 0700)
            @chmod($dir, 0700);
        return true;
    }

    public static function isValid($settings): bool
    {
        if (!is_array($settings) || !is_string($settings['fontConfigUrl'] ?? null))
            return false;
        if (!is_array($settings['lokAllowHosts'] ?? null))
            return false;
        foreach ($settings['lokAllowHosts'] as $pattern)
            if (!is_string($pattern) || !preg_match('/^[-a-zA-Z0-9.:_\\\\\[\]*]+$/', $pattern))
                return false;
        return true;
    }

    // The file's text, or "" when it is missing, not this user's, or does not hold valid settings
    public static function readText(string $path): string
    {
        if (!self::isOwnedPlain(self::directory(), true) || !self::isOwnedPlain($path, false))
            return "";
        $text = @file_get_contents($path);
        if ($text === false || !self::isValid(json_decode($text, true)))
            return "";
        return $text;
    }

    // The file is replaced in one rename, so a reader sees either the old settings or the new ones
    public static function writeText(string $path, string $text): void
    {
        if (!self::ensureDirectory())
            return;
        if (self::isOwnedPlain($path, false) && @file_get_contents($path) === $text)
            return;
        $tmpPath = $path . '.' . getmypid();
        if (@file_put_contents($tmpPath, $text) !== false)
            @rename($tmpPath, $path);
    }

    public static function write(array $settings): void
    {
        if (self::isValid($settings))
            self::writeText(self::path(), json_encode($settings, JSON_UNESCAPED_SLASHES));
    }

    public static function remove(): void
    {
        if (self::isOwnedPlain(self::path(), false))
            @unlink(self::path());
    }
}
