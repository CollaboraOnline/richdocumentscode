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

    private static function isLoopback(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1'
            || preg_match('/^127\.[0-9.]+$/', $host);
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

    // coolwsd also accepts a WOPI host whose resolved address matches, so a pattern with no letter in
    // it, such as 192.168.*, would trust any name that resolves into that range
    private function isNotAddressRange(string $host): bool
    {
        if (!str_contains($host, '*') || preg_match('/[a-z]/', $host))
            return true;
        $this->warnings[] = "The trusted domain \"$host\" covers a range of addresses, so the built-in office server does not trust it as a WOPI host";
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

        // Nextcloud always answers on localhost, but the loopback is trusted as a WOPI host only
        // when it is all there is, as on a test install
        $namedHosts = array_values(array_filter($nextcloudHosts, $this->isNotAddressRange(...)));
        $wopiHosts = array_values(array_filter($namedHosts, fn ($host) => !self::isLoopback($host)));
        // Whether some entry was left out, by any of the rules above, and only the loopback is left
        $leftOut = array_filter($entries, fn ($entry) => trim($entry) !== "" && !in_array(self::hostOf($entry), $namedHosts, true));
        $onlyLoopbackLeft = $wopiHosts === [] && $leftOut !== [];
        if ($wopiHosts === [])
            $wopiHosts = $namedHosts ?: ['localhost'];

        // With a callback URL every WOPISrc carries its host. Picture URLs still carry the host the
        // user reached Nextcloud by.
        $callbackHost = $this->urlHost($this->appConfig->getValueString('richdocuments', 'wopi_callback_url'));
        if ($callbackHost !== "")
        {
            $wopiHosts = [$callbackHost];
            $nextcloudHosts[] = $callbackHost;
        }
        else if ($onlyLoopbackLeft)
            $this->warnings[] = "No trusted domain gives a single host the built-in office server can use, so it opens documents only through localhost. Add the name or address users reach Nextcloud by to trusted_domains.";

        // The font list is fetched by coolwsd itself, at the address Nextcloud reaches this app by
        $fontConfigUrl = "";
        if (parse_url($wopiUrl, PHP_URL_SCHEME) === 'https' && $this->urlHost($wopiUrl) !== "")
            $fontConfigUrl = preg_replace('/richdocumentscode[^\/]*\/proxy\.php.*$/', 'richdocuments/settings/fonts.json', $wopiUrl);

        // The group host is a plain name, so a host made only of wildcards leaves a name that never
        // resolves
        $plainHosts = array_values(array_filter($wopiHosts, fn ($host) => !str_contains($host, '*')));
        $groupHost = $plainHosts[0] ?? 'richdocumentscode.invalid';
        if (str_contains($groupHost, ':'))
            $groupHost = "[$groupHost]";

        return [
            'wopiHosts' => array_map([self::class, 'pattern'], $wopiHosts),
            'wopiGroupHost' => $groupHost,
            'lokAllowHosts' => array_map([self::class, 'pattern'], array_values(array_unique($nextcloudHosts))),
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
