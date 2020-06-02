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
/** @var $_ array */
?>

<div id="CODE-settings" class="section">
    <h2>Collabora Online - Built-in CODE Server</h2>
    <?php if($_['richdocumentsEnabled'] === 'yes'): ?>
        <div id="richdocuments-Enabled">
            <p>You have the Collabora Online app enabled. Please check <em>Settings > Admin > Collabora Online</em> for further information and configuration.</p>
        </div>
    <?php elseif($_['richdocumentsEnabled'] === 'no'): ?>
        <div id="richdocuments-NotEnabled">
            <p>Built-in CODE server is designed to work with the usual Collabora Online app. You may <u><a href="<?php echo($_['richdocumentsURL']) ?>">install it from the Nextcloud App Store.</a></u></p>
        </div>
    <?php else: ?>
        <div id="richdocuments-EnabledCheckFailed">
            An error occurred while trying to check your Collabora Online app installation. You may report this error with the tag: <em>richdocuments-EnabledCheckFailed</em></p>
        </div>
    <?php endif; ?>
</div>
