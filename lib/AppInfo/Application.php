<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Officeonline\AppInfo;

use OC\Files\Type\Detection;
use OC\Security\CSP\ContentSecurityPolicy;
use OCA\Federation\TrustedServers;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCA\Officeonline\Capabilities;
use OCA\Officeonline\Hooks\WopiLockHooks;
use OCA\Officeonline\Listener\LoadOfficeOnlineFilesActions;
use OCA\Officeonline\Listener\LoadOfficeOnlineFilesSharingActions;
use OCA\Officeonline\Middleware\WOPIMiddleware;
use OCA\Officeonline\PermissionManager;
use OCA\Officeonline\Preview\MSExcel;
use OCA\Officeonline\Preview\MSWord;
use OCA\Officeonline\Preview\OOXML;
use OCA\Officeonline\Preview\OpenDocument;
use OCA\Officeonline\Preview\Pdf;
use OCA\Officeonline\Service\FederationService;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\QueryException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IPreview;

class Application extends App implements IBootstrap {
	public const APP_ID = 'officeonline';

	/**
	 * Strips the path and query parameters from the URL.
	 *
	 * @param string $url
	 * @return string
	 */
	private function domainOnly(string $url): string {
		$parsed_url = parse_url(trim($url));
		$scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		return "$scheme$host$port";
	}

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadOfficeOnlineFilesActions::class);
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, LoadOfficeOnlineFilesSharingActions::class);
    }

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

    public function boot(IBootContext $context): void {
        // Check if the app is enabled for the current user
        $currentUser = \OC::$server->getUserSession()->getUser();
        if ($currentUser !== null) {
            /** @var PermissionManager $permissionManager */
            $permissionManager = \OC::$server->query(PermissionManager::class);
            if (!$permissionManager->isEnabledForUser($currentUser)) {
                return;
            }
        }

        // Add to LoadViewer if loaded
        try {
            /** @var IEventDispatcher $eventDispatcher */
            $eventDispatcher = $this->getContainer()->getServer()->query(IEventDispatcher::class);
            if (class_exists(LoadViewer::class)) {
                $eventDispatcher->addListener(LoadViewer::class, function () {
                    \OCP\Util::addScript('officeonline', 'viewer');
                });
            }
        } catch (QueryException $e) {
        }

        // Register components
        $this->getContainer()->registerCapability(Capabilities::class);
        $this->getContainer()->registerMiddleWare(WOPIMiddleware::class);

        $context->injectFn([$this, 'registerProvider']);
        $context->injectFn([$this, 'updateCSP']);
    }

	public function registerProvider() {
		$container = $this->getContainer();

		// Register mimetypes
		/** @var Detection $detector */
		$detector = $container->query(\OCP\Files\IMimeTypeDetector::class);
		$detector->getAllMappings();
		$detector->registerType('ott', 'application/vnd.oasis.opendocument.text-template');
		$detector->registerType('ots', 'application/vnd.oasis.opendocument.spreadsheet-template');
		$detector->registerType('otp', 'application/vnd.oasis.opendocument.presentation-template');

		/** @var IPreview $previewManager */
		$previewManager = $container->query(IPreview::class);

		$previewManager->registerProvider('/application\/vnd.ms-excel/', function () use ($container) {
			return $container->query(MSExcel::class);
		});

		$previewManager->registerProvider('/application\/msword/', function () use ($container) {
			return $container->query(MSWord::class);
		});

		$previewManager->registerProvider('/application\/vnd.openxmlformats-officedocument.*/', function () use ($container) {
			return $container->query(OOXML::class);
		});

		$previewManager->registerProvider('/application\/vnd.oasis.opendocument.*/', function () use ($container) {
			return $container->query(OpenDocument::class);
		});

		$previewManager->registerProvider('/application\/pdf/', function () use ($container) {
			return $container->query(Pdf::class);
		});

		$container->query(WopiLockHooks::class)->register();
	}

	public function updateCSP() {
		$container = $this->getContainer();

		$publicWopiUrl = \OC::$server->getConfig()->getAppValue('officeonline', 'wopi_url');
		$publicWopiUrl = $publicWopiUrl === '' ? \OC::$server->getConfig()->getAppValue('officeonline', 'wopi_url') : $publicWopiUrl;
		$cspManager = $container->getServer()->getContentSecurityPolicyManager();
		$policy = new ContentSecurityPolicy();
		if ($publicWopiUrl !== '') {
			$policy->addAllowedFrameDomain('\'self\'');
			$policy->addAllowedFrameDomain($this->domainOnly($publicWopiUrl));
			if (method_exists($policy, 'addAllowedFormActionDomain')) {
				$policy->addAllowedFormActionDomain($this->domainOnly($publicWopiUrl));
			}
		}

		/**
		 * Dynamically add CSP for federated editing
		 */
		$path = '';
		try {
			$path = $container->getServer()->getRequest()->getPathInfo();
		} catch (\Exception $e) {
		}
		if (strpos($path, '/apps/files') === 0 && $container->getServer()->getAppManager()->isEnabledForUser('federation')) {
			/** @var TrustedServers $trustedServers */
			$trustedServers = $container->query(TrustedServers::class);
			/** @var FederationService $federationService */
			$federationService = $container->query(FederationService::class);
			$remoteAccess = $container->getServer()->getRequest()->getParam('officeonline_remote_access');

			if ($remoteAccess && $trustedServers->isTrustedServer($remoteAccess)) {
				$remoteCollabora = $federationService->getRemoteCollaboraURL($remoteAccess);
				$policy->addAllowedFrameDomain($remoteAccess);
				$policy->addAllowedFrameDomain($remoteCollabora);
			}
		}

		$cspManager->addDefaultPolicy($policy);
	}
}
