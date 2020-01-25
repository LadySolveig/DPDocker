<?php
/**
 * @author     Digital Peak http://www.digital-peak.com
 * @copyright  Copyright (C) 2007 - 2020 Digital Peak. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

$toDelete = [
	'bin/',
	'test/',
	'tests/',
	'doc/',
	'docs/',
	'examples',
	'phpunit',
	'git',
	'build/',
	'composer.json',
	'README',
	'ChangeLog',
	'CONTRIBUTING',
	'UPGRADE',
	'Makefile',
	'.travis',
	'.styleci',
	'.sh',
	'.dist',
	'scrutinizer'
];

$folder = $argv[1] . '/vendor';
if (!is_dir($folder)) {
	echo 'No vendor dir found!!';

	return;
}

if (file_exists($folder . '/../vendor-ignore.txt')) {
	$toDelete = array_merge($toDelete, explode(PHP_EOL, file_get_contents($folder . '/../vendor-ignore.txt')));
}

$index = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder), RecursiveIteratorIterator::SELF_FIRST);
foreach ($files as $file) {
	if ($file->getFileName() == '.' || $file->getFileName() == '..') {
		continue;
	}

	$path = $file->getPathName();
	if (is_dir($path)) {
		$path .= '/';
	}

	if (isInIndex($path, $index)) {
		continue;
	}

	foreach ($toDelete as $ex) {
		if ($ex && stripos(str_replace($folder, '', $path), $ex) !== false) {
			$index[] = $path;
		}
	}
}

foreach ($index as $file) {
	echo $file . PHP_EOL;
	delete($file);
}

function delete($target)
{
	if (!is_dir($target) && !is_file($target)) {
		return;
	}

	shell_exec('rm -rf ' . $target);
}

function isInIndex($fileName, $index)
{
	foreach ($index as $entry) {
		if (stripos($fileName, $entry) !== false) {
			return true;
		}
	}

	return false;
}
