<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.pwa
 *
 * @copyright   Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

JLoader::register('JFile',  JPATH_PLATFORM . '/joomla/filesystem/file.php');

/**
 * Class PlgSystemPwa
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgSystemPwa extends CMSPlugin
{
	/**
	 * Application object.
	 *
	 * @var    CMSApplicationInterface
	 * @since  3.3
	 */
	protected $app;

	/**
	 * Load the progressive web app and it's web workers onto any HTML page
	 *
	 * @return  void
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function onBeforeRender()
	{
		// This is only for web interfaces so if we're on a CLI environment just bail here
		if ($this->app->isCli())
		{
			return;
		}

		/** @var CMSApplication $app */
		$app = $this->app;
		$doc = $app->getDocument();

		// If we aren't displaying a HTML page, let's also not insert the information onto the page.
		if ($doc->getType() !== 'html')
		{
			return;
		}

		/** @var $doc JDocumentHtml */

		if (!$app->isClient('site'))
		{
			return;
		}

		$name_of_file = $this->params->get('name_of_file', 'manifest.json');
		$short_name = $this->params->get('short_name', 'Short Name');
		$icon1 = $this->params->get('icon1', 'icon1');
		$icon2 = $this->params->get('icon2', 'icon2');
		$includeserviceworkers = $this->params->get('includeserviceworkers', '0');

		$doc->addHeadLink($name_of_file, 'manifest');
		$doc->addHeadLink($icon1, 'icon', 'rel', array('sizes' => '16x16'));
		$doc->addHeadLink($icon2, 'icon', 'rel', array('sizes' => '512x512'));

		// Fallback application metadata for legacy browsers
		$doc->setMetaData('application-name', $short_name);

		// Import service worker plugin group
		PluginHelper::importPlugin('service-worker');

		// This is an array of service worker files that we need to include
		// TODO: What should come back here. Filename and init script?
		/** @var string[] $plugins */
		$plugins = $app->triggerEvent('onGetServiceWorkers');

		if ($includeserviceworkers && count($plugins))
		{
			foreach ($plugins as $plugin)
			{
				$doc->addScriptDeclaration('
				if(\'serviceWorker\' in navigator) {
					navigator.serviceWorker
					.register(\'' . $plugin . '\')
					.then(function(registration) {
						//Registration Worked
						console.log("Service Worker Registered scope is " + registration.scope);
					}).catch(function(error){
						// registration failed
						console.log(\'Registration failed with \' + error);
					});
					navigator.serviceWorker.ready.then(function(registration) {
						console.log("Service Worker Ready");
					});
				}
				');

			}
		}
	}

	/**
	 * Removes or publishes the manifest and service workers if our plugin is disabled or
	 * a service plugin is disabled
	 *
	 * @param   string  $context  The context of the extension
	 * @param   array   $pks      The ids of the extension who's state is being changed
	 * @param   int     $value    The new value of the state
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onContentChangeState($context, $pks, $value)
	{
		if ($context === 'com_plugins.plugin')
		{
			foreach ($pks as $pk)
			{
				/** @var Extension $extensionTable */
				$extensionTable = Table::getInstance('Extension', 'JTable');
				$extensionTable->reset();
				$extensionTable->load($pk);

				if ($extensionTable->name === 'plg_system_pwa' && $value === 1)
				{
					$this->buildManifestFile($this->params);
					$this->createServiceWorkerManifest($this->params);
				}
				else if ($extensionTable->name === 'plg_system_pwa' && $value === 0)
				{
					$this->deleteManifestFile($this->params->get('name_of_file', 'manifest.json'));
					$this->deleteServerWorkerManifestFile();
				}
				else if ($extensionTable->folder === 'service-worker')
				{
					$this->createServiceWorkerManifest($this->params);
				}
			}
		}
	}

	/**
	 * If we are saving a plugin then we need to check if it is either a service worker (if so we need to regenerate
	 * the service workers file). If it's the plugin itself, and the plugin is being disabled then we need to destroy
	 * the manifest file.
	 *
	 * @param   string     $context  The context of the extension
	 * @param   Extension  $table    The table object for the extension that was just saved
	 * @param   boolean    $isNew    Is this a new extension
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterSave($context, $table, $isNew)
	{
		if ($context === 'com_plugins.plugin')
		{
			$result = $table->name;

			if ($result === 'plg_system_pwa')
			{
				$publishedStatus = $table->enabled;
				$newParams = json_decode($table->params, true);

				// Something has gone wrong - we don't have a json object for the params
				if ($newParams == null)
				{
					return;
				}

				$newParams = new Registry($newParams);

				// This is stored as a string(!?) so do a weak comparison here
				if ($publishedStatus == 1)
				{
					$this->buildManifestFile($newParams);
				}
				else
				{
					$this->deleteManifestFile($newParams->get('name_of_file', 'manifest.json'));
					$this->deleteServerWorkerManifestFile();
				}

				$includeServiceWorkers = $newParams->get('includeserviceworkers', '0');

				if ($includeServiceWorkers && $publishedStatus == 1)
				{
					$this->createServiceWorkerManifest($newParams);
				}
			}
			elseif ($table->folder === 'service-worker')
			{
				$this->createServiceWorkerManifest($this->params);
			}
		}
	}

	/**
	 * Removes the manifests if our plugin is uninstalled or updates the server manifest if
	 * a service plugin is uninstalled
	 *
	 * @param   array  $pk  The id of the extension that is being uninstalled
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionBeforeUninstall($pk)
	{
		/** @var Extension $extensionTable */
		$extensionTable = Table::getInstance('Extension', 'JTable');
		$extensionTable->reset();
		$extensionTable->load($pk);

		if ($extensionTable->name === 'plg_system_pwa')
		{
			$this->deleteManifestFile($this->params->get('name_of_file', 'manifest.json'));
			$this->deleteServerWorkerManifestFile();
		}
		else if ($extensionTable->folder === 'service-worker')
		{
			$this->createServiceWorkerManifest($this->params);
		}
	}

	/**
	 * Updates the manifests if our plugin is updated or updates the server manifest if
	 * a service plugin is updated
	 *
	 * @param   array  $installer  The reference to the installer object
	 * @param   array  $pk         The id of the extension that is being uninstalled
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterUpdate($installer, $pk)
	{
		/** @var Extension $extensionTable */
		$extensionTable = Table::getInstance('Extension', 'JTable');
		$extensionTable->reset();
		$extensionTable->load($pk);

		if ($extensionTable->name === 'plg_system_pwa')
		{
			$this->buildManifestFile($this->params);
			$this->createServiceWorkerManifest($this->params);
		}
		else if ($extensionTable->folder === 'service-worker')
		{
			$this->createServiceWorkerManifest($this->params);
		}
	}

	/**
	 * Builds the manifest file from a given set of parameters
	 *
	 * @param   Registry  $params  The parameters used to build the manifest file (note this isn't always the active
	 *                             parameters (e.g. on saving the manifest plugin the new parameters aren't saved)
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function buildManifestFile(Registry $params)
	{
		$name_of_file = $params->get('name_of_file', 'manifest.json');
		$dir = $params->get('dir', 'ltr');
		$lang = $params->get('lang', 'en');
		$name = $params->get('name');
		$short_name = $params->get('short_name');
		$description = $params->get('description', 'Description of Application');
		$scope = $params->get('scope', '');
		$icons = $params->get('icons', '');
		$display = $params->get('display', 'Standalone');
		$orientation = $params->get('orientation', 'Any');
		$start_url = $params->get('start_url', '/');
		$themecolor = $params->get('themecolor', '#eee');
		$related_applications = $params->get('related_applications', '');
		$prefer_related_applications = $params->get('prefer_related_applications', "false");
		$backgroundcolor = $params->get('backgroundcolor');

		$manifestItems = [];

		if (!empty($lang))
		{
			$manifestItems['lang'] = $lang;
		}

		if (!empty($dir))
		{
			$manifestItems['dir'] = $dir;
		}

		if (!empty($name))
		{
			$manifestItems['name'] = $name;
		}

		if (!empty($name))
		{
			$manifestItems['short_name'] = $short_name;
		}

		if (!empty($description))
		{
			$manifestItems['description'] = $description;
		}

		if (!empty($scope))
		{
			$manifestItems['scope'] = $scope;
		}

		if (!empty($icons))
		{
			$manifestItems['icons'] = [];

			foreach($icons as $icon)
			{
				$manifestIcon = [
					'src' => $icon->src,
					'sizes' => $icon->size[0],
					'type' => $icon->type[0] . count($icons)
				];

				$manifestItems['icons'][] = $manifestIcon;
			}
		}

		if (!empty($display))
		{
			$manifestItems['display'] = $display;
		}

		if (!empty($orientation))
		{
			$manifestItems['orientation'] = $orientation;
		}

		if (!empty($start_url))
		{
			$manifestItems['start_url'] = $start_url;
		}

		if (!empty($themecolor))
		{
			$manifestItems['theme_color'] = $themecolor;
		}

		if (!empty($related_applications))
		{
			$manifestItems['related_applications'] = $related_applications;
		}

		if (!empty($prefer_related_applications))
		{
			$manifestItems['prefer_related_applications'] = filter_var($prefer_related_applications, FILTER_VALIDATE_BOOLEAN);
		}

		if (!empty($backgroundcolor))
		{
			$manifestItems['background_color'] = $backgroundcolor;
		}

		$fileManifestWrite = JPATH_ROOT . "/" . $name_of_file;
		JFile::write($fileManifestWrite, json_encode($manifestItems));
	}

	/**
	 * Deletes the manifest file from the filesystem
	 *
	 * @param   string  $fileName  The filename to delete
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function deleteManifestFile($fileName)
	{
		JFile::delete(JPATH_ROOT . '/' . $fileName);
	}

	/**
	 * Creates the service worker manifest file
	 *
	 * @param   string  $fileName  The filename to delete
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function createServiceWorkerManifest(Registry $params)
	{
		$root = Uri::root();
		$host = Uri::getInstance($root)->getHost();

		$javascriptContents = 'self.addEventListener(\'install\', function(event) {
	event.waitUntil(
		caches.open(\'' . $host . '\').then(function(cache) {
			return cache.addAll([
				\'' . $params->get('start_url', '/') . '\'
						  ]).then(function() {
				return self.skipWaiting();
			});
		})
	);
});

self.addEventListener(\'activate\', function(event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener(\'fetch\', function(event) {
	console.log(event.request.url);

	event.respondWith(
		caches.match(event.request).then(function(response) {
			return response || fetch(event.request);
		})
	);
});';

		JFile::write(JPATH_ROOT . "/serviceWorker.js", $javascriptContents);
	}

	/**
	 * Deletes the service worker manifest file from the filesystem
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function deleteServerWorkerManifestFile()
	{
		JFile::delete(JPATH_ROOT . '/serviceWorker.js');
	}
}
