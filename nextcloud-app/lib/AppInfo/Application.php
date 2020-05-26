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

namespace OCA\RichDocumentsCODE\AppInfo;

use OCP\AppFramework\App;
use OCA\Richdocuments\AppConfig;
use OCA\Richdocuments\WOPI\DiscoveryManager;
use OCA\Richdocuments\Service\CapabilitiesService;

class Application extends App
{
    const APPNAME = 'richdocumentscode';

    public function __construct(array $urlParams = array())
    {
        parent::__construct(self::APPNAME, $urlParams);
    }

    public function checkAndEnableCODEServer()
    {
        if ($this->getContainer()->getServer()->getAppManager()->isEnabledForUser('richdocuments')) {
            $appConfig = $this->getContainer()->query(AppConfig::class);
            $wopi_url = $appConfig->getAppValue('wopi_url');

            // Check if we have the wopi_url set currently
            if ($wopi_url !== null && $wopi_url !== '') {
                return;
            }

            $urlGenerator = \OC::$server->getURLGenerator();
            $relativeUrl = $urlGenerator->linkTo('richdocumentscode', '') . 'proxy.php';
            $absoluteUrl = $urlGenerator->getAbsoluteURL($relativeUrl);
            $wopi_url = $absoluteUrl . '?req=';

            $appConfig->setAppValue('wopi_url', $wopi_url);
            $appConfig->setAppValue('disable_certificate_verification', 'yes');

            $discoveryManager = $this->getContainer()->query(DiscoveryManager::class);
            $capabilitiesService = $this->getContainer()->query(CapabilitiesService::class);

            $discoveryManager->refretch();
            $capabilitiesService->clear();
            $capabilitiesService->refretch();
        }
    }
}