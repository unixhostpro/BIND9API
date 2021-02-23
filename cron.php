<?php
$pidFile = '/tmp/dnsmanager_cron.pid';

if (file_exists($pidFile)) {
	$pid = trim(file_get_contents($pidFile));
	if ($pid > 0) {
		if (file_exists('/proc/' . $pid)) {
			echo "DNSManager CRON already running.\n";
			exit;
		}
	}
}

if (@!file_put_contents($pidFile, getmypid())) {
	echo "Failed to write to $pidFile file\n";
	exit;	
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Manager.php';
require_once __DIR__ . '/lib/Client.php';

$manager = new UnixHost\DNS\Manager();
$remote = new UnixHost\DNS\Client();

if ($manager->getBindReloadRequest()) {
	$manager->bindReload();
}

foreach ($manager->clusterTasks() as $domain => $results) {
	if ($zone = $manager->zoneOpen($domain)) {
		foreach ($manager->getClusterConfig() as $server) {
			$remote->setCredentials($server['url'], $server['user'], $server['token']);
			if ($result = $remote->put('/cluster/import', ['domain' => $domain, 'zone' => $zone])) {
				$manager->clusterTaskRemove($domain, $server['url']);	
			}
		}
	}
}

@unlink($pidFile);
