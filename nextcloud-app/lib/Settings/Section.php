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

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection
{
    /** @var IURLGenerator */
    private $url;

    public function __construct(IURLGenerator $url)
    {
        $this->url = $url;
    }

    /**
     * returns the ID of the section. It is supposed to be a lower case string,
     * e.g. 'ldap'
     *
     * @returns string
     */
    public function getID()
    {
        return 'richdocumentscode';
    }

    /**
     * returns the translated name as it should be displayed, e.g. 'LDAP / AD
     * integration'. Use the L10N service to translate it.
     *
     * @return string
     */
    public function getName()
    {
        return "Built-in CODE Server";
    }

    /**
     * @return int whether the form should be rather on the top or bottom of
     * the settings navigation. The sections are arranged in ascending order of
     * the priority values. It is required to return a value between 0 and 99.
     *
     * E.g.: 70
     */
    public function getPriority()
    {
        return 75;
    }

    /**
     * {@inheritdoc}
     */
    public function getIcon()
    {
        return $this->url->imagePath('richdocumentscode', 'app-dark.svg');
    }
}
