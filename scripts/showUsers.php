<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Manager.php';

$manager = new UnixHost\DNS\Manager();

foreach ($manager->getUsers() as $user) {
	$superuser = (isset($user['superuser']) && $user['superuser']) ? 'YES' : 'NO';
	$expires = ($user['expire_timestamp'] != false) ? date('d.m.Y H:i:s', $user['expire_timestamp']) : 'never';
	echo "============================\n";
	echo "USERNAME: " . $user['name'] . "\n";
	echo "EXPIRES: " . $expires . "\n";
	echo "SUPERUSER: " . $superuser . "\n";
	if (isset($user['allowed_ips']) && count($user['allowed_ips'])) {
		echo "ALLOWED IP's: \n" . implode("\n", $user['allowed_ips']) . "\n";
	}
	if (isset($user['access']) && count($user['access'])) {
		echo "Permissions: \n";
		foreach ($user['access'] as $route => $params) {
			echo $route . " " . implode(', ', $params['methods']) . "\n";
		}
	}
}
