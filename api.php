<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Manager.php';
require_once __DIR__ . '/lib/ValidationRules.php';

$manager = new UnixHost\DNS\Manager();
$app = new Comet\Comet(['host' => $manager->listenAddress(), 'port' => $manager->listenPort()]);

$app->get('/zones/{domain}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict', 
			'soa' => 'boolean'
		]);
		if ($data && $manager->zoneUserAccess($data['domain'])) {
			$soa = (isset($data['soa']) && $data['soa']) ? true : false;	
			return $response->with($manager->zoneArray($data['domain'], $soa), 200);
		}
	}	
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->put('/zones/{domain}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict',
			'name' => 'required|recordname',
			'type' => 'required|recordtype',
			'ttl' => 'integer|min:1|max:604800',
			'value' => 'required|min:1'
		]);
		if ($data && $manager->zoneUserAccess($data['domain'])) {
			if ($manager->zoneRecordAdd($data)) {	
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->post('/zones/{domain}/{id}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'id' => 'required|integer|min:0',
			'domain' => 'required|domainstrict',
			'ttl' => 'integer|min:1|max:604800',
			'value' => 'required|min:1'
		]);
		if ($data && $manager->zoneUserAccess($data['domain'])) {
			if ($manager->zoneRecordModify($data)) {
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->delete('/zones/{domain}/{id}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'id' => 'required|integer|min:1',
			'domain' => 'required|domainstrict'
		]);
		if ($data && $manager->zoneUserAccess($data['domain'])) {
			if ($manager->zoneRecordRemove($data['domain'], $data['id'])) {
				return $response->with(['success' => true], 200);
			}
		}			
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->delete('/zones/{domain}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict'
		]);
		if ($data && $manager->zoneUserAccess($data['domain'])) {
			if ($manager->zoneRemove($data['domain'])) {
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->get('/zones', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator([
			'all' => 'boolean',
			'limit' => 'integer|min:1',
			'offset' => 'integer|min:0'
		]);
		if ($data) {
			$scan = (isset($data['all']) && $data['all'] && $manager->iamSuperuser()) ? $manager->scanDomains() : $manager->scanOwnDomains();
			if (is_array($scan)) {
				$domains = [];
				$total_count = count($scan);
				if (isset($data['offset'])) {
					$data['limit'] = (!isset($data['limit'])) ? 10 : $data['limit'];
					$limit = ($data['offset'] + $data['limit']) - 1;
					$i = 0;
					foreach ($scan as $domain) {
						if ($i >= $data['offset']) {
							$domains[] = $domain;
							if ($i >= $limit) {
								break;
							}
						}
						$i++;
					}
				} else {
					$domains = $scan;
				}
				return $response->with(['total_count' => $total_count, 'zones' => $domains], 200);
			} else {
				$manager->setError('Zones not found', 404);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->put('/zones', function ($request, $response) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict',
			'ip' => 'ipv4',
			'ip6' => 'ipv6',  
			'nameservers' => 'array', 
			'nameservers.*' => 'required|domain', 
			'options' => 'array',
			'options.ttl' => 'integer|min:1|max:604800',
			'options.origin' => 'domain',
			'options.contact' => 'email',
			'options.serial' => 'integer|min:0|max:2147483646',
			'options.refresh' => 'integer|min:1800|max:2419200',
			'options.retry' => 'integer|min:300|max:1209600',
			'options.expiry' => 'integer|min:1800|max:31536000',
			'options.minimum' => 'integer|min:1|max:604800'
		]);
		if ($data) {
			if (!$manager->zoneExists($data['domain'])) {
				if ($manager->isAllowedDomainName($data['domain'])) {
					if ($manager->zoneCreate($data)) {	
						return $response->with(['success' => true], 200);
					}				
				} else {
					$manager->setError('Domain name not allowed here', 406);
				}			
			} else {
				$manager->setError('Zone already exists', 406);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());		
});

$app->get('/users', function ($request, $response) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		return $response->with($manager->getUsers(), 200);
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());			
});

$app->get('/users/{name}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		$data = $manager->validator([
			'name' => 'required|alpha_dash|min:3'
		]);		
		if ($data) {
			if ($user = $manager->getUser($data['name'])) {
				return $response->with($user, 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());			
});

$app->post('/users/{name}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$allRoutes = $manager->getAllRoutes();
		$data = $manager->validator([
			'name' => 'required|alpha_dash|min:3',
			'token' => 'min:30',
			'allowed_ips' => 'array|min:1',
			'allowed_ips.*' => 'ipsubnet',
			'expire_timestamp' => 'integer',
			'superuser' => 'boolean', 
			'access' => [
				'array',
				function ($values) use ($allRoutes) {
					if (isset($values)) {
						foreach ($values as $route => $route_array) {
							if (!array_key_exists($route, $allRoutes)) {
								return 'route ' . $route . ' does not exist in API';
							}
							foreach ($route_array['methods'] as $method) {
								if (!in_array($method, $allRoutes[$route]['methods'])) {
									return 'method ' . $method . ' does not exist for route ' . $route;
								}
							}
						}
					}
				}
			]
		]);
		if ($data) {
			if ($manager->userModify($data)) {
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->delete('/users/{name}', function ($request, $response, $args) use ($app, $manager) {
	if ($manager->setRequest($app, $request, $args)) {
		$data = $manager->validator($args, [
			'name' => 'required|alpha_dash|min:3'
		]);
		if ($data) {
			if ($manager->userRemove($data['name'])) {
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());	
});

$app->put('/users', function ($request, $response) use ($app) {
	if ($manager->setRequest($app, $request)) {
		$allRoutes = $manager->getAllRoutes();
		$data = $manager->validator([
			'name' => 'required|alpha_dash|min:3',
			'allowed_ips' => 'required|array|min:1',
			'allowed_ips.*' => 'ipsubnet',
			'expire_timestamp' => 'required|integer',
			'superuser' => 'boolean', 
			'access' => [
				'array',
				function ($values) use ($allRoutes) {
					if (isset($values)) {
						foreach ($values as $route => $route_array) {
							if (!array_key_exists($route, $allRoutes)) {
								return 'route ' . $route . ' does not exist in API';
							}
							foreach ($route_array['methods'] as $method) {
								if (!in_array($method, $allRoutes[$route]['methods'])) {
									return 'method ' . $method . ' does not exist for route ' . $route;
								}
							}
						}
					}
				}
			]
		]);
		if ($data) {
			if ($token = $manager->userCreate($data)) {
				return $response->with(['success' => true, 'token' => $token], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());	
});

$app->get('/routes', function($request, $response) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		return $response->with($manager->getAllRoutes($app), 200);
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->put('/import', function($request, $response) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict',
			'zone' => 'required|min:1'
		]);
		if ($data) {
			if (!$manager->zoneExists($data['domain']) || $manager->zoneUserAccess($data['domain'])) {
				if ($manager->zoneImport($data['domain'], $data['zone'])) {
					return $response->with(['success' => true], 200);
				}
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());
});

$app->put('/cluster/import', function($request, $response) use ($app, $manager) {
	if ($manager->setRequest($app, $request)) {
		$data = $manager->validator([
			'domain' => 'required|domainstrict',
			'zone' => 'required|min:1'
		]);
		if ($data) {
			if ($manager->zoneImport($data['domain'], $data['zone'])) {
				return $response->with(['success' => true], 200);
			}
		}
	}
	return $response->with(['errors' => $manager->getErrors()], $manager->getStatusCode());		
});

$app->run();
