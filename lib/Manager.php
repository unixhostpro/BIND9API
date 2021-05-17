<?php
namespace UnixHost\DNS;

class Manager {

	private $config;
	private $redis;
	private $remoteAddr;
	private $app;
	private $request;
	private $args;
	private $user;
	private $token;
	private $chuser;
	private $input;
	private $scanned;
	public $errors;
	public $statusCode;
	
    public function __construct() {  
    	$this->config = [];
    	$this->redis = false;     
        $this->getConfig();
        $this->redisInit();
        $this->getAllDomains();
        $this->clean();
    }
    private function clean() {
    	$this->remoteAddr = false;
    	$this->app = false;
    	$this->request = false;
    	$this->args = [];
        $this->user = false;
        $this->token = false;
        $this->chuser = false;
        $this->input = [];
        $this->scanned = false; 
        $this->errors = [];
        $this->statusCode = 400;    
    }
    public function setRequest($app, $request, $args = []) {
    	$this->clean();
    	$this->app = $app;
    	$this->request = $request;
    	$this->args = $args;
    	$this->setInput();
		return ($this->access()) ? true : false;
    } 
    private function getConfig() {
		require __DIR__ . '/../config/api_config.php';  
		$this->config = $main;  	
    }
    public function listenAddress() {
    	return $this->config['listen_address'];
    }
    public function listenPort() {
    	return $this->config['listen_port'];
    }    
    private function redisInit() {
    	$redis = new \Redis();
    	if ($redis->connect($this->config['redis_host'], $this->config['redis_port'])) {
			if ($this->config['redis_password']) {
				if ($this->config['redis_user']) {
					if (!$redis->auth([$this->config['redis_user'], $this->config['redis_password']])) {
						echo "Failed to Redis User+Password Authentication\n";
						exit;
					}				
				}		
				if (!$redis->auth($this->config['redis_password'])) {
					echo "Failed to Redis Password Authentication\n";
					exit;
				}	
			}  
    	} else {
			echo "Failed to connect to Redis Server\n";
			exit;    	
    	}
    	$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
		$this->redis = $redis;	
    }
    public function zoneOpen(string $domain) {
    	return file_get_contents($this->zoneFilePath($domain));
    }
    public function zoneParse(string $domain) {
    	$parse = \Badcow\DNS\Parser\Parser::parse($domain . '.', $this->zoneOpen($domain));
    	if (is_object($parse)) {
    		return $parse;
    	}
    	$this->setError('Failed to parse zone', 420);
    	return false;
    }
    public function zoneArray(string $domain, $soa = true) {
		if ($zone = $this->zoneParse($domain)) {
			$records = [];
			foreach ($zone as $key => $record) {
				if (!$soa && $record->getType() == 'SOA') {
					continue;
				}
				$recordArray = [];
				$recordArray['id'] = $key;
				$recordArray['name'] = $record->getName();
				$recordArray['type'] = $record->getType();
				$recordArray['ttl'] = $record->getTtl();
				$recordArray['value'] = $record->getRdata()->toText();
				$records[] = $recordArray;
			}
			return $records;
		}
		return false;		
    }
    public function zoneRecordAdd(array $data) {
		if ($zone = $this->zoneParse($data['domain'])) {
			$new = new \Badcow\DNS\ResourceRecord;
			$name = (filter_var($data['name'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) ? $this->idn($data['name']) : $data['name'];
			$new->setName($name);
			$new->setClass(\Badcow\DNS\Classes::INTERNET);
			$value = (filter_var($data['value'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) ? $this->idn($data['value']) : $data['value'];
			if ($data['type'] == 'TXT' || $data['type'] == 'SPF') {
				$value = $this->txtQuotes($value);			
			}
			if (isset($data['ttl'])) {
				$new->setTtl($data['ttl']);
			} else {
				$new->setTtl($this->config['default_ttl']);
			}
			$factory = new \Badcow\DNS\Rdata\Factory;
			try {
				$new->setRdata($factory->textToRdataType($data['type'], $value));
			} catch (\Exception $e) {
				$this->setError('Wrong record value', 420);
				return false;
			}
			$zone->addResourceRecord($new);
			if ($this->zoneSave($data['domain'], $zone)) {
				return true;
			}
		}
		return false;	
    } 
    public function zoneRecordModify(array $data) {
		if ($zone = $this->zoneParse($data['domain'])) {
			$value = (filter_var($data['value'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) ? $this->idn($data['value']) : $data['value'];	
			foreach ($zone as $key => $record) {
				if ($key == $data['id']) {
					if ($record->getType() == 'TXT' || $record->getType() == 'SPF') {
						$value = $this->txtQuotes($value);			
					}   			
					if (isset($data['ttl'])) {
						$record->setTtl($data['ttl']);
					}
					if (isset($data['value'])) {
						$rdata = $record->getRdata();
						try {
							$rdata->fromText($value);
						} catch (\Exception $e) {
							$this->setError('Wrong record value', 420);
							return false;
						}	
					}
					if ($this->zoneSave($data['domain'], $zone)) {
						return true;
					}
					break;
				}
			}
		}
		return false; 		
    }    
    public function zoneRecordRemove(string $domain, int $id) {
    	if ($zone = $this->zoneParse($domain)) {
			unset($zone[$id]);
	 		if ($this->zoneSave($domain, $zone)) {
				return true;
			}
		}
		return false;   	
    }
    private function zoneSerialUpdate($zone) {
    	foreach($zone as $record) {
    		if ($record->getType() == 'SOA') {
    			$rdata = $record->getRdata();
    			$rdata->setSerial(time());		     			    			    			
    		}
    	}
    	return $zone;    	
    }
    public function zoneCreate(array $params) {
    	$zone = \Badcow\DNS\Parser\Parser::parse('example.com.', file_get_contents($this->config['zone_template_file']));
    	$zone->setName($params['domain'] . '.');
    	if (isset($options['ttl'])) {
    		$zone->setDefaultTtl($options['ttl']);
    	}
    	foreach($zone as $record) {
    		if ($record->getType() == 'SOA') {
    			$rdata = $record->getRdata();
    			if (isset($params['nameservers']['0'])) {
    				$rdata->setMname($this->withDot($params['nameservers']['0']));
    			} else {
    				$rdata->setMname($this->config['default_nameservers']['0'] . '.');
    			}
    			if (isset($params['options']['contact'])) {
    				$rdata->setRname($params['options']['contact'] . '.');
    			} else {
    				$rdata->setRname('hostmaster.' . $params['domain'] . '.');
    			}
    			if (isset($options['serial'])) {
    				$rdata->setSerial($options['serial']);
    			} else {
    				$rdata->setSerial(time());
    			}
    			if (isset($options['refresh'])) {
    				$rdata->setRefresh($options['refresh']);
    			}
    			if (isset($options['retry'])) {
    				$rdata->setRetry($options['retry']);
    			}  
    			if (isset($options['expiry'])) {
    				$rdata->setExpire($options['expiry']);
    			}  
    			if (isset($options['minimum'])) {
    				$rdata->setMinimum($options['minimum']);
    			}     			     			    			    			
    		}
    	}
    	if (isset($params['nameservers'])) {  	
			$nameservers = $params['nameservers'];
    	} else {
    		$nameservers = $this->config['default_nameservers'];
    	}
		foreach ($nameservers as $nameserver) {
			$ns = new \Badcow\DNS\ResourceRecord;
			$ns->setName('@');
			$ns->setClass(\Badcow\DNS\Classes::INTERNET);
			$ns->setRdata(\Badcow\DNS\Rdata\Factory::Ns($this->withDot($nameserver)));
			$zone->addResourceRecord($ns);
			unset($ns);    	
		}    	
    	if (isset($params['ip'])) {
    		$default_a = ['@', 'mail', 'www'];
    		foreach ($default_a as $aName) {
				$a = new \Badcow\DNS\ResourceRecord;
				$a->setName($aName);
				$a->setClass(\Badcow\DNS\Classes::INTERNET);
				$a->setRdata(\Badcow\DNS\Rdata\Factory::A($params['ip']));
				$zone->addResourceRecord($a); 
				unset($a);    		
    		}
    	}
    	if (isset($params['ip6'])) {
    		$default_aaaa = ['@', 'mail', 'www'];
    		foreach ($default_aaaa as $aaaaName) {
				$aaaa = new \Badcow\DNS\ResourceRecord;
				$aaaa->setName($aaaaName);
				$aaaa->setClass(\Badcow\DNS\Classes::INTERNET);
				$aaaa->setRdata(\Badcow\DNS\Rdata\Factory::Aaaa($params['ip6']));
				$zone->addResourceRecord($aaaa);
				unset($aaaa);      		
    		}
    	}
    	if (isset($params['ip']) || isset($params['ip6'])) {
			$mx = new \Badcow\DNS\ResourceRecord;
			$mx->setName('@');
			$mx->setClass(\Badcow\DNS\Classes::INTERNET);
			$mx->setRdata(\Badcow\DNS\Rdata\Factory::Mx(10, 'mail.' . $params['domain'] . '.'));
			$zone->addResourceRecord($mx);  
		}
		if ($this->zoneSave($params['domain'], $zone)) {
			return true;
		}
		return false; 	 		
    }
    private function zoneSave(string $domain, object $zoneObject) {
    	$zoneObject = $this->zoneSerialUpdate($zoneObject);
    	$builder = new \Badcow\DNS\AlignedBuilder();
		if ($this->zoneSaveIfValid($domain, $builder->build($zoneObject))) {
			if ($this->rebuildZonesConf()) {
				return true;
			}
		}
		$this->setError('Failed to save zone', 420);
		return false;    
    }
    public function zoneRemove(string $domain) {
    	if (!$file = $this->zoneFilePath($domain)) {
    		$this->setError('DNS zone not found', 420);
    		return false;
    	}
    	if (rename($file, $file . '.removed')) {
    		if ($this->rebuildZonesConf()) {
    			@unlink($file . '.removed');
    			return true;
    		} else {
    			rename($file . '.removed', $file);
    		}
    	}
    	$this->setError('Failed to remove zone', 420);
    	return false;
    }
    public function zoneImport(string $domain, string $zoneString) {
		if ($this->zoneSaveIfValid($domain, $zoneString)) {
			if ($this->rebuildZonesConf()) {
				return true;
			}		
		}
		$this->setError('Failed to save zone', 420);
		return false;
    }
    private function zoneSaveIfValid(string $domain, string $zoneString) {
		if (!$zoneDirectory = $this->zoneDirectory($domain)) {
			$zoneDirectory = $this->config['named_directory'] . '/' . $this->user;
		}
		if (!is_dir($zoneDirectory)) {
			if (!mkdir($zoneDirectory)) {
				$this->setError('Internal Error', 420);
			}
		}
    	$zoneFile = $zoneDirectory . '/' . $domain . '.db';
		$newZoneFile = $zoneFile . '.temp';
		if (file_put_contents($newZoneFile, $zoneString . "\n")) {
			$exec = shell_exec($this->config['named_checkzone'] . ' ' . $domain . ' ' . $newZoneFile);
			$split = explode("\n", trim($exec));
			if (end($split) == 'OK') {
				if (rename($newZoneFile, $zoneFile)) {
					if (count($this->config['cluster'])) {
						$this->clusterTaskAdd($domain);
					}				
					return true;
				}
			}
			$this->setError('Zone contains errors', 400);
		} else {
			$this->setError('Failed to open zone file', 420);
		}
		return false;
    }
    public function zoneExists(string $domain) {
		return in_array($domain, $this->scanDomains());
    }
    public function zoneUserAccess(string $domain) {
		if ($this->iamSuperuser() || file_exists($this->config['named_directory'] . '/' . $this->user . '/' . $domain . '.db')) {
			return true;
		}
		$this->setError('Zone does not exists or no permission access');
		return false;
	}
    private function rebuildZonesConf() {
    	$conf = "\n";
    	foreach ($this->scanZones(true) as $username => $domains) {
    		foreach ($domains as $domain) {
    			$conf .= 'zone "' . $domain . '" {type master; file "' . $this->config['named_directory'] . '/' . $username . '/' . $domain . '.db";};' . "\n";
    		}
    	}
    	$tempConfFile = $this->config['named_include_file'] . '.temp';
    	if (file_put_contents($tempConfFile, $conf)) {
    		if ($this->isValidConfig($tempConfFile)) {
    			if (copy($this->config['named_include_file'], $this->config['named_include_file'] . '.backup')) {
					if (rename($tempConfFile, $this->config['named_include_file'])) {
						$this->setBindReloadRequest();
						return true;
					}
				}
			}
    	}
    	$this->setError('Internal error', 420);
    	return false;
    }
    public function getClusterConfig() {
    	return $this->config['cluster'];
    }
    public function getClusterCredentails($url) {
    	$key = array_search($url, array_column($this->config['cluster'], 'url'));
    	if ($key !== false) {
    		return $this->config['cluster'][$key];
    	}
    	return false;
    }    
    public function clusterTasks() {
    	if ($tasks = $this->redis->get('clusterTasks')) {
    		return $tasks;
    	}
    	return [];
    }
    public function clusterTaskRemove(string $domain, string $url) {
    	$remove = true;
		$tasks = $this->redis->get('clusterTasks');
		$tasks[$domain][$url] = true;
		foreach ($this->getClusterConfig() as $remoteServer) {
			if (!isset($tasks[$domain][$remoteServer['url']])) {
				$remove = true;
				break;
			}
		}
		if ($remove) {
			unset($tasks[$domain]);
		}	
		$this->redis->set('clusterTasks', $tasks);
    }    
    public function clusterTaskAdd(string $domain) {
		$tasks = $this->redis->get('clusterTasks');
		if (!is_array($tasks)) {
			$tasks = [];
		}
		$tasks[$domain] = [];
		$this->redis->set('clusterTasks', $tasks);
    }
    private function isValidConfig(string $file) {
    	$exec = shell_exec($this->config['named_checkconf'] . ' ' . $file);
    	if (strlen($exec) === 0) {
    		return true;
    	}
    	$this->setError('Internal Error', 420);
    	return false;
    }
    private function setBindReloadRequest() {
		$this->redis->set('bindReload', time());
    }
    public function getBindReloadRequest() {
		return $this->redis->get('bindReload');
    }   
    public function bindReload() {
    	shell_exec($this->config['named_reload_cmd']);
    	$this->redis->del('bindReload');
    }
    public function zoneFilePath(string $domain) {
    	$scan = $this->scanZones();
		foreach ($scan as $username => $domains) {
			if (in_array($domain, $domains)) {
				return $this->config['named_directory'] . '/' . $username . '/' . $domain . '.db';
			}
		}
		return false;
    }    
    public function zoneDirectory(string $domain) {
		$scan = $this->scanZones();
		foreach ($scan as $username => $domains) {
			if (in_array($domain, $domains)) {
				return $this->config['named_directory'] . '/' . $username;
			}
		}
		return false;
    }    
	private function scanZones(bool $force = false) {
		if ($this->scanned && $force === false) {
			return $this->scanned;
		}
		$result = [];
		foreach(scandir($this->config['named_directory']) as $username) {
			$fullPath = $this->config['named_directory'] . '/' . $username;
			if (is_dir($fullPath)) {
				foreach (scandir($fullPath) as $item) {
					if (substr($item, -3) == '.db') {
						$result[$username][] = str_replace('.db', '', $item);
					}
				}
			}
		}
		asort($result);
		$this->scanned = $result;
		return $result;
	}
	public function scanOwnDomains() {
		return $this->scanDomains($this->user);
	}   
	public function scanDomains(string $username = '') {
		$scan = $this->scanZones();
		$result = [];
		if ($username) {
			if (isset($scan[$username])) {
				$result = $scan[$username];
			}
		} else {
			foreach ($scan as $username => $domains) {
				$result = array_merge($result, $domains);
			}
		}
		asort($result);
		return $result;
	} 	 
    public function idn(string $domain) {
		$idn = idn_to_ascii($domain);
		if (filter_var($idn, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))	{
			return $idn;
		}
		return $domain;      	
    }
    public function iamSuperuser() {
		$me = $this->getSelfInfo();
		if (isset($me['superuser']) && $me['superuser'] === true) {
			return true;	
		}
		return false;
    }
    public function defaultUserPermissions() {
		return $this->main['default_permissions'];
    }
    private function changeUser() {
    	$key = 'api_user_' . $this->chuser;
    	if (!$this->redis->get($key)) {
    		if (!$this->userCreate(['name' => $this->chuser, 'allowed_ips' => ['127.0.0.1'], 'access' => $this->defaultUserPermissions(), 'expire_timestamp' => 0])) {
    			return false;
    		}
    	}
    	$this->user = $this->chuser;
    	return true;
    }
	public function access() {
		$this->parseHeaders();
		if ($this->user && $data = $this->redis->get('api_user_' . $this->user)) {
			if (!password_verify($this->token, $data['token'])) return false;
			if ($this->isAllowedIP($this->remoteAddr, $data['allowed_ips'])) {
				if (isset($data['superuser']) && $data['superuser'] === true) {
					if ($this->chuser) {
						if (!$this->changeUser()) {
							$this->setError('Failed to change user');
							return false;
						}
					}
					return true;
				}
				if (isset($data['access'])) {			
					$allRoutes = $this->getAllRoutes();
					if (in_array($this->request->getMethod(), $allRoutes[$this->request->getAttribute('__route__')->getPattern()]['methods'])) {
						return true;
					}							
				}
			}		
		}	
		$this->setError('Access denied');	
		return false;
	}
	private function parseHeaders() {
		$headers = $this->request->getHeaders();
		$this->remoteAddr = (isset($headers['remote_addr']['0'])) ? $headers['remote_addr']['0'] : false;
		$this->user = (isset($headers['dns-api-user']['0'])) ? $headers['dns-api-user']['0'] : false;
		$this->token = (isset($headers['dns-api-token']['0'])) ? $headers['dns-api-token']['0'] : false;
		$this->chuser = (isset($headers['dns-api-chuser']['0'])) ? $headers['dns-api-chuser']['0'] : false;		
	}
	public function getUser(string $name) {
		$key = 'api_user_' . $name;
		if ($data = $this->redis->keys($key)) {
			$ttl = $this->redis->ttl($key);
			$data['expire_timestamp'] = ($this->redis->ttl($key) > -1) ? time()+$ttl : false;
			return $data;
		}
		$this->setError('User not found');
		return false;
	}
	public function getUsers() {
		$result = [];
		$items = $this->redis->keys('api_user_*');
		foreach ($items as $key => $item) {
			$user = str_replace('api_user_', '', $item);
			$data = $this->redis->get($item);
			unset($data['token']);
			$result[$key] = $data;
			$result[$key]['name'] = $user;
			$ttl = $this->redis->ttl($user);
			$result[$key]['expire_timestamp'] = ($this->redis->ttl($user) > -1) ? time()+$ttl : false;
		}
		return $result;		
	}
	public function userRemove(string $name) {
		if ($this->redis->del('api_user_' . $name)) {
			return true;	
		}
		$this->setError('Failed to remove user. Maybe user does not exists');
		return false;	
	}
	public function userModify(array $data) {
		$key = 'api_user_' . $data['name'];
		if (!$user = $this->redis->get($key)) {
			$this->setError('User does not exists');
			return false;
		}
		if (isset($data['token'])) {
			$user['token'] = password_hash($data['token'], PASSWORD_BCRYPT);
		}
		if (isset($data['allowed_ips'])) {
			$user['allowed_ips'] = $data['allowed_ips'];
		}
		if (isset($data['access'])) {
			$user['access'] = $data['access'];
		}			
		if (isset($data['superuser']) && $this->iamSuperuser()) {
			$user['superuser'] = $data['superuser'];
			if ($user['superuser'] === true) {
				$user['access'] = [];
			}
		}						
		if (isset($data['expire_timestamp'])) {
			$ttl = $data['expire_timestamp'] - time();
		} else {
			$ttl = $this->redis->ttl($key);	
		}					
		$result = false;
		$result = ($ttl > 0) ? $this->redis->setEx($key, $ttl, $user) : $this->redis->set($key, $user);
		if ($result) {
			return true;
		}
		$this->setError('Failed save user data');
		return false;		
	}
	public function userCreate(array $post) {
		$result = false;
		$token = $this->generateRandomString(30);
		$token_hash = password_hash($token, PASSWORD_BCRYPT);
		$key = 'api_user_' . $post['name'];
		if ($this->redis->get($key)) {
			$this->setError('User already exists');
			return false;
		}
		$data = ['token' => $token_hash, 'allowed_ips' => $post['allowed_ips']];
		if (isset($post['access'])) {
			$data['access'] = $post['access'];
		}
		if (isset($post['superuser']) && $post['superuser'] === true) {
			$data['access'] = [];
			$data['superuser'] = true;
		}			
		$result = ($post['expire_timestamp'] === 0) ? $this->redis->set($key, $data) : $this->redis->setEx($key, ($post['expire_timestamp']-time()), $data);
		if ($result) {
			return $token;
		}
		$this->setError('Failed add user');
		return false;		
	}
	public function getStatusCode() {
		return $this->statusCode;
	}	
	public function getErrors() {
		return $this->errors;
	}
	public function setError(string $text, int $code = 420) {
		$this->errors[] = $text;
		$this->statusCode = $code;
	}
	public function setErrors(array $errors) {
		$this->errors = $errors;
	}
	public function getSelfInfo() {
		return $this->redis->get('api_user_' . $this->user);
	}
	public function getAllRoutes() {
		$result = [];
		foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
			foreach ($route->getMethods() as $method) {
				$result[$route->getPattern()]['methods'][] = $method;
			}
		}
		return $result;
	}
	private function withDot(string $value) {
		if (substr($value, -1) != '.') {
			return $value . '.';
		}
		return $value;
	}
	private function withoutDot(string $value) {
		if (substr($value, -1) == '.') {
			return substr($value, 0, -1);
		}
		return $value;
	}	
	public function generateRandomString(int $length = 30) {
        $length = ($length < 4) ? 4 : $length;
        return bin2hex(random_bytes(($length-($length%2))/2));
	}
	public function validator(array $rules) {
		if (array_key_exists('domain', $this->input)) {
			$this->input['domain'] = $this->idn($this->input['domain']);
		}
		$validator = new \Comet\Validator;
		$validator->addValidator('domainstrict', new \UnixHost\DNS\Validation\Rules\DomainValidRule());
		$validator->addValidator('domain', new \UnixHost\DNS\Validation\Rules\DomainRule());
		$validator->addValidator('ipsubnet', new \UnixHost\DNS\Validation\Rules\IPSubnetRule());
		$validator->addValidator('recordname', new \UnixHost\DNS\Validation\Rules\RecordNameRule());
		$validator->addValidator('recordtype', new \UnixHost\DNS\Validation\Rules\RecordTypeRule());
		$validation = $validator->validate($this->input, $rules);
		if (count($validation->getErrors())) {
			$this->setErrors($validation->getErrors());
			return false;
		}
		return $this->unsetIfEmpty($this->input);			
	}
	private function unsetIfEmpty(array $data) {
		foreach ($data as $k => $v) {
			if (is_array($v)) {
				$data[$k] = $this->unsetIfEmpty($v);
			} else {
				if ($v == "") {
					unset($data[$k]);
				}
			}
		}
		return $data;
	}
	public function isAllowedIP(string $ip_check, array $ips_allowed) {
		foreach($ips_allowed as $ip_allow) {
			if (strpos($ip_allow, '/') === false) {
				if (inet_pton($ip_check) == inet_pton($ip_allow)) {
					return true;;
				}
			} else {
				list($subnet, $bits) = explode('/', $ip_allow);
				$subnet = unpack('H*', inet_pton($subnet));
				foreach($subnet as $i => $h) $subnet[$i] = base_convert($h, 16, 2);
				$subnet = substr(implode('', $subnet), 0, $bits);
				$ip = unpack('H*', inet_pton($ip_check));
				foreach($ip as $i => $h) $ip[$i] = base_convert($h, 16, 2);
				$ip = substr(implode('', $ip), 0, $bits);
				if ($subnet == $ip) {
					return true;
				}
			}
		}	
	}	
	public function isAllowedDomainName(string $domainname) {
		if ($this->main['domain_validate'] === false) {
			return true;
		}
		if (in_array($domainname, $this->redis->get('allDomains'))) {
			return false;
		}
		$tmp = explode('.', $domainname);
		foreach ($tmp as $key => $value) {
			unset($tmp[$key]);
			$domain = implode('.', $tmp);
			if (in_array($domain, $this->redis->get('allDomains'))) {
				break;
			} else {
				$domain = false;
			}
		}
		if ($domain) {
			$nameTmp = explode('.' . $domain, $domainname);
			$nameParts = array_reverse(explode('.', $nameTmp['0']));
			$nparts = [];
			foreach ($nameParts as $npart) {
				$nparts[] = $npart;
				$try = implode('.', array_reverse($nparts)) . '.' . $domain;
				if ($this->zoneExists($try) && !$this->zoneUserAccess($try)) {
					return false;
				}
			}
			return true;
		}
		return false;
	}
	private function getAllDomains() {
		if ($this->redis->exists('allDomains') && ($this->redis->get('allDomainsLastUpdate') + $this->config['all_domains_update_interval']) > time()) {
			return;
		}
		if ($data = file_get_contents($this->config['all_domains_list'])) {
			$domains = [];
			foreach (explode("\n", $data) as $value) {
				$value = trim($value);
				if (strlen($value) === 0) continue;
				$domains[] = $this->idn($value);
			}
			$this->redis->set('allDomains', $domains);
			$this->redis->set('allDomainsLastUpdate', time());	
		}	
	}
    private function txtQuotes(string $value) {
		if ($value[0] != '"') {
			$value = '"' . $value;
		}
		if ($value[strlen($value)-1] != '"') {
			$value .= '"';
		}
		return $value;    	
    }
	public function setInput() {
		$post_string = (string) $this->request->getBody();
		parse_str($post_string, $post);	
		$this->input = array_merge($post, $this->args, $this->request->getQueryParams());
	}	
}
