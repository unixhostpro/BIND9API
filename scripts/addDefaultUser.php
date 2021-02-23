#!/usr/bin/php
<?php
if (!isset($argv['1'])) {
	echo "Use this script as: php addclusteruser.php USERNAME 0.0.0.0/0\n";
	exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Manager.php';

$manager = new UnixHost\DNS\Manager();

$username = trim($argv['1']);
$allowed = (isset($argv['2'])) ? trim($argv['2']) : '0.0.0.0/0';

if (strlen($username) < 3) {
	echo "USERNAME length must be more 2 letters";
}

if ($token = $manager->userCreate(['name' => $username, 'superuser' => false, 'access' => $manager->defaultUserPermissions(), 'allowed_ips' => [$allowed], 'expire_timestamp' => 0])) {
	echo "Username: $username\n";
	echo "Token: $token\n";
	echo "Allowed IP's: $allowed\n";
	echo "Super user: No\n";
	echo "Expire: never\n";
	exit;
}

if (count($manager->getErrors())) {
	foreach ($manager->getErrors() as $error) {
		echo $error . "\n";
	}
}
