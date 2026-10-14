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

use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

// Works out the coolwsd settings in HostFile from Nextcloud's own configuration, which only an
// admin can change
class HostConfig
{
    // The warnings about the configuration from the last settings() call
    private array $warnings = [];

    public function __construct(
        private readonly IConfig $config,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    // The host of a trusted_domains or overwritehost entry, without a port, in lower case, or ""
    // when it is not a host name, an IPv4 address or an IPv6 address in brackets
    private static function hostOf(string $entry): string
    {
        $entry = strtolower(trim($entry));
        // An entry such as https://cloud.example.com never matches a request's host in Nextcloud
        if (str_contains($entry, '/'))
            return "";
        if (str_starts_with($entry, '['))
        {
            $end = strpos($entry, ']');
            if ($end === false)
                return "";
            $address = substr($entry, 1, $end - 1);
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $address : "";
        }
        // An IPv6 address may also be written without brackets, and then has no port
        if (substr_count($entry, ':') > 1)
            return filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $entry : "";
        $host = explode(':', $entry)[0];
        return preg_match('/^[-a-z0-9._*]+$/', $host) && !str_contains($host, '..') ? $host : "";
    }

    // A regular expression matching the host as Nextcloud matches a trusted domain: a * stands for
    // any run of letters, digits, dots and hyphens, and everything else matches itself. In a host
    // with no letter, such as 192.168.*, a * stands for digits and dots only, so that it matches
    // addresses and not a name such as 192.168.1.example.com.
    private static function pattern(string $host): string
    {
        $wildcard = preg_match('/[a-z]/', $host) ? '[-.a-zA-Z0-9]*' : '[0-9.]*';
        $parts = array_map(fn ($part) => str_replace('.', '\\.', $part), explode('*', $host));
        return implode($wildcard, $parts);
    }

    // The host of a trusted_domains or overwritehost entry, or "" with a warning when it has none
    private function usableHost(string $entry): string
    {
        $host = self::hostOf($entry);
        if ($host === "" && trim($entry) !== "")
            $this->warnings[] = "The trusted domain \"$entry\" is not a host name, so the built-in office server does not use it";
        return $host;
    }

    // A name made only of wildcards would match every host
    private function isSpecific(string $host): bool
    {
        if (preg_match('/[a-z0-9]/', $host))
            return true;
        $this->warnings[] = "The trusted domain \"$host\" matches every host, so the built-in office server does not use it";
        return false;
    }

    private function urlHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? self::hostOf($host) : "";
    }

    // The settings for coolwsd, or null when richdocuments uses another office server
    public function settings(): ?array
    {
        $appId = basename(dirname(__DIR__));
        $wopiUrl = $this->appConfig->getValueString('richdocuments', 'wopi_url');
        if ($wopiUrl !== '' && !str_contains(parse_url($wopiUrl, PHP_URL_PATH) ?? '', "/$appId/proxy.php"))
            return null;

        $entries = $this->config->getSystemValue('trusted_domains', []);
        $entries = is_array($entries) ? array_filter($entries, 'is_string') : [];
        $entries[] = (string)$this->config->getSystemValue('overwritehost', '');
        $nextcloudHosts = array_values(array_unique(array_filter(array_filter(array_map($this->usableHost(...), $entries)), $this->isSpecific(...))));

        // The font list is fetched by coolwsd itself, at the address Nextcloud reaches this app by
        $fontConfigUrl = "";
        if (parse_url($wopiUrl, PHP_URL_SCHEME) === 'https' && $this->urlHost($wopiUrl) !== "")
            $fontConfigUrl = preg_replace('/richdocumentscode[^\/]*\/proxy\.php.*$/', 'richdocuments/settings/fonts.json', $wopiUrl);

        return [
            'lokAllowHosts' => array_map([self::class, 'pattern'], $nextcloudHosts),
            'fontConfigUrl' => $fontConfigUrl,
        ];
    }

    public function update(): void
    {
        $this->warnings = [];
        $settings = $this->settings();
        if ($settings === null)
            HostFile::remove();
        else
            HostFile::write($settings);
        $this->logNewWarnings();
    }

    // The warnings are logged once each time they change, so an unchanged configuration does not
    // repeat them on every page view
    private function logNewWarnings(): void
    {
        $appId = basename(dirname(__DIR__));
        $text = implode("\n", $this->warnings);
        if ($this->appConfig->getValueString($appId, 'logged_warnings') === $text)
            return;
        foreach ($this->warnings as $warning)
            $this->logger->warning($warning);
        $this->appConfig->setValueString($appId, 'logged_warnings', $text);
    }
}
