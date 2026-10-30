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

// The coolwsd this app runs, known by its pid file or else by its name in /proc. It uses plain PHP
// only, so it works without Nextcloud loaded.
class Coolwsd
{
    // The pid file is in the settings directory, which only this user can write to
    public static function pidPath(): string
    {
        return HostFile::directory() . '/coolwsd.pid';
    }

    // The pid file of a coolwsd started by an earlier release, which kept it in the temporary
    // directory itself
    public static function oldPidPath(): string
    {
        return HostFile::tmpDirectory() . '/coolwsd.pid';
    }

    // Whether the pid is a live process of this user that the kernel knows as coolwsd. Any live pid
    // counts when /proc cannot be read, as under an open_basedir that does not include it.
    public static function isPid(int $pid): bool
    {
        if ($pid <= 0 || !posix_kill($pid, 0))
            return false;
        $comm = @file_get_contents("/proc/$pid/comm");
        return $comm === false || rtrim($comm) === 'coolwsd';
    }

    // The pid the first pid file with any content names, or 0 when there is none
    public static function pidFromFile(): int
    {
        clearstatcache();
        foreach ([self::pidPath(), self::oldPidPath()] as $path)
        {
            if (!file_exists($path))
                continue;
            $pid = (int)file_get_contents($path);
            if ($pid > 0)
                return $pid;
        }
        return 0;
    }

    // Whether the pid file names a live coolwsd, as it does once a started coolwsd has written it
    public static function pidFileIsLive(): bool
    {
        return self::isPid(self::pidFromFile());
    }

    // Removes the pid files before a start, so that a stale one is not taken for the new coolwsd
    public static function removePidFiles(): void
    {
        foreach ([self::pidPath(), self::oldPidPath()] as $path)
            if (file_exists($path))
                @unlink($path);
    }

    // The pids of this user's coolwsd processes found by name in /proc, or [] when /proc cannot be
    // read. The pid file can be gone while coolwsd runs on, as when the temporary directory it was
    // in is cleaned or replaced, and then this is the only way to find it.
    public static function findPids(): array
    {
        $pids = [];
        $uid = posix_geteuid();
        foreach ((array)@scandir('/proc') as $entry)
        {
            if (!ctype_digit((string)$entry))
                continue;
            $stat = @stat("/proc/$entry");
            if ($stat === false || $stat['uid'] !== $uid)
                continue;
            $comm = @file_get_contents("/proc/$entry/comm");
            if ($comm !== false && rtrim($comm) === 'coolwsd')
                $pids[] = (int)$entry;
        }
        return $pids;
    }

    // The pids of this user's running coolwsd processes: the one the pid file names, or else any
    // found in /proc
    public static function pids(): array
    {
        if (self::pidFileIsLive())
            return [self::pidFromFile()];
        return self::findPids();
    }

    public static function isRunning(): bool
    {
        return self::pids() !== [];
    }

    // Asks every running coolwsd of this user to stop
    public static function stop(): void
    {
        foreach (self::pids() as $pid)
            posix_kill($pid, 15 /*SIGTERM*/);
    }
}
