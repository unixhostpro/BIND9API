# BIND9API

<p>BIND9API - it's API server for DNS-zones management.</p>
<p>Based on <a href="https://github.com/gotzmann/comet">Comet</a> and <a href="https://github.com/Badcow/DNS">Badcow DNS Library</a>.</p>

## Requestments
 - Debain 10
 - BIND 9
 - PHP 7.4 with modules: curl, redis, mbstring, intl, gmp
 - Nginx
 - Redis

## Installation

Adding repositories
```
wget https://packages.sury.org/php/apt.gpg -O /etc/apt/trusted.gpg.d/php.gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list
apt update
apt full-upgrade
```

Installing required packages

```apt install bind9 redis-server php7.4-cli php7.4-redis php7.4-curl php7.4-mbstring php7.4-intl php7.4-gmp nginx sudo git composer```

Adding system user

```useradd dnsmanager -d /opt/dnsmanager -m```

Adding user permissions for reload dns server

```echo 'dnsmanager ALL=NOPASSWD: /bin/systemctl reload bind9.service' > /etc/sudoers.d/dnsmanager```

Setting BIND9
```
mkdir /etc/bind/zones
touch /etc/bind/zones/include.conf
chown dnsmanager:bind -R /etc/bind/zones
echo 'include "/etc/bind/zones/include.conf";' >> /etc/bind/named.conf
```

Cloning repository and installing dependencies
```
cd /opt/dnsmanager
git clone https://github.com/unixhostpro/BIND9API.git
cd BIND9API
composer install
```

Setting nginx config
```
rm -rf /etc/nginx/sites-available/default
cp /opt/dnsmanager/BIND9API/nginx-proxy.conf /etc/nginx/sites-available/default
```

Generating ssl certificate

```openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/ssl/private/nginx-selfsigned.key -out /etc/ssl/certs/nginx-selfsigned.crt```

Adding service to systemd

```cp /opt/dnsmanager/BIND9API/bind9api.service /etc/systemd/system/```

Changing files permissions

```chown dnsmanager:dnsmanager -R /opt/dnsmanager/```

Enabling and starting services
```
systemctl enable bind9 redis-server nginx bind9api
systemctl restart bind9 redis-server nginx bind9api
```

## Scripts for API user management

 - addClusterUser.php - adding cluster users (access to /cluster/import endpoint only)
 - addDefaultUser.php - adding users with default permissions (setting in config/api_config.php)
 - addSuperUser.php - adding users with all permissions
 - removeUser.php - removing users
 - showUsers.php - user listing

For showing usage parameters execute command: 

> php scriptName.php

## Usage 

Create user for api access
```
cd /opt/dnsmanager/BIND9API/scripts
php addSuperUser.php superuser 127.0.0.1/32
```

Then getting result

```
Username: superuser
Token: ed1a139acd28ffc77bd0f0b9df2ce4
Allowed IP's: 127.0.0.1
Super user: Yes
Expire: never
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$api_server_url = 'https://127.0.0.1:9090';
$api_server_username = 'superuser';
$api_server_token = 'ed1a139acd28ffc77bd0f0b9df2ce4';

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
```

## Cluster sync

Set up d cron job

```*/1 * * * * /usr/bin/php /opt/dnsmanager/BIND9API/cron.php```

## API Documentation
```GET /zones``` - Return list of DNS-zones

Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
| all | boolean | no | Only for Superuser. Return all DNS-zones on the server |
| limit | integer | no | Items return limit per request | 
| offset | integer | no | Number of first return item |

```PUT /zones``` - Create new DNS-zone
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name |
ip | string (ipv4) | yes | IP of main A record |
ip6 | string (ipv6) | no | IP of main AAAA record |
nameservers | array | no | Array of nameservers |
nameservers[ * ] | string | no | Nameserver addresses|
options | array | no | Array of options |
options[ttl] | integer | no | DNS zone TTL |
options[origin] | string | no | DNS zone Origin |
options[contact] | string | no | Administrator contact E-Mail |
options[serial] | integer | no | Serial number |
options[refresh] | integer | no | Refresh period |
options[retry] | integer | no | Retry period |
options[expiry] | integer | no | Expiry time |
options[minimum] | integer | no | Minimum TTL |

```DELETE /zones/{domain}``` - Delete DNS-zone
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name | 

```GET /zones/{domain}``` - Return records of DNS-zone
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name | 
soa | boolean | no | Include SOA record. As default ignored |

```PUT /zones/{domain}``` - Create record
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name | 
name | string | yes | Record name |
type | string | yes | Record type |
ttl | integer | no | Record TTL |
value | string | yes | Record value |

```POST /zones/{domain}/{id}``` - Record modify
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
id | integer | yes | Record ID | 
domain | string | yes | Domain name | 
ttl | integer | no | Record TTL | 
value | string | yes | Record value |

```DELETE /zones/{domain}/{id}``` - Delete record
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
id | integer | yes | Record ID | 
domain | string | yes | Domain name | 

```GET /users``` - List of API-users

No arguments.

```PUT /users``` - Create API-user
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
name | string | yes | Username |
allowed_ips | array | no | Array of allowed IP's to API access |
allowed_ips[ * ] | string (ipv4/ipv6) | no | IP or subnet (Example: 192.168.0.1 or 192.168.0.0/24) |
expire_timestamp | integer | no | Timestamp of expire user access. 0 - Never expire |
superuser | boolean | no | Superuser permissions |
access | array | no | Access to routes and methods |
access[ * ] | string | no | Access to route |
access[ * ][methods][ * ] | string | no | Access to methods of routes (GET/POST/PUT/DELETE) |

```GET /users/{name}``` - Return API-user information
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
name | string | yes | Username 

```POST /users/{name}``` - Modify API-user
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
name | string | yes | Username |
allowed_ips | array | no | Array of allowed IP's to API access |
token | string | no | Change access token |
allowed_ips[ * ] | string (ipv4/ipv6) | no | IP or subnet (Example: 192.168.0.1 or 192.168.0.0/24) |
expire_timestamp | integer | no | Timestamp of expire user access. 0 - Never expire |
superuser | boolean | no | Superuser permissions |
superuser | boolean | no | Superuser permissions |
access | array | no | Access to routes and methods |
access[ * ] | string | no | Access to route |
access[ * ][methods][ * ] | string | no | Access to methods of routes (GET/POST/PUT/DELETE) |

```DELETE /users/{name}``` - Delete API-user
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
name | string | yes | Username |

```GET /routes``` - List of available API-routes and methods

No arguments.

```PUT /import``` - Import DNS-zone as raw
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name | 
zone | string | yes | DNS-zone RAW |

```PUT /cluster/import``` - Import DNS-zone as raw and replace if exists.
Argument | Type |  Required | Description
--------- | ---- | ---- | ----------------
domain | string | yes | Domain name | 
zone | string | yes | DNS-zone RAW |
