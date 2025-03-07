<?php
/**
 * @package   DPDocker
 * @copyright Copyright (C) 2020 Digital Peak GmbH. <https://www.digital-peak.com>
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */

$wwwRoot	   = '/var/www/html/' . $argv[1];
$db			   = array_key_exists(3, $argv) ? $argv[3] : 'mysql';
$joomlaVersion = array_key_exists(4, $argv) ? $argv[4] : (substr($argv[1], -1) == 4 ? 4 : 3);
$force		   = array_key_exists(5, $argv) && $argv[5] === 'yes' ? true : false;
$isBranch      = strpos($joomlaVersion, '-dev') !== false;

if (is_dir($wwwRoot) && !$force) {
	return;
}

$completeVersion = $isBranch ? $joomlaVersion : null;
if (!$completeVersion) {
	foreach (json_decode(file_get_contents('https://downloads.joomla.org/api/v1/latest/cms'))->branches as $branch) {
		if ($branch->branch !== 'Joomla! ' . $joomlaVersion) {
			continue;
		}

		$completeVersion = $branch->version;
		break;
	}
}

// No version found
if (!$completeVersion) {
	return;
}

$syncBack = false;
if (!is_dir('/var/www/html/cache/' . $completeVersion)) {
	echo 'Cloning ' . $completeVersion . ' from repo to cache directory' . PHP_EOL;
	shell_exec('git clone https://github.com/joomla/joomla-cms.git /var/www/html/cache/' . $completeVersion . ' > /dev/null 2>&1');
	// Checkout latest stable release
	shell_exec('git --work-tree=/var/www/html/cache/' . $completeVersion . ' --git-dir=/var/www/html/cache/' . $completeVersion . '/.git checkout ' . ($isBranch ? $completeVersion : 'tags/' . $completeVersion) . ' > /dev/null 2>&1');
	$syncBack = true;
}

// Only update when it is a branch and not newly cloned
if ($isBranch && !$syncBack) {
	echo 'Updating branch ' . $completeVersion . PHP_EOL;
	shell_exec('git --work-tree=/var/www/html/cache/' . $completeVersion . ' --git-dir=/var/www/html/cache/' . $completeVersion . '/.git fetch > /dev/null 2>&1');
	shell_exec('git --work-tree=/var/www/html/cache/' . $completeVersion . ' --git-dir=/var/www/html/cache/' . $completeVersion . '/.git reset --hard > /dev/null 2>&1');
	shell_exec('git --work-tree=/var/www/html/cache/' . $completeVersion . ' --git-dir=/var/www/html/cache/' . $completeVersion . '/.git pull > /dev/null 2>&1');
	$syncBack = true;
}

echo 'Syncing cache to ' . $wwwRoot . PHP_EOL;
shell_exec('rsync -r --delete --exclude .git /var/www/html/cache/' . $completeVersion . '/ ' . $wwwRoot . ' > /dev/null 2>&1');

echo 'Using version ' . $completeVersion . ' on ' . $wwwRoot . PHP_EOL;

// Put joomla in dev state so we don't have to delete the installation directory
file_put_contents(
	$wwwRoot . '/libraries/src/Version.php',
	str_replace("const DEV_STATUS = 'Stable';", "const DEV_STATUS = 'Development';", file_get_contents($wwwRoot . '/libraries/src/Version.php'))
);

// Live output the install Joomla command
while (@ob_end_flush());
$proc = popen('/var/www/html/Projects/DPDocker/webserver/scripts/install-joomla.sh ' . $wwwRoot . ' ' . $db . ' sites_' . $argv[1] . ' "Joomla ' . $argv[1] . '" mailcatcher' .($isBranch ? ' r' : ''), 'r');
while (!feof($proc)) {
	echo fread($proc, 4096);
	@flush();
}

// When cloned sync back the assets and dependencies
if ($syncBack) {
	echo 'Syncing assets and dependencies back to cache ' . $wwwRoot . PHP_EOL;
	shell_exec('rsync -r --delete --exclude .git --exclude configuration.php --exclude node_modules ' . $wwwRoot . '/ /var/www/html/cache/' . $completeVersion . ' > /dev/null 2>&1');
}

// Check if extensions are needed to be installed
if (!$argv[2]) {
	return;
}

$folders = explode(',', $argv[2]);
if ($argv[2] == 'all') {
	$folders = array_diff(scandir(dirname(dirname(dirname(__DIR__)))), ['..', '.', 'DPDocker']);
}

foreach ($folders as $project) {
	// Ignore projects with a dash when not a dev site is built and we use all extensions
	if ($argv[2] == 'all' && strpos($argv[1], 'dev') === false && strpos($project, '-') > 0) {
		continue;
	}

	// Ignore all non dev projects when we have a dev site and we use all extensions
	if ($argv[2] == 'all' && strpos($argv[1], 'dev') !== false && strpos($project, '-Dev') === false) {
		continue;
	}

	// Check if it is a Joomla installation
	if (is_dir('/var/www/html/Projects/' . $project . '/includes')) {
		continue;
	}

	// Ignore files
	if (is_file('/var/www/html/Projects/' . $project)) {
		continue;
	}

	createLinks('/var/www/html/Projects/' . $project . '/', $wwwRoot);
}
echo 'Discovering extensions on ' . $wwwRoot . PHP_EOL;
copy('/var/www/html/Projects/DPDocker/webserver/scripts/discoverapp.php', $wwwRoot . '/discoverapp.php');
shell_exec('php ' . $wwwRoot . '/discoverapp.php');
unlink($wwwRoot . '/discoverapp.php');

function createLinks($folderRoot, $wwwRoot)
{
	echo 'Starting to create the links for ' . $folderRoot . PHP_EOL;

	// Folder structure like https://github.com/joomla-extensions/weblinks
	if (file_exists($folderRoot . '/src/administrator/components/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/administrator/components/') as $filename) {
			createLink($folderRoot . '/src/administrator/components/' . $filename, $wwwRoot . '/administrator/components/' . $filename);
		}
	}
	if (file_exists($folderRoot . '/src/administrator/manifests/packages/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/administrator/manifests/packages/') as $filename) {
			createLink(
				$folderRoot . '/src/administrator/manifests/packages/' . $filename,
				$wwwRoot . '/administrator//manifests/packages/' . $filename
			);
		}
	}
	if (file_exists($folderRoot . '/src/api/components/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/api/components/') as $filename) {
			createLink($folderRoot . '/src/api/components/' . $filename, $wwwRoot . '/api/components/' . $filename);
		}
	}
	if (file_exists($folderRoot . '/src/components/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/components/') as $filename) {
			createLink($folderRoot . '/src/components/' . $filename, $wwwRoot . '/components/' . $filename);
		}
	}
	if (file_exists($folderRoot . '/src/administrator/language')) {
		$languages = scandir($folderRoot . '/src/administrator/language');
		foreach ($languages as $language) {
			foreach (new DirectoryIterator($folderRoot . '/src/administrator/language/' . $language) as $filename) {
				createLink(
					$folderRoot . '/src/administrator/language/' . $language . $filename,
					$wwwRoot . '/administrator/language/' . $language . $filename
				);
			}
		}
	}
	if (file_exists($folderRoot . '/src/language')) {
		$languages = scandir($folderRoot . '/src/language');
		foreach ($languages as $language) {
			foreach (new DirectoryIterator($folderRoot . '/src/language/' . $language) as $filename) {
				createLink($folderRoot . '/src/language/' . $language . $filename, $wwwRoot . '/language/' . $language . $filename);
			}
		}
	}
	if (file_exists($folderRoot . '/src/media/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/media/') as $filename) {
			createLink($folderRoot . '/src/media/' . $filename, $wwwRoot . '/media/' . $filename);
		}
	}
	if (file_exists($folderRoot . '/src/modules/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/modules/') as $filename) {
			createLink($folderRoot . '/src/modules/' . $filename, $wwwRoot . '/modules/' . $filename);
		}
	}
	if (file_exists($folderRoot . '/src/plugins')) {
		$groups = scandir($folderRoot . '/src/plugins');
		foreach ($groups as $group) {
			foreach (new DirectoryIterator($folderRoot . '/src/plugins/' . $group) as $filename) {
				createLink($folderRoot . '/src/plugins/' . $group . '/' . $filename, $wwwRoot . '/plugins/' . $group . '/' . $filename);
			}
		}
	}
	if (file_exists($folderRoot . '/src/templates/')) {
		foreach (new DirectoryIterator($folderRoot . '/src/templates/') as $filename) {
			createLink($folderRoot . '/src/templates/' . $filename, $wwwRoot . '/templates/' . $filename);
		}
	}

	foreach (new DirectoryIterator($folderRoot) as $filename) {
		// Ignore files
		if (is_file($folderRoot . $filename)) {
			continue;
		}

		if (strpos($filename, 'com_') === 0) {
			createLink($folderRoot . $filename . '/admin', $wwwRoot . '/administrator/components/' . $filename);
			createLink($folderRoot . $filename . '/site', $wwwRoot . '/components/' . $filename);
			createLink($folderRoot . $filename . '/media', $wwwRoot . '/media/' . $filename);

			if (file_exists($folderRoot . $filename . '/api') && file_exists($wwwRoot . '/api/components/')) {
				createLink($folderRoot . $filename . '/api', $wwwRoot . '/api/components/' . $filename);
			}
		}
		if (strpos($filename, 'mod_') === 0) {
			createLink($folderRoot . $filename, $wwwRoot . '/modules/' . $filename);

			if (file_exists($folderRoot . $filename . '/media')) {
				createLink($folderRoot . $filename . '/media', $wwwRoot . '/media/' . $filename);
			}
		}
		if (strpos($filename, 'plg_') === 0) {
			foreach (new RegexIterator(new DirectoryIterator($folderRoot . $filename), "/\\.xml\$/i") as $pluginFile) {
				$xml = new SimpleXMLElement(file_get_contents($folderRoot . $filename . '/' . $pluginFile));

				foreach ($xml->files->children() as $file) {
					$plugin = (string)$file->attributes()->plugin;
					if (!$plugin) {
						continue;
					}

					$group = (string)$xml->attributes()->group;
					if (!is_dir($wwwRoot . '/plugins/' . $group)) {
						@mkdir($wwwRoot . '/plugins/' . $group, '0777', true);
						exec('chmod 777 ' . $wwwRoot . '/plugins/' . $group);
					}

					createLink($folderRoot . $filename, $wwwRoot . '/plugins/' . $group . '/' . $plugin);

					if (file_exists($folderRoot . $filename . '/media')) {
						createLink($folderRoot . $filename . '/media', $wwwRoot . '/media/' . $filename);
					}
				}
			}
		}
		if (strpos($filename, 'tmpl_') === 0) {
			createLink($folderRoot . $filename, $wwwRoot . '/templates/' . str_replace('tmpl_', '', $filename));
		}
		if (strpos($filename, 'lib_') === 0) {
			createLink($folderRoot . $filename, $wwwRoot . '/libraries/' . $filename);
			createLink($folderRoot . $filename . '/' . $filename . '.xml', $wwwRoot . '/administrator/manifests/libraries/' . $filename . '.xml');

			if (file_exists($folderRoot . $filename . '/media')) {
				createLink($folderRoot . $filename . '/media', $wwwRoot . '/media/' . $filename);
			}
		}
	}
	echo 'Finished to create the links for ' . $folderRoot . PHP_EOL;
}

function createLink($source, $target)
{
	$source = realpath($source);

	@mkdir(dirname($target), '777', true);
	shell_exec('ln -sfn ' . $source . ' ' . $target);
}
