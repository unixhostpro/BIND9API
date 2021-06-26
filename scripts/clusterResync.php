#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Manager.php';

$manager = new UnixHost\DNS\Manager();

foreach ($manager->scanDomains() as $domain) {
	$manager->clusterTaskAdd($domain);
}

echo "All zones added to Cluster Sync task list. It will be synced by cronjob.\n";
