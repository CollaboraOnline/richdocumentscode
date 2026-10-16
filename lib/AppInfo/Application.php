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

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
	// Read by proxy.php, which runs outside Nextcloud, when it starts coolwsd
	public const FONT_CONFIG_URL_FILE = 'coolwsd-font-config-url';

	// How old the file may get before a web request writes it again
	private const FONT_CONFIG_URL_MAX_AGE = 3600;

	public function __construct()
	{
		parent::__construct('richdocumentscode');
	}

	public function register(IRegistrationContext $context): void
	{
	}

	public function boot(IBootContext $context): void
	{
		// The URL generator only knows the host the users reach Nextcloud at in a web request
		if (PHP_SAPI === 'cli')
			return;

		// The same directory as the lock and pid files of proxy.php
		$tmpDir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();
		$file = $tmpDir . '/' . self::FONT_CONFIG_URL_FILE;

		$mtime = @filemtime($file);
		if ($mtime !== false && time() - $mtime < self::FONT_CONFIG_URL_MAX_AGE)
			return;

		$container = $context->getServerContainer();
		$urlGenerator = $container->get(IURLGenerator::class);
		try
		{
			// The route of the richdocuments app, wherever that app is installed
			$route = $urlGenerator->linkToRoute('richdocuments.settings.getJsonFontList');
		}
		catch (\Throwable $e)
		{
			return;
		}

		// The route is unknown when richdocuments is not enabled
		if ($route === '')
			return;

		$url = $urlGenerator->getAbsoluteURL($route);

		$tmpFile = $file . '.' . getmypid();
		if (@file_put_contents($tmpFile, $url) === false || !@rename($tmpFile, $file))
		{
			@unlink($tmpFile);
			$container->get(LoggerInterface::class)->debug('Cannot write ' . $file, ['app' => 'richdocumentscode']);
		}
	}
}
