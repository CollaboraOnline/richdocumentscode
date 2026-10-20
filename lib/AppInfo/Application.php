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

namespace OCA\RichDocumentsCODE\AppInfo;

use OCA\RichDocumentsCODE\Listener\HostConfigListener;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap
{
    public function __construct()
    {
        // The app directory is named after the app id, which differs between the x86_64 and ARM64
        // builds
        parent::__construct(basename(dirname(__DIR__, 2)));
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, HostConfigListener::class);
        $context->registerEventListener(BeforeLoginTemplateRenderedEvent::class, HostConfigListener::class);
        $context->registerEventListener(AppEnableEvent::class, HostConfigListener::class);
        $context->registerEventListener(AppDisableEvent::class, HostConfigListener::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}
