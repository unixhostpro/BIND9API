<?php
$main = [];

//Listen 
$main['listen_address'] = '127.0.0.1';
$main['listen_port'] = 8080;

//Redis
$main['redis_host'] = '127.0.0.1';
$main['redis_port'] = '6379';
$main['redis_user'] = '';
$main['redis_password'] = '';

//DNS
$main['default_nameservers'][] = 'ns1.example.com';
$main['default_nameservers'][] = 'ns2.example.com';
$main['default_ttl'] = 1440;
$main['domain_validate'] =  true;
$main['all_domains_list'] = 'https://raw.githubusercontent.com/zonedb/zonedb/main/zones.txt';
$main['all_domains_update_interval'] = 86400; //24 Hours
$main['zone_template_file'] = __DIR__ . '/example.com.db';
$main['named_checkzone'] = '/usr/sbin/named-checkzone';
$main['named_checkconf'] = '/usr/sbin/named-checkconf';
$main['named_reload_cmd'] = '/bin/sudo /bin/systemctl reload bind9.service';
$main['named_directory'] = '/etc/bind/zones';
$main['named_include_file'] = '/etc/bind/zones/include.conf';

//Cluster
$main['cluster'] = [];
//Example
//$main['cluster'][] = ['url' => 'https://127.0.0.2:9006', 'ssl_verify_peer' => false, 'ssl_verify_host' => false, 'user' => 'user', 'token' => ''];
