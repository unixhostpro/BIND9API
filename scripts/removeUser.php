<?php
if (!isset($argv['1'])) {
	echo "Use this script as: php removeuser.php USERNAME\n";
	exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Manager.php';

$manager = new UnixHost\DNS\Manager();

if ($manager->userRemove(trim($argv['1']))) {
	echo "Done.\n";
	exit;
}
if (count($manager->getErrors())) {
	foreach ($manager->getErrors() as $error) {
		echo $error . "\n";
	}
}
