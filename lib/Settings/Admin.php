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

namespace OCA\RichDocumentsCODE\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    /**
     * @return TemplateResponse
     */
    public function getForm()
    {
        $isEnabled = 'no';
        $absoluteUrl = '/';
        $absoluteUrlAdmin = '/';
        $absoluteUrlAppInstall = '/';
        $appArch = 'x86_64';

        $urlGenerator = \OC::$server->getURLGenerator();
        $absoluteUrl = $urlGenerator->getAbsoluteURL('/index.php/settings/apps/app-bundles/richdocuments');
        $absoluteUrlAdmin = $urlGenerator->getAbsoluteURL('/index.php/settings/admin/richdocuments');
        $absoluteUrlAppInstall = $urlGenerator->getAbsoluteURL('/index.php/settings/apps/app-bundles/richdocumentscode');

        if ($this->getSection() === 'richdocumentscode_arm64') {
            $appArch = 'aarch64';
        }

        if (\OC::$server->getAppManager()->isEnabledForUser('richdocuments')) {
            $isEnabled = 'yes';
        }

        $parameters = [
            'richdocumentsEnabled'    => $isEnabled,
            'richdocumentsURL'    => $absoluteUrl,
            'richdocumentsAdminURL'    => $absoluteUrlAdmin,
            'appArch'   => $appArch,
            'appInstallUrl'    => $absoluteUrlAppInstall
        ];

        return new TemplateResponse('richdocumentscode', 'admin', $parameters);
    }

    /**
     * @return string the section ID, e.g. 'sharing'
     */
    public function getSection()
    {
        return 'richdocumentscode';
    }

    /**
     * @return int whether the form should be rather on the top or bottom of
     * the admin section. The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     */
    public function getPriority()
    {
        return 0;
    }
}
