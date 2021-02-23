<?php
require_once __DIR__ . '/vendor/autoload.php';

$api_server_url = 'https://127.0.0.1:9090';
$api_server_username = 'username';
$api_server_token = 'token';

$api = new UnixHost\DNS\Client;
$api->setCredentials($api_server_url, $api_server_username, $api_server_token);

//Create new DNS zone
$params = ['domain' => 'mynewdomain.com', 'nameservers' => ['ns1.mydnsserver.com', 'ns2.mydnsserver.com']];
$create = $api->put('/zones', $params);
if (isset($create['success'])) {
  echo "DNS zone succesfully created.\n";
}

//Get records
$result = $api->get('/zones/mynewdomain.com');
print_r($result);
