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

namespace OCA\RichDocumentsCODE\Migration;

use OCA\RichDocumentsCODE\HostConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

// Writes the host file on install and upgrade, so a setup done only from the command line has it
// before any page is shown
class WriteHostConfig implements IRepairStep
{
    public function __construct(
        private readonly HostConfig $hostConfig,
    ) {
    }

    public function getName(): string
    {
        return 'Write the coolwsd host settings';
    }

    public function run(IOutput $output): void
    {
        $this->hostConfig->update();
    }
}
