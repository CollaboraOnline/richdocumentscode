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
/** @var $l \OCP\IL10N */
/** @var $_ array */
?>

<div id="CODE-settings" class="section">
    <h2>Collabora Online - Built-in CODE Server</h2>
    <?php if ($_['appArch'] === 'x86_64' && php_uname('m') === 'aarch64'): ?>
        <div id="richdocumentscode-arm64-needed">
            <p><?php p($l->t('Your system is ARM64, but you have installed the x86_64 version of the app. Please remove this and')); ?>
                <u><a href="<?php echo($_['appInstallUrl']) ?>">
                    <?php p($l->t('install the correct version from the Nextcloud App Store.')); ?>
                    </a>
                </u>
            </p>
        </div>
    <?php elseif ($_['appArch'] === 'aarch64' && php_uname('m') === 'x86_64'): ?>
        <div id="richdocumentscode-arm64-needed">
            <p><?php p($l->t('Your system is x86_64, but you have installed the ARM64 version of the app. Please remove this and')); ?>
                <u><a href="<?php echo($_['appInstallUrl']) ?>">
                    <?php p($l->t('install the correct version from the Nextcloud App Store.')); ?>
                    </a>
                </u>
            </p>
        </div>
    <?php elseif ($_['richdocumentsEnabled'] === 'yes'): ?>
        <div id="richdocuments-Enabled">
            <p><?php p($l->t('You have the Collabora Online app enabled. For further information and configuration, please check:')); ?>
                <u><a href="<?php
                    echo($_['richdocumentsAdminURL']);
                    ?>">
                        <?php p($l->t('Administration settings > Nextcloud Office')); ?>
                    </a>
                </u>
            </p>
        </div>
    <?php elseif ($_['richdocumentsEnabled'] === 'no'): ?>
        <div id="richdocuments-NotEnabled">
            <p>
                <?php p($l->t('The Built-in CODE Server is designed to work with the Nextcloud Office app.')); ?>
                <u>
                    <a href="<?php echo($_['richdocumentsURL']) ?>">
                        <?php p($l->t('Install it from the Nextcloud App Store.')); ?>
                    </a>
                </u>
            </p>
        </div>
    <?php else: ?>
        <div id="richdocuments-EnabledCheckFailed">
            <p><?php p($l->t('An error occurred while trying to check your Collabora Online app installation. You may report this error
                with the tag: <em>richdocuments-EnabledCheckFailed</em>')); ?></p>
        </div>
    <?php endif; ?>
</div>
